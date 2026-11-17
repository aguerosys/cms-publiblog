<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\Deployment;
use App\Models\User;
use App\Services\ArticlePublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Publica o actualiza un artículo. Con PUBLIBLOG_QUEUE_PUBLISH=false (por defecto) se lanza con
 * dispatchSync y la petición espera; con true pasa a la cola de base de datos que procesa el cron.
 * El código es el mismo en los dos modos.
 */
class PublishArticle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Los pasos remotos son idempotentes, pero el reintento lo decide la persona con el botón. */
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly int $articleId,
        public readonly ?int $userId = null,
        public readonly ?int $deploymentId = null,
    ) {
    }

    public function handle(ArticlePublisher $publisher): void
    {
        $article = Article::with('blog')->findOrFail($this->articleId);

        $publisher->publish($article, $this->userId ? User::find($this->userId) : null, $this->deploymentId);
    }

    /** Un fallo fuera del flujo controlado (memoria, tiempo) no debe dejar el registro «en curso». */
    public function failed(Throwable $e): void
    {
        if (! $this->deploymentId) {
            return;
        }

        $deployment = Deployment::withoutGlobalScopes()->find($this->deploymentId);

        if ($deployment && in_array($deployment->status, [Deployment::STATUS_QUEUED, Deployment::STATUS_RUNNING], true)) {
            $deployment->forceFill([
                'status' => Deployment::STATUS_ERROR,
                'error_code' => 'incomplete',
                'error_message' => 'La publicación se interrumpió. Puedes reintentarla.',
                'finished_at' => now(),
            ])->save();

            Article::withoutGlobalScopes()->whereKey($this->articleId)->update(['status' => Article::STATUS_ERROR]);
        }
    }
}
