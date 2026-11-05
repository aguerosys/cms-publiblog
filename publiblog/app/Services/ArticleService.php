<?php

namespace App\Services;

use App\Exceptions\PublishException;
use App\Exceptions\VersionConflictException;
use App\Models\Article;
use App\Models\Blog;
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

        return $article;
    }

    /**
     * @param  array{title?:?string,slug?:?string,excerpt?:?string,meta_title?:?string,meta_description?:?string,
     *     body_html?:?string,featured_media_id?:?int,featured_alt?:?string,author_name?:?string,
     *     published_at?:?string,version?:?int}  $input
     *
     * @throws VersionConflictException
     */
    public function save(Article $article, array $input, ?User $user = null, bool $force = false): Article
    {
        $blog = $article->blog;

        if (! $force && isset($input['version']) && (int) $input['version'] !== $article->version) {
            throw new VersionConflictException($article->version);
        }

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
                'canonical' => $slug ? $blog->blogUrl().'/'.$slug : '',
            ],
            'published_at' => $published->setTimezone($blog->timezone)->toIso8601String(),
            'updated_at' => now($blog->timezone)->toIso8601String(),
            'author' => ['name' => trim((string) ($input['author_name'] ?? ''))],
            'featured_image' => $this->featuredImage($blog, $input),
            'body_html' => $body,
        ];

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

        if ($missing) {
            throw PublishException::make(PublishException::VALIDATION, 'Falta: '.implode(', ', $missing).'.');
        }

        return $json;
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
            'published_hash' => $article->published_hash,
            'deleted_at' => $article->deleted_at?->toIso8601String(),
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
