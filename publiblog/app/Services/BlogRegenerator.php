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
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;
use Throwable;

/**
 * Regeneración masiva (F2-4): vuelve a generar con la plantilla actual los artículos ya publicados
 * de un blog y sube solo lo que cambió.
 *
 *  - Se regenera lo PUBLICADO (copia *.published.json), nunca un borrador con cambios sin publicar.
 *  - "Solo lo afectado": un artículo cuyo HTML nuevo tiene el mismo hash que el publicado no se sube.
 *    Con $force (restaurar una web perdida) se sube todo.
 *  - Trabaja por lotes y guarda el progreso en un Deployment (acción regenerate) para poder
 *    repartirlo en varios trabajos de la cola y mostrarlo en el panel.
 *  - Un fallo de un artículo no detiene al resto; un fallo de conexión sí.
 */
class BlogRegenerator
{
    private const CONNECTION_CODES = [
        PublishException::AUTH, PublishException::HOSTKEY, PublishException::BLOCKED,
        PublishException::CONNECT, PublishException::TIMEOUT,
    ];

    public function __construct(
        private RemoteFilesystemFactory $remote,
        private ArticleStorage $storage,
        private ArticleRenderer $renderer,
        private MediaService $media,
        private HtaccessManager $htaccess,
        private ListingBuilder $listing,
        private ArticleService $articles,
        private ArticlePublisher $publisher,
    ) {
    }

    public function isRunning(Blog $blog): bool
    {
        return Deployment::where('blog_id', $blog->id)
            ->whereIn('action', ['regenerate', 'import'])
            ->whereIn('status', [Deployment::STATUS_QUEUED, Deployment::STATUS_RUNNING])
            ->exists();
    }

    /** Crea el registro de seguimiento; el trabajo en sí lo hace runBatch(). */
    /** @param bool $onlyProblems limitarse a los artículos que la última verificación marcó con diferencias */
    public function start(Blog $blog, ?User $user = null, bool $force = false, bool $onlyProblems = false): Deployment
    {
        $total = $this->regenerable(Article::where('blog_id', $blog->id), $onlyProblems)->count();

        return Deployment::create([
            'blog_id' => $blog->id,
            'article_id' => null,
            'action' => 'regenerate',
            'status' => Deployment::STATUS_QUEUED,
            'template_version' => $blog->template_version,
            'total_items' => $total,
            'force' => $force || $onlyProblems,
            'scope' => $onlyProblems ? 'problems' : null,
            'triggered_by' => $user?->id,
        ]);
    }

    /**
     * Reconstruye y sube SOLO los listados y el sitemap (no toca ningún artículo). Sirve cuando cambia algo que
     * únicamente ven los listados: el nombre o la descripción de una categoría. No lanza excepciones: devuelve
     * el resultado para mostrarlo; si no se pudo, el cambio llegará con la próxima publicación o regeneración.
     *
     * @return array{ok:bool, message:string, files:int}
     */
    public function refreshListings(Blog $blog): array
    {
        if (! $blog->canPublish()) {
            return ['ok' => false, 'files' => 0, 'message' => 'la conexión del blog no está en estado correcto'];
        }

        if ($this->isRunning($blog)) {
            return ['ok' => false, 'files' => 0, 'message' => 'hay una regeneración en curso'];
        }

        @set_time_limit((int) config('publiblog.publish.time_limit'));

        $lock = Cache::lock("blog:{$blog->id}", (int) config('publiblog.publish.lock_ttl'));

        try {
            $lock->block((int) config('publiblog.publish.lock_wait'));
        } catch (LockTimeoutException) {
            return ['ok' => false, 'files' => 0, 'message' => 'hay otra publicación en curso'];
        }

        try {
            $this->renderer->forgetCategoryNames();
            $this->publisher->guardBlog($blog);
            $fs = $this->remote->make($blog->fresh());
            $files = $this->listing->sync($fs, $blog);

            return ['ok' => true, 'files' => $files, 'message' => ''];
        } catch (Throwable $e) {
            $error = RemoteErrorMapper::map($e);
            $this->publisher->logFailure($blog, null, $error, $e);
            $this->publisher->applyBlogStatus($blog, $error);

            return ['ok' => false, 'files' => 0, 'message' => $error->getMessage()];
        } finally {
            $lock->release();
        }
    }

    /** Publicación en lote de los artículos importados (borradores con `import_publish`). */
    public function startImport(Blog $blog, ?User $user = null): Deployment
    {
        return Deployment::create([
            'blog_id' => $blog->id,
            'article_id' => null,
            'action' => 'import',
            'status' => Deployment::STATUS_QUEUED,
            'template_version' => $blog->template_version,
            'total_items' => $this->pendingImports($blog)->count(),
            'triggered_by' => $user?->id,
        ]);
    }

    private function regenerable($query, bool $onlyProblems)
    {
        return $query->whereNotNull('remote_path')
            ->when($onlyProblems, fn ($q) => $q->whereIn('remote_state', BlogVerifier::PROBLEMS));
    }

    private function pendingImports(Blog $blog)
    {
        return Article::where('blog_id', $blog->id)
            ->where('import_publish', true)
            ->whereNull('remote_path')
            ->where('status', Article::STATUS_DRAFT);
    }

    /**
     * Procesa un lote de artículos a partir de $afterId.
     *
     * @return int|null  id del último artículo tratado (siguiente cursor), o null si ya terminó o falló
     *
     * @throws PublishException LOCK_TIMEOUT si otra publicación tiene el blog bloqueado
     */
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

        // Los nombres de categoría se leen de la base de datos en cada lote: un renombrado durante una
        // regeneración larga llega a los artículos que quedan.
        $this->renderer->forgetCategoryNames();

        $batch = max(1, (int) config('publiblog.regenerate_batch'));
        $lock = Cache::lock("blog:{$blog->id}", (int) config('publiblog.publish.lock_ttl'));

        try {
            $lock->block((int) config('publiblog.publish.lock_wait'));
        } catch (LockTimeoutException $e) {
            throw PublishException::make(PublishException::LOCK_TIMEOUT, null, $e);
        }

        try {
            $this->publisher->guardBlog($blog);
            $fs = $this->remote->make($blog->fresh());

            // Con cabecera y pie compartidos, el .htaccess y los fragmentos tienen que estar antes de que
            // los artículos pasen a referenciarlos; si no, quedarían sin cabecera mientras dura la regeneración.
            if ($afterId === 0 && $blog->ssi_enabled) {
                $this->prepareSsi($fs, $blog, $deployment);
            }

            $import = $deployment->action === 'import';

            $articles = ($import
                ? $this->pendingImports($blog)->with('blog')
                : $this->regenerable(Article::with('blog')->where('blog_id', $blog->id), $deployment->scope === 'problems'))
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit($batch)
                ->get();

            $cursor = $afterId;
            $moved = false;

            foreach ($articles as $article) {
                $cursor = $article->id;
                $moved = $this->regenerateArticle($fs, $blog, $article, $deployment) || $moved;
            }

            // Hubo artículos que cambiaron de sitio: sus redirecciones se escriben ya, sin esperar al final.
            if ($moved) {
                $applied = $this->htaccess->apply($fs, $this->htaccess->buildBlock($blog));
                $deployment->files_count += $applied['changed'] ? 1 : 0;
            }

            if ($articles->count() === $batch) {
                $deployment->save();

                return $cursor;
            }

            $this->finalize($fs, $blog, $deployment);

            return null;
        } catch (Throwable $e) {
            $error = RemoteErrorMapper::map($e);
            $this->publisher->logFailure($blog, null, $error, $e);
            $this->publisher->applyBlogStatus($blog, $error);
            $this->fail($deployment, $error);

            return null;
        } finally {
            $lock->release();
        }
    }

    /** Ejecuta todos los lotes seguidos (comando de consola y pruebas). */
    public function runAll(Deployment $deployment, ?callable $progress = null): Deployment
    {
        $cursor = 0;

        while ($cursor !== null) {
            $cursor = $this->runBatch($deployment->refresh(), $cursor);
            $progress && $progress($deployment->refresh());
        }

        return $deployment->refresh();
    }

    public function fail(Deployment $deployment, PublishException $error): void
    {
        $deployment->forceFill([
            'status' => Deployment::STATUS_ERROR,
            'error_code' => $error->errorCode,
            'error_message' => $error->getMessage(),
            'finished_at' => now(),
        ])->save();
    }

    /** @return bool si el artículo cambió de ubicación en el servidor */
    private function regenerateArticle(FilesystemOperator $fs, Blog $blog, Article $article, Deployment $deployment): bool
    {
        $moved = false;

        try {
            $moved = $deployment->action === 'import'
                ? $this->publishImported($fs, $blog, $article, $deployment)
                : $this->republish($fs, $blog, $article, $deployment);
            $deployment->done_items++;
        } catch (Throwable $e) {
            $error = RemoteErrorMapper::map($e);

            // Un problema de conexión afecta a todos los artículos: se corta y se informa una vez.
            if (in_array($error->errorCode, self::CONNECTION_CODES, true)) {
                throw $error;
            }

            $deployment->failed_items++;
            $deployment->done_items++;

            $failure = Deployment::create([
                'blog_id' => $blog->id,
                'article_id' => $article->id,
                'action' => 'regenerate',
                'status' => Deployment::STATUS_ERROR,
                'error_code' => $error->errorCode,
                'error_message' => $error->getMessage(),
                'template_version' => $blog->template_version,
                'started_at' => now(),
                'finished_at' => now(),
                'triggered_by' => $deployment->triggered_by,
            ]);

            $article->timestamps = false;

            if ($deployment->action === 'import') {
                // Sigue siendo un borrador (no llegó a publicarse): se deja listo para revisarlo y publicarlo a mano.
                $article->forceFill(['import_publish' => false, 'last_deployment_id' => $failure->id])->save();
            } else {
                $article->forceFill(['status' => Article::STATUS_ERROR, 'last_deployment_id' => $failure->id])->save();
            }
        }

        $deployment->save();

        return $moved;
    }

    /** @return bool si el artículo cambió de ubicación en el servidor */
    private function republish(FilesystemOperator $fs, Blog $blog, Article $article, Deployment $deployment): bool
    {
        $json = $this->storage->readLiveJson($blog->id, $article->public_id);

        if (! $json) {
            throw PublishException::make(PublishException::LOCAL_IO, 'No se encuentra el JSON del artículo.');
        }

        $html = $this->renderer->render($blog, $json);
        $hash = $this->renderer->hash($html);

        // Un artículo anterior a las categorías conserva su sitio hasta que se republique con una categoría.
        $target = ArticleLocator::fileFromJson($json) ?? $article->remote_path;
        $relocated = $target !== $article->remote_path;

        if (! $deployment->force && ! $relocated && $article->published_hash === $hash && $article->status !== Article::STATUS_ERROR) {
            // El diseño nuevo no cambia este artículo: nada que subir. Se completa su resumen si faltaba.
            if ($article->live_summary === null) {
                $article->setRelation('blog', $blog);
                $this->articles->syncLive($article, $json);
            }

            return false;
        }

        foreach ($this->media->usedMedia($blog, $json) as $media) {
            if ($deployment->force || $media->uploaded_at === null || ! $fs->fileExists($media->remote_path)) {
                $stream = Storage::disk('blogs')->readStream($media->local_path);
                $fs->writeStream($media->remote_path, $stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }

                $media->forceFill(['uploaded_at' => now()])->save();
                $deployment->files_count++;
            }
        }

        $moved = $this->publisher->place($fs, $article, $json, $html, $deployment);

        // Si el artículo tenía cambios sin publicar, siguen pendientes: solo avanza lo publicado.
        $pending = $article->hasUnpublishedChanges();

        $article->timestamps = false;
        $article->published_hash = $hash;

        if (! $pending) {
            $article->content_hash = $hash;
            $this->storage->writeHtml($blog->id, $article->public_id, $html);
        }

        if ($article->status === Article::STATUS_ERROR) {
            $article->status = Article::STATUS_PUBLISHED;
        }

        // Acaba de subirse: el servidor coincide con el CMS.
        $article->remote_state = 'ok';
        $article->remote_checked_at = now();

        $article->save();
        $article->setRelation('blog', $blog);
        $this->articles->syncLive($article, $json);
        $this->articles->syncMeta($article);

        return $moved;
    }

    /** Primera publicación de un artículo importado: sube imágenes y HTML y lo marca como publicado. */
    private function publishImported(FilesystemOperator $fs, Blog $blog, Article $article, Deployment $deployment): bool
    {
        $json = $this->articles->assertPublishable($article);
        $html = $this->renderer->render($blog, $json);
        $hash = $this->renderer->hash($html);

        foreach ($this->media->usedMedia($blog, $json) as $media) {
            $stream = Storage::disk('blogs')->readStream($media->local_path);
            $fs->writeStream($media->remote_path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $media->forceFill(['uploaded_at' => now()])->save();
            $deployment->files_count++;
        }

        // La URL que tenía en WordPress (si era de este mismo dominio y de dentro de /blog/) redirige a la nueva.
        if (! empty($json['import']['url']) && ($old = ArticleLocator::redirectSource($blog, (string) $json['import']['url']))) {
            $article->addRedirect($old);
        }

        $this->publisher->place($fs, $article, $json, $html, $deployment);

        $this->storage->writeHtml($blog->id, $article->public_id, $html);
        $this->storage->writePublishedJson($blog->id, $article->public_id, $json);

        $article->timestamps = false;
        $article->forceFill([
            'status' => Article::STATUS_PUBLISHED,
            'first_published_at' => now(),
            'content_hash' => $hash,
            'published_hash' => $hash,
            'remote_state' => 'ok',
            'remote_checked_at' => now(),
            'import_publish' => false,
        ])->save();

        $article->setRelation('blog', $blog);
        $this->articles->syncLive($article, $json);
        $this->articles->syncMeta($article);

        return false;
    }

    private function prepareSsi(FilesystemOperator $fs, Blog $blog, Deployment $deployment): void
    {
        $applied = $this->htaccess->apply($fs, $this->htaccess->buildBlock($blog));
        $deployment->files_count += $applied['changed'] ? 1 : 0;

        $this->listing->sync($fs, $blog, onChange: function () use ($deployment) {
            $deployment->files_count++;
        });
    }

    /** Reglas .htaccess, listado y sitemap: una sola vez al final, no por artículo. */
    private function finalize(FilesystemOperator $fs, Blog $blog, Deployment $deployment): void
    {
        $applied = $this->htaccess->apply($fs, $this->htaccess->buildBlock($blog));
        $deployment->files_count += $applied['changed'] ? 1 : 0;

        $this->listing->sync($fs, $blog, onChange: function () use ($deployment) {
            $deployment->files_count++;
        }, force: (bool) $deployment->force);

        $hash = TemplateFingerprint::for($blog);

        if ($blog->template_hash !== $hash) {
            $blog->forceFill([
                'template_hash' => $hash,
                'template_version' => $blog->template_version + ($blog->template_hash !== null ? 1 : 0),
            ])->save();
        }

        if ($blog->status !== Blog::STATUS_ACTIVE) {
            $blog->forceFill(['status' => Blog::STATUS_ACTIVE])->save();
        }

        $failed = $deployment->failed_items;

        // Una regeneración completa y sin fallos deja las páginas al día. Solo se limpia el aviso si el cambio
        // es anterior a su inicio: un renombrado hecho mientras corría no se vería en los artículos ya tratados.
        if ($deployment->action === 'regenerate' && $deployment->scope !== 'problems' && ! $failed) {
            Blog::withoutGlobalScopes()->whereKey($blog->id)
                ->where('pages_stale_at', '<=', $deployment->started_at ?? now())
                ->update(['pages_stale_at' => null]);
        }

        ActivityLogger::record($deployment->action === 'import' ? 'import.completed' : 'blog.regenerated', $blog, details: [
            'articles' => $deployment->done_items,
            'failed' => $failed,
            'files' => $deployment->files_count,
        ], userId: $deployment->triggered_by);

        $deployment->forceFill([
            'status' => $failed ? Deployment::STATUS_ERROR : Deployment::STATUS_OK,
            'error_code' => $failed ? PublishException::INCOMPLETE : null,
            'error_message' => $failed
                ? ($deployment->action === 'import'
                    ? "{$failed} artículos importados no se pudieron publicar (siguen como borrador). Ábrelos para revisarlos."
                    : "{$failed} artículos no se pudieron regenerar. Ábrelos para reintentar.")
                : null,
            'finished_at' => now(),
        ])->save();
    }
}
