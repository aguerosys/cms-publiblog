<?php

namespace App\Jobs;

use App\Models\Article;
use App\Services\ArticlePublisher;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fase 1: se lanza con dispatchSync (la petición espera).
 * Fase 2: pasa a la cola de base de datos con cron sin cambiar este código
 * (basta con implementar ShouldQueue).
 */
class PublishArticle
{
    use Dispatchable, Queueable;

    public function __construct(
        public readonly int $articleId,
        public readonly ?int $userId = null,
    ) {
    }

    public function handle(ArticlePublisher $publisher): void
    {
        $article = Article::with('blog')->findOrFail($this->articleId);

        $publisher->publish($article, $this->userId ? \App\Models\User::find($this->userId) : null);
    }
}
