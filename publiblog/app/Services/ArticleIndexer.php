<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Blog;
use App\Models\Media;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Reconstruye la tabla `articles` desde los JSON del CMS (blog:reindex). Por eso cada JSON
 * lleva public_id, slug, fechas y el bloque `cms` con el estado interno.
 */
class ArticleIndexer
{
    public function __construct(
        private ArticleStorage $storage,
        private ArticleIdService $ids,
        private ArticleRenderer $renderer,
    ) {
    }

    /** @return int número de artículos indexados */
    public function reindex(Blog $blog): int
    {
        $count = 0;
        $max = 0;

        foreach ($this->storage->allPublicIds($blog) as $publicId) {
            $json = $this->storage->readJson($blog->id, $publicId);

            if (! $json) {
                continue;
            }

            $cms = $json['cms'] ?? [];
            $html = $this->storage->readHtml($blog->id, $publicId);

            $article = Article::withTrashed()->firstOrNew(['blog_id' => $blog->id, 'public_id' => $publicId]);
            $article->forceFill([
                'slug' => $json['slug'] ?: null,
                'title' => ($json['title'] ?? '') !== '' ? $json['title'] : null,
                'status' => $cms['status'] ?? Article::STATUS_DRAFT,
                'version' => $cms['version'] ?? 1,
                'published_at' => isset($json['published_at']) ? CarbonImmutable::parse($json['published_at'])->utc() : null,
                'first_published_at' => isset($cms['first_published_at']) ? CarbonImmutable::parse($cms['first_published_at'])->utc() : null,
                'remote_path' => $cms['remote_path'] ?? null,
                'content_hash' => $html !== null ? $this->renderer->hash($html) : null,
                'published_hash' => $cms['published_hash'] ?? null,
            ]);
            $article->save();

            if (! empty($cms['deleted_at'])) {
                $article->forceFill(['deleted_at' => CarbonImmutable::parse($cms['deleted_at'])->utc()])->saveQuietly();
            }

            $max = max($max, (int) $publicId);
            $count++;
        }

        $this->ids->ensureAtLeast($blog, $max);
        $this->reindexMedia($blog);

        return $count;
    }

    /**
     * Reconstruye la tabla `media` desde las imágenes guardadas en el CMS. El nombre
     * ({public_id}-{n}-{hash8}.{ext}) identifica el artículo; las medidas y el hash se recalculan.
     * Quedan sin marcar como subidas: la próxima publicación comprueba el servidor y las sube si faltan.
     */
    public function reindexMedia(Blog $blog): int
    {
        $count = 0;

        foreach (Storage::disk('blogs')->files($blog->id.'/media', true) as $path) {
            if (! preg_match('#/media/(\d{4})/((\d{6})-\d+-[0-9a-f]{8}\.(jpg|png|webp))$#', $path, $m)) {
                continue;
            }

            if (Media::where('blog_id', $blog->id)->where('local_path', $path)->exists()) {
                continue;
            }

            $bytes = Storage::disk('blogs')->get($path);
            $info = @getimagesizefromstring($bytes);

            if (! $info) {
                continue;
            }

            Media::create([
                'blog_id' => $blog->id,
                'article_id' => Article::withTrashed()->where('blog_id', $blog->id)->where('public_id', $m[3])->value('id'),
                'local_path' => $path,
                'remote_path' => "img/{$m[1]}/{$m[2]}",
                'mime' => $info['mime'],
                'sha256' => hash('sha256', $bytes),
                'width' => $info[0],
                'height' => $info[1],
                'bytes' => strlen($bytes),
            ]);
            $count++;
        }

        return $count;
    }
}
