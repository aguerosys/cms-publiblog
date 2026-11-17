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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;
use Throwable;

/**
 * Publica y retira artículos del servidor del cliente.
 *
 * Orden (plan): validar → guardar JSON → generar HTML → bloquear el blog → conectar y
 * comprobar la huella → subir imágenes nuevas → subir el HTML → actualizar .htaccess →
 * listado y sitemap (fase 1b) → verificar la URL pública → registrar.
 *
 * Los pasos remotos son idempotentes: Reintentar vuelve a ejecutar todo sin riesgo.
 * Si algo falla, el contenido ya está guardado en el CMS y el artículo pasa a `error`.
 */
class ArticlePublisher
{
    public function __construct(
        private RemoteFilesystemFactory $remote,
        private ArticleService $articles,
        private ArticleStorage $storage,
        private ArticleRenderer $renderer,
        private MediaService $media,
        private HtaccessManager $htaccess,
        private ListingBuilder $listing,
    ) {
    }

    public function publish(Article $article, ?User $user = null, ?int $deploymentId = null): Deployment
    {
        $blog = $article->blog;
        $action = $article->first_published_at ? 'update' : 'publish';

        return $this->run($article, $user, $action, $deploymentId, function (FilesystemOperator $fs, Deployment $deployment) use ($article, $blog) {
            $json = $this->articles->assertPublishable($article);

            // Se regenera con la plantilla actual, para que lo subido sea siempre lo último.
            $html = $this->renderer->render($blog, $json);
            $this->storage->writeHtml($blog->id, $article->public_id, $html);
            $hash = $this->renderer->hash($html);

            foreach ($this->media->usedMedia($blog, $json) as $media) {
                if ($media->uploaded_at === null || ! $fs->fileExists($media->remote_path)) {
                    $stream = Storage::disk('blogs')->readStream($media->local_path);
                    $fs->writeStream($media->remote_path, $stream);

                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    $media->forceFill(['uploaded_at' => now()])->save();
                    $this->count($deployment);
                }
            }

            $this->place($fs, $article, $json, $html, $deployment);

            // Lo que acaba de subirse: base del listado y de la regeneración, aunque luego se edite el borrador.
            $this->storage->writePublishedJson($blog->id, $article->public_id, $json);
            $this->articles->syncLive($article, $json);

            $block = $this->htaccess->buildBlock($blog, include: $article);
            $applied = $this->htaccess->apply($fs, $block);
            $this->count($deployment, $applied['changed'] ? 1 : 0);

            // Listado /blog/ y sitemap.xml: siempre al final y solo lo que cambió.
            $this->listing->sync($fs, $blog, include: $article, onChange: fn () => $this->count($deployment));

            $this->verify($blog, $article, $fs, $applied);

            $article->forceFill([
                'status' => Article::STATUS_PUBLISHED,
                'first_published_at' => $article->first_published_at ?? now(),
                'content_hash' => $hash,
                'published_hash' => $hash,
                'remote_state' => 'ok',
                'remote_checked_at' => now(),
            ])->save();
        });
    }

    /** Retira el artículo del servidor: primero la regla, después el archivo. */
    public function unpublish(Article $article, ?User $user = null, ?int $deploymentId = null): Deployment
    {
        $blog = $article->blog;

        if ($article->remote_path === null) {
            // Nunca llegó al servidor: solo se oculta en el CMS.
            $article->timestamps = false;
            $deployment = $this->newDeployment($article, $user, 'unpublish', $deploymentId);
            $article->delete();
            $this->articles->syncMeta($article);

            return $this->finish($deployment, $article, null);
        }

        return $this->run($article, $user, 'unpublish', $deploymentId, function (FilesystemOperator $fs, Deployment $deployment) use ($article, $blog) {
            $block = $this->htaccess->buildBlock($blog, exclude: $article);
            $this->count($deployment, $this->htaccess->apply($fs, $block)['changed'] ? 1 : 0);

            if (RemoteWriter::remove($fs, $article->remote_path)) {
                $this->count($deployment);
            }

            $this->listing->sync($fs, $blog, exclude: $article, onChange: fn () => $this->count($deployment));

            $this->articles->clearLive($article);
            $article->forceFill(['remote_path' => null, 'status' => Article::STATUS_DRAFT, 'published_hash' => null])->save();
            $article->delete();
        });
    }

    /**
     * Sube el HTML a su sitio: `{categoria-principal}/{slug}.html`. Si el artículo estaba publicado en
     * otro sitio (cambió de categoría principal, o es de antes de las categorías), retira el archivo viejo
     * y recuerda la URL antigua para redirigirla con un 301.
     *
     * Orden: subir el nuevo → borrar el viejo → guardar la nueva ubicación. Si algo falla a medias, el
     * artículo conserva su ubicación anterior en la base de datos y Reintentar repite el movimiento sin
     * dejar archivos huérfanos; lo peor que puede pasar es una URL antigua en 404 unos segundos, hasta que
     * se escriba el .htaccess con su redirección.
     *
     * @return bool si el artículo cambió de ubicación
     */
    public function place(FilesystemOperator $fs, Article $article, array $json, string $html, ?Deployment $deployment = null): bool
    {
        $target = ArticleLocator::fileFromJson($json) ?? $article->remote_path;

        if ($target === null) {
            throw PublishException::make(PublishException::VALIDATION, 'Falta: categoría.');
        }

        RemoteWriter::put($fs, $target, $html);
        $deployment && $this->count($deployment);

        $old = $article->remote_path;
        $moved = $old !== null && $old !== $target;

        if ($moved) {
            // Tras cambiar de ubicación pueden coincidir la URL antigua y la nueva (p. ej. A → B → A): no se redirige a sí misma.
            $oldUrl = ArticleLocator::publicPath($old);

            if (RemoteWriter::remove($fs, $old) && $deployment) {
                $this->count($deployment);
            }

            $article->addRedirect($oldUrl);
        }

        $article->timestamps = false;
        $article->remote_path = $target;
        $article->redirects = array_values(array_filter(
            $article->redirects ?? [],
            fn ($path) => $path !== ArticleLocator::publicPath($target)
        ));
        $article->save();

        return $moved;
    }

    /**
     * Ejecuta un trabajo remoto bajo el bloqueo del blog y registra el resultado.
     *
     * @param  callable(FilesystemOperator, Deployment): void  $work
     */
    private function run(Article $article, ?User $user, string $action, ?int $deploymentId, callable $work): Deployment
    {
        $blog = $article->blog;
        // Una publicación con imágenes puede tardar más que el límite por defecto del hosting.
        @set_time_limit((int) config('publiblog.publish.time_limit'));

        // Publicar no es editar: updated_at (y por tanto el lastmod del sitemap) solo cambia al guardar contenido.
        $article->timestamps = false;

        $deployment = $this->newDeployment($article, $user, $action, $deploymentId);
        $lock = Cache::lock("blog:{$blog->id}", (int) config('publiblog.publish.lock_ttl'));
        $acquired = false;

        try {
            $this->guardBlog($blog);

            try {
                $lock->block((int) config('publiblog.publish.lock_wait'));
                $acquired = true;
            } catch (LockTimeoutException $e) {
                throw PublishException::make(PublishException::LOCK_TIMEOUT, null, $e);
            }

            $fs = $this->remote->make($blog->fresh());
            $work($fs, $deployment);

            if ($blog->status !== Blog::STATUS_ACTIVE) {
                $blog->forceFill(['status' => Blog::STATUS_ACTIVE])->save();
            }

            // Primera referencia de la plantilla: a partir de aquí el panel avisa si cambia (F2-4).
            if ($blog->template_hash === null) {
                $blog->forceFill(['template_hash' => TemplateFingerprint::for($blog)])->save();
            }

            return $this->finish($deployment, $article, null);
        } catch (Throwable $e) {
            $error = RemoteErrorMapper::map($e);

            if (! in_array($error->errorCode, [PublishException::VALIDATION, PublishException::LOCK_TIMEOUT], true)) {
                $this->logFailure($blog, $article, $error, $e);
            }

            $this->applyBlogStatus($blog, $error);

            return $this->finish($deployment, $article, $error);
        } finally {
            if ($acquired) {
                $lock->release();
            }
        }
    }

    /** Archivos subidos o borrados hasta ahora; queda registrado aunque el trabajo falle más tarde. */
    private function count(Deployment $deployment, int $n = 1): void
    {
        $deployment->files_count += $n;
    }

    public function guardBlog(Blog $blog): void
    {
        $blog = $blog->fresh();

        if ($blog->status === Blog::STATUS_HOSTKEY_CHANGED) {
            throw PublishException::make(PublishException::BLOCKED, 'Un administrador debe confirmar la nueva huella del servidor.');
        }

        // Sin huella guardada no hay forma de saber si el servidor es el correcto.
        if (! $blog->isLocal() && blank($blog->host_key_fingerprint)) {
            throw PublishException::make(PublishException::BLOCKED, 'Pulsa «Probar conexión» y confirma la huella del servidor.');
        }
    }

    /** Registra el fallo sin volcar la traza (podría mostrar fragmentos de credenciales). */
    public function logFailure(Blog $blog, ?Article $article, PublishException $error, Throwable $e): void
    {
        $message = $e->getMessage();

        foreach ([$blog->sftp_secret, $blog->sftp_passphrase] as $secret) {
            if (is_string($secret) && strlen($secret) >= 3) {
                $message = str_replace($secret, '***', $message);
            }
        }

        Log::warning('Publiblog: fallo al publicar', [
            'blog_id' => $blog->id,
            'article_id' => $article?->id,
            'code' => $error->errorCode,
            'exception' => get_class($e),
            'message' => $message,
        ]);
    }

    public function applyBlogStatus(Blog $blog, PublishException $error): void
    {
        $status = match ($error->errorCode) {
            PublishException::AUTH => Blog::STATUS_CONNECTION_ERROR,
            PublishException::HOSTKEY => Blog::STATUS_HOSTKEY_CHANGED,
            default => null,
        };

        if ($status) {
            $blog->forceFill(['status' => $status])->save();
        }
    }

    /** @param array{changed:bool, had_original:bool} $applied */
    private function verify(Blog $blog, Article $article, FilesystemOperator $fs, array $applied): void
    {
        if (! $blog->verify_http) {
            return;
        }

        $url = $article->url().'?_pb='.time();

        try {
            $response = Http::timeout((int) config('publiblog.publish.verify_timeout'))
                ->withUserAgent('Publiblog/1.0 (verificación de publicación)')
                ->get($url);
        } catch (Throwable $e) {
            throw PublishException::make(PublishException::VERIFY, '('.$article->url().' no responde)', $e);
        }

        if ($response->serverError() && $applied['changed']) {
            // Un error de sintaxis en .htaccess rompe todo /blog/: se restaura la copia previa.
            try {
                $this->htaccess->restore($fs, $applied['had_original']);
            } catch (Throwable $e) {
                Log::warning('Publiblog: no se pudo restaurar el .htaccess', ['blog_id' => $blog->id, 'message' => $e->getMessage()]);
            }

            throw PublishException::make(PublishException::VERIFY, "(HTTP {$response->status()}; se restauró el .htaccess anterior)");
        }

        if ($response->status() !== 200) {
            throw PublishException::make(PublishException::VERIFY, "(HTTP {$response->status()})");
        }

        $needle = '<meta name="blog-article-id" content="'.$article->public_id.'">';

        if (! str_contains($response->body(), $needle)) {
            throw PublishException::make(PublishException::VERIFY, '(la página no contiene el ID del artículo)');
        }

        // Con cabecera y pie compartidos, una directiva sin ejecutar significa una página sin cabecera ni pie.
        if ($blog->ssi_enabled && str_contains($response->body(), '<!--#include')) {
            throw PublishException::make(PublishException::VERIFY, '(el servidor no está ejecutando las inclusiones SSI: la página saldría sin cabecera ni pie)');
        }
    }

    private function newDeployment(Article $article, ?User $user, string $action, ?int $existingId = null): Deployment
    {
        // Desde la cola el registro ya existe («en cola»): pasa a «en curso» en vez de crear otro.
        $existing = $existingId ? Deployment::withoutGlobalScopes()->find($existingId) : null;

        if ($existing) {
            $existing->forceFill(['status' => Deployment::STATUS_RUNNING, 'started_at' => now(), 'template_version' => $article->blog->template_version])->save();

            return $existing;
        }

        return Deployment::create([
            'blog_id' => $article->blog_id,
            'article_id' => $article->id,
            'action' => $action,
            'status' => Deployment::STATUS_RUNNING,
            'template_version' => $article->blog->template_version,
            'started_at' => now(),
            'triggered_by' => $user?->id,
        ]);
    }

    private function finish(Deployment $deployment, Article $article, ?PublishException $error): Deployment
    {
        $deployment->forceFill([
            'status' => $error ? Deployment::STATUS_ERROR : Deployment::STATUS_OK,
            'error_code' => $error?->errorCode,
            'error_message' => $error?->getMessage(),
            'finished_at' => now(),
        ])->save();

        $article->last_deployment_id = $deployment->id;

        // El contenido ya está guardado: solo cambia el estado. Si nada llegó a tocar el servidor
        // (validación, blog bloqueado, otra publicación en curso) el estado anterior se mantiene.
        $untouched = [PublishException::VALIDATION, PublishException::BLOCKED, PublishException::LOCK_TIMEOUT];

        if ($error && ! in_array($error->errorCode, $untouched, true)) {
            $article->status = Article::STATUS_ERROR;
        }

        $article->save();
        $this->articles->syncMeta($article);

        $event = $error ? 'article.publish_failed' : match ($deployment->action) {
            'unpublish' => 'article.unpublished',
            'update' => 'article.updated',
            default => 'article.published',
        };

        ActivityLogger::record($event, article: $article, details: array_filter([
            'action' => $deployment->action,
            'error_code' => $error?->errorCode,
            'error' => $error?->getMessage(),
            'files' => $deployment->files_count,
        ], fn ($v) => $v !== null), userId: $deployment->triggered_by);

        return $deployment;
    }
}
