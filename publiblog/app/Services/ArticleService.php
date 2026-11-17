<?php

namespace App\Services;

use App\Exceptions\PublishException;
use App\Exceptions\VersionConflictException;
use App\Models\Article;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Media;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea y guarda artículos en el CMS. Orden de guardado (plan): JSON, HTML y, por último,
 * la fila de `articles`. Nada de esto toca el servidor del cliente.
 */
class ArticleService
{
    public function __construct(
        private SlugService $slugs,
        private ArticleIdService $ids,
        private HtmlSanitizer $sanitizer,
        private MediaService $media,
        private ArticleRenderer $renderer,
        private ArticleStorage $storage,
    ) {
    }

    /** Un artículo nuevo recibe su ID público al abrirse, para poder nombrar sus imágenes. */
    public function createDraft(Blog $blog, ?User $user = null): Article
    {
        $article = Article::create([
            'blog_id' => $blog->id,
            'public_id' => $this->ids->next($blog),
            'status' => Article::STATUS_DRAFT,
            'version' => 1,
            'created_by' => $user?->id,
            'updated_by' => $user?->id,
        ]);

        $article->setRelation('blog', $blog);

        $json = $this->emptyContent($blog, $article, $user);
        $this->writeFiles($blog, $article, $json);

        ActivityLogger::record('article.created', article: $article, userId: $user?->id);

        return $article;
    }

    /**
     * @param  array{title?:?string,slug?:?string,excerpt?:?string,meta_title?:?string,meta_description?:?string,
     *     body_html?:?string,featured_media_id?:?int,featured_alt?:?string,author_name?:?string,
     *     published_at?:?string,version?:?int,category_ids?:?array,primary_category_id?:?int}  $input
     *     Sin `category_ids` se conservan las categorías que ya tenía; con la clave y vacía, el artículo se queda sin categoría.
     *
     * @throws VersionConflictException
     */
    public function save(Article $article, array $input, ?User $user = null, bool $force = false): Article
    {
        $blog = $article->blog;

        if (! $force && isset($input['version']) && (int) $input['version'] !== $article->version) {
            throw new VersionConflictException($article->version);
        }

        // Datos que el formulario no edita pero hay que conservar (p. ej. el origen de un artículo importado).
        $previous = $this->storage->readJson($blog->id, $article->public_id);
        $categories = $this->resolveCategories($blog, $input, $previous ?? []);

        $title = trim((string) ($input['title'] ?? ''));

        $slug = $article->slug;
        if (! $article->slugIsLocked()) {
            $wanted = $this->slugs->normalize($input['slug'] ?? '');
            $slug = $wanted !== '' ? $wanted : ($title !== '' ? $this->slugs->unique($blog, $title, $article) : null);
        }

        $body = $this->sanitizer->sanitize(
            $this->media->toPublic((string) ($input['body_html'] ?? ''), $blog),
            $blog
        );

        $published = $this->parsePublishedAt($blog, $input['published_at'] ?? null, $article);

        $json = [
            'schema_version' => config('publiblog.schema_version'),
            'public_id' => $article->public_id,
            'slug' => $slug,
            'title' => $title,
            'excerpt' => trim((string) ($input['excerpt'] ?? '')),
            'seo' => [
                'meta_title' => trim((string) ($input['meta_title'] ?? '')),
                'meta_description' => trim((string) ($input['meta_description'] ?? '')),
                'canonical' => $slug && $categories ? $blog->blogUrl().'/'.$categories[0]['slug'].'/'.$slug : '',
            ],
            'categories' => $categories,
            'published_at' => $published->setTimezone($blog->timezone)->toIso8601String(),
            'updated_at' => now($blog->timezone)->toIso8601String(),
            'author' => ['name' => trim((string) ($input['author_name'] ?? ''))],
            'featured_image' => $this->featuredImage($blog, $input),
            'body_html' => $body,
        ];

        if (! empty($previous['import'])) {
            $json['import'] = $previous['import'];
        }

        $article->slug = $slug;
        $article->title = $title !== '' ? $title : null;
        $article->published_at = $published;
        $article->updated_by = $user?->id;
        $article->version = $article->version + 1;

        $html = $this->writeFiles($blog, $article, $json);
        $article->content_hash = $this->renderer->hash($html);

        DB::transaction(function () use ($article) {
            $article->save();
        });

        $changes = ActivityLogger::diffArticle($previous ?? [], $json);

        if ($changes) {
            ActivityLogger::record('article.saved', article: $article, details: ['changes' => $changes, 'version' => $article->version], userId: $user?->id);
        }

        return $article;
    }

    /** Contenido del JSON tal como se guardó, listo para rellenar el formulario. */
    public function load(Article $article): array
    {
        $json = $this->storage->readJson($article->blog_id, $article->public_id) ?? [];
        $blog = $article->blog;

        $json['body_html'] = $this->media->toEditor($json['body_html'] ?? '', $blog);

        return $json;
    }

    /** Falla si el artículo no está completo para publicarse. */
    public function assertPublishable(Article $article): array
    {
        $json = $this->storage->readJson($article->blog_id, $article->public_id);
        $missing = [];

        if (! $json) {
            throw PublishException::make(PublishException::LOCAL_IO, 'No se encuentra el JSON del artículo.');
        }

        foreach (['title' => 'título', 'body_html' => 'contenido', 'published_at' => 'fecha'] as $key => $label) {
            if (trim((string) ($json[$key] ?? '')) === '') {
                $missing[] = $label;
            }
        }

        if (! $this->slugs->isValid((string) ($json['slug'] ?? '')) || $this->slugs->isReserved((string) $json['slug'])) {
            $missing[] = 'slug válido';
        }

        if (empty($json['categories'])) {
            $missing[] = 'categoría (elige al menos una; la principal forma la URL)';
        }

        if ($missing) {
            throw PublishException::make(PublishException::VALIDATION, 'Falta: '.implode(', ', $missing).'.');
        }

        // Una categoría borrada después de guardar el borrador: hay que elegir otra, no publicar a ciegas.
        $known = Category::where('blog_id', $article->blog_id)
            ->whereIn('slug', array_column($json['categories'], 'slug'))->pluck('slug')->all();

        foreach ($json['categories'] as $c) {
            if (! in_array($c['slug'], $known, true)) {
                throw PublishException::make(PublishException::VALIDATION, "La categoría «{$c['name']}» ya no existe. Elige otra y vuelve a publicar.");
            }
        }

        return $json;
    }

    /**
     * Refleja en la base de datos lo que está PUBLICADO: categorías (para el listado) y resumen.
     * Se llama cada vez que el servidor recibe el artículo; nunca con el borrador.
     */
    public function syncLive(Article $article, array $liveJson): void
    {
        $blog = $article->blog;
        $slugs = array_values(array_filter(array_column($liveJson['categories'] ?? [], 'slug')));
        $ids = $slugs ? Category::where('blog_id', $blog->id)->whereIn('slug', $slugs)->pluck('id', 'slug') : collect();

        $sync = [];

        foreach ($slugs as $i => $slug) {
            if ($ids->has($slug)) {
                $sync[$ids[$slug]] = ['is_primary' => $i === 0];
            }
        }

        $article->liveCategories()->sync($sync);

        $article->timestamps = false;
        $article->live_summary = $this->renderer->summaryData($blog, $liveJson);
        $article->saveQuietly();
    }

    /** El artículo deja de estar publicado: ya no cuenta para ningún listado. */
    public function clearLive(Article $article): void
    {
        $article->liveCategories()->detach();
        $article->timestamps = false;
        $article->live_summary = null;
        $article->saveQuietly();
    }

    /**
     * Categorías del JSON, la principal primero: [{slug, name}, …]. Puede estar vacía (un borrador sin elegir
     * categoría): no se asigna ninguna por omisión, porque la principal forma la URL y la decide quien edita.
     * Para publicar hace falta al menos una (assertPublishable).
     *
     * @return list<array{slug:string,name:string}>
     */
    private function resolveCategories(Blog $blog, array $input, array $previous): array
    {
        if (array_key_exists('category_ids', $input)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) $input['category_ids']))));
            $primary = (int) ($input['primary_category_id'] ?? 0);

            if ($primary && ! in_array($primary, $ids, true)) {
                array_unshift($ids, $primary);
            }

            $models = Category::where('blog_id', $blog->id)->whereIn('id', $ids)->get()->keyBy('id');
            $order = $primary && $models->has($primary) ? array_merge([$primary], array_diff($ids, [$primary])) : $ids;

            $list = [];

            foreach ($order as $id) {
                if ($models->has($id)) {
                    $list[] = ['slug' => $models[$id]->slug, 'name' => $models[$id]->name];
                }
            }
        } else {
            $list = array_values(array_filter(
                array_map(fn ($c) => ['slug' => (string) ($c['slug'] ?? ''), 'name' => (string) ($c['name'] ?? $c['slug'] ?? '')], $previous['categories'] ?? []),
                fn ($c) => $c['slug'] !== ''
            ));
        }

        return $list;
    }

    /** Refleja en el JSON el estado interno del CMS (para poder reconstruir el índice). */
    public function syncMeta(Article $article): void
    {
        $json = $this->storage->readJson($article->blog_id, $article->public_id);

        if (! $json) {
            return;
        }

        $json['cms'] = $this->cmsMeta($article);
        $this->storage->writeJson($article->blog_id, $article->public_id, $json);
    }

    /** @return array<string,mixed> */
    public function cmsMeta(Article $article): array
    {
        return [
            'status' => $article->status,
            'version' => $article->version,
            'first_published_at' => $article->first_published_at?->toIso8601String(),
            'remote_path' => $article->remote_path,
            'redirects' => $article->redirects ?? [],
            'published_hash' => $article->published_hash,
            'deleted_at' => $article->deleted_at?->toIso8601String(),
            'source_ref' => $article->source_ref,
        ];
    }

    private function emptyContent(Blog $blog, Article $article, ?User $user): array
    {
        return [
            'schema_version' => config('publiblog.schema_version'),
            'public_id' => $article->public_id,
            'slug' => null,
            'title' => '',
            'excerpt' => '',
            'seo' => ['meta_title' => '', 'meta_description' => '', 'canonical' => ''],
            'categories' => [],
            'published_at' => now($blog->timezone)->toIso8601String(),
            'updated_at' => now($blog->timezone)->toIso8601String(),
            'author' => ['name' => $user?->name ?? ''],
            'featured_image' => null,
            'body_html' => '',
        ];
    }

    /** JSON, luego HTML. Devuelve el HTML generado. */
    private function writeFiles(Blog $blog, Article $article, array $json): string
    {
        $json['cms'] = $this->cmsMeta($article);

        $this->storage->writeJson($blog->id, $article->public_id, $json);

        $html = $this->renderer->render($blog, $json);
        $this->storage->writeHtml($blog->id, $article->public_id, $html);

        return $html;
    }

    private function featuredImage(Blog $blog, array $input): ?array
    {
        $id = $input['featured_media_id'] ?? null;

        if (! $id) {
            return null;
        }

        $media = Media::where('blog_id', $blog->id)->find((int) $id);

        if (! $media) {
            return null;
        }

        return [
            'file' => $media->remote_path,
            'alt' => trim((string) ($input['featured_alt'] ?? '')),
            'width' => $media->width,
            'height' => $media->height,
        ];
    }

    /** La fecha del formulario va en la zona horaria del blog; se guarda en UTC. */
    private function parsePublishedAt(Blog $blog, ?string $value, Article $article): CarbonImmutable
    {
        if ($value) {
            return CarbonImmutable::parse($value, $blog->timezone)->utc();
        }

        return $article->published_at
            ? CarbonImmutable::instance($article->published_at)
            : CarbonImmutable::now('UTC');
    }
}
