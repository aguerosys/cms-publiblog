<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\User;
use App\Services\ArticlePublisher;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/** Elimina un artículo: retira la regla del .htaccess, borra el archivo remoto y lo oculta en el CMS. */
class UnpublishArticle
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

        $publisher->unpublish($article, $this->userId ? User::find($this->userId) : null);
    }
}
