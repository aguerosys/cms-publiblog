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

/** Elimina un artículo: retira la regla del .htaccess, borra el archivo remoto y lo oculta en el CMS. */
class UnpublishArticle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

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

        $publisher->unpublish($article, $this->userId ? User::find($this->userId) : null, $this->deploymentId);
    }

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
                'error_message' => 'La eliminación se interrumpió. Puedes reintentarla.',
                'finished_at' => now(),
            ])->save();

            Article::withoutGlobalScopes()->whereKey($this->articleId)->update(['status' => Article::STATUS_ERROR]);
        }
    }
}
