<?php

namespace App\Services;

use App\Exceptions\PublishException;
use App\Jobs\PublishArticle;
use App\Jobs\UnpublishArticle;
use App\Models\Article;
use App\Models\Deployment;
use App\Models\User;

/**
 * Lanza los trabajos de publicación en uno de dos modos (PUBLIBLOG_QUEUE_PUBLISH):
 *
 *  - síncrono (por defecto): la petición espera y el usuario ve el resultado al instante;
 *  - cola: se registra un despliegue «en cola», el trabajo lo recoge el cron y la pantalla
 *    del artículo se refresca sola hasta que termina. El código del trabajo es el mismo.
 */
class PublishDispatcher
{
    public function __construct(private ArticleService $articles)
    {
    }

    public static function queued(): bool
    {
        return (bool) config('publiblog.queue_publish');
    }

    /** @return Deployment|null el despliegue en cola, o null si ya se ejecutó en esta petición */
    public function publish(Article $article, ?User $user): ?Deployment
    {
        if (! self::queued()) {
            PublishArticle::dispatchSync($article->id, $user?->id);

            return null;
        }

        $this->assertNotBusy($article);
        $this->articles->assertPublishable($article); // el error de validación se ve al momento, no minutos después

        $deployment = $this->queue($article, $user, $article->first_published_at ? 'update' : 'publish');
        PublishArticle::dispatch($article->id, $user?->id, $deployment->id);

        return $deployment;
    }

    public function unpublish(Article $article, ?User $user): ?Deployment
    {
        if (! self::queued()) {
            UnpublishArticle::dispatchSync($article->id, $user?->id);

            return null;
        }

        $this->assertNotBusy($article);

        $deployment = $this->queue($article, $user, 'unpublish');
        UnpublishArticle::dispatch($article->id, $user?->id, $deployment->id);

        return $deployment;
    }

    /** ¿Tiene el artículo una operación pendiente? */
    public static function isBusy(Article $article): bool
    {
        $last = $article->lastDeployment;

        return $last !== null
            && in_array($last->status, [Deployment::STATUS_QUEUED, Deployment::STATUS_RUNNING], true)
            && $last->updated_at->gt(now()->subMinutes(30));
    }

    private function assertNotBusy(Article $article): void
    {
        $article->unsetRelation('lastDeployment');

        if (self::isBusy($article)) {
            throw PublishException::make(PublishException::BLOCKED, 'Este artículo ya tiene una operación en curso. Espera a que termine.');
        }
    }

    private function queue(Article $article, ?User $user, string $action): Deployment
    {
        $deployment = Deployment::create([
            'blog_id' => $article->blog_id,
            'article_id' => $article->id,
            'action' => $action,
            'status' => Deployment::STATUS_QUEUED,
            'template_version' => $article->blog->template_version,
            'triggered_by' => $user?->id,
        ]);

        // Publicar no es editar: no se toca updated_at (lo usa el lastmod del sitemap).
        $article->timestamps = false;
        $article->forceFill(['last_deployment_id' => $deployment->id])->save();

        return $deployment;
    }
}
