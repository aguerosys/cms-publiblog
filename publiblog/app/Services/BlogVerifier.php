<?php

namespace App\Services;

use App\Exceptions\PublishException;
use App\Models\Article;
use App\Models\Blog;
use App\Models\Deployment;
use App\Models\User;
use App\Services\Remote\RemoteErrorMapper;
use App\Services\Remote\RemoteFilesystemFactory;
use App\Services\Remote\RemoteWriter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;
use Throwable;

/**
 * Verificación del servidor del cliente (F2-6): lee por SFTP lo que hay publicado y lo compara con
 * lo que el CMS cree que subió. Detecta archivos borrados o editados a mano, restauraciones antiguas
 * del hosting, imágenes que faltan y reglas del .htaccess perdidas.
 *
 * Solo LEE: no necesita el bloqueo del blog ni cambia nada en el servidor. El resultado queda en
 * `articles.remote_state` (ok | modified | missing | missing_media | no_rule).
 */
class BlogVerifier
{
    public const PROBLEMS = ['modified', 'missing', 'missing_media', 'no_rule'];

    private const CONNECTION_CODES = [
        PublishException::AUTH, PublishException::HOSTKEY, PublishException::BLOCKED,
        PublishException::CONNECT, PublishException::TIMEOUT,
    ];

    public function __construct(
        private RemoteFilesystemFactory $remote,
        private ArticleStorage $storage,
        private ArticleRenderer $renderer,
        private MediaService $media,
        private ArticlePublisher $publisher,
        private HtaccessManager $htaccess,
    ) {
    }

    public function isRunning(Blog $blog): bool
    {
        return Deployment::where('blog_id', $blog->id)->where('action', 'verify')
            ->whereIn('status', [Deployment::STATUS_QUEUED, Deployment::STATUS_RUNNING])->exists();
    }

    public function start(Blog $blog, ?User $user = null): Deployment
    {
        return Deployment::create([
            'blog_id' => $blog->id,
            'article_id' => null,
            'action' => 'verify',
            'status' => Deployment::STATUS_QUEUED,
            'total_items' => Article::where('blog_id', $blog->id)->whereNotNull('remote_path')->count(),
            'triggered_by' => $user?->id,
        ]);
    }

    /** @return int|null cursor del siguiente lote, o null si terminó o falló */
    public function runBatch(Deployment $deployment, int $afterId = 0): ?int
    {
        if (in_array($deployment->status, [Deployment::STATUS_OK, Deployment::STATUS_ERROR], true)) {
            return null;
        }

        @set_time_limit((int) config('publiblog.publish.time_limit'));

        $blog = Blog::findOrFail($deployment->blog_id);

        if ($deployment->status === Deployment::STATUS_QUEUED) {
            $deployment->forceFill(['status' => Deployment::STATUS_RUNNING, 'started_at' => now()])->save();
        }

        try {
            $this->publisher->guardBlog($blog);
            $fs = $this->remote->make($blog->fresh());

            $batch = max(1, (int) config('publiblog.regenerate_batch'));
            $block = $this->htaccess->parseBlock(RemoteWriter::readOrNull($fs, HtaccessManager::FILE));

            $articles = Article::where('blog_id', $blog->id)
                ->whereNotNull('remote_path')
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit($batch)
                ->get();

            $cursor = $afterId;

            foreach ($articles as $article) {
                $cursor = $article->id;
                $state = $this->check($fs, $blog, $article, $block);

                $article->timestamps = false;
                $article->forceFill(['remote_state' => $state, 'remote_checked_at' => now()])->saveQuietly();

                $deployment->done_items++;
                $deployment->failed_items += $state === 'ok' ? 0 : 1;
            }

            if ($articles->count() === $batch) {
                $deployment->save();

                return $cursor;
            }

            $this->finalize($fs, $blog, $deployment);

            return null;
        } catch (Throwable $e) {
            $error = RemoteErrorMapper::map($e);

            if (in_array($error->errorCode, self::CONNECTION_CODES, true)) {
                $this->publisher->applyBlogStatus($blog, $error);
            }

            $deployment->forceFill([
                'status' => Deployment::STATUS_ERROR,
                'error_code' => $error->errorCode,
                'error_message' => $error->getMessage(),
                'finished_at' => now(),
            ])->save();

            return null;
        }
    }

    public function runAll(Deployment $deployment): Deployment
    {
        $cursor = 0;

        while ($cursor !== null) {
            $cursor = $this->runBatch($deployment->refresh(), $cursor);
        }

        return $deployment->refresh();
    }

    /** @param list<string>|null $block líneas del bloque de Publiblog en el .htaccess del servidor (null = no hay bloque) */
    private function check(FilesystemOperator $fs, Blog $blog, Article $article, ?array $block): string
    {
        $file = $article->remote_path;

        if (! $fs->fileExists($file)) {
            return 'missing';
        }

        if ($article->published_hash && $this->renderer->hash($fs->read($file)) !== $article->published_hash) {
            return 'modified';
        }

        if (! $this->rulesPresent($blog, $article, $block)) {
            return 'no_rule';
        }

        $json = $this->storage->readLiveJson($blog->id, $article->public_id);

        foreach ($json ? $this->media->usedMedia($blog, $json) : [] as $media) {
            if (! $fs->fileExists($media->remote_path)) {
                return 'missing_media';
            }
        }

        return 'ok';
    }

    /**
     * ¿Tiene el .htaccess del servidor las reglas fijas del bloque y las propias de este artículo
     * (la suya de antes de las categorías y sus redirecciones 301)?
     *
     * @param  list<string>|null  $block
     */
    private function rulesPresent(Blog $blog, Article $article, ?array $block): bool
    {
        if ($block === null) {
            return false;
        }

        $lines = array_flip($block);

        foreach ([...$this->htaccess->baseLines($blog), ...$this->htaccess->requiredLines($blog, $article)] as $required) {
            if (! isset($lines[$required])) {
                return false;
            }
        }

        return true;
    }

    /** Listado y sitemap contra el manifiesto, y registro del resultado. */
    private function finalize(FilesystemOperator $fs, Blog $blog, Deployment $deployment): void
    {
        $issues = [];
        $manifestPath = "{$blog->id}/build/manifest.json";
        $manifest = Storage::disk('blogs')->exists($manifestPath) ? (json_decode(Storage::disk('blogs')->get($manifestPath), true) ?: []) : [];

        // Las dos puertas de entrada y la primera página de cada categoría.
        $watched = array_merge(
            ['index.html', 'sitemap.xml', SitePartials::file('header'), SitePartials::file('footer')],
            array_values(array_filter(array_keys($manifest), fn ($p) => preg_match('#^[a-z0-9-]+/index\.html$#', $p) === 1))
        );

        foreach ($watched as $file) {
            if (! isset($manifest[$file])) {
                continue; // nunca se generó
            }

            if (! $fs->fileExists($file)) {
                $issues[] = "{$file}: falta";
            } elseif (hash('sha256', $fs->read($file)) !== $manifest[$file]) {
                $issues[] = "{$file}: distinto de lo que subió el CMS";
            }
        }

        $deployment->forceFill(['status' => Deployment::STATUS_OK, 'finished_at' => now()])->save();

        ActivityLogger::record('blog.verified', $blog, details: array_filter([
            'articles' => $deployment->done_items,
            'failed' => $deployment->failed_items,
            'listing' => $issues ?: null,
        ], fn ($v) => $v !== null), userId: $deployment->triggered_by);
    }
}
