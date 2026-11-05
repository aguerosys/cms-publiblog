<?php

namespace App\Console\Commands;

use App\Models\Blog;
use App\Services\ArticleIndexer;
use Illuminate\Console\Command;

class BlogReindex extends Command
{
    protected $signature = 'blog:reindex {blog : ID del blog} {--fresh : Vacía antes la tabla articles del blog}';

    protected $description = 'Reconstruye el índice de artículos (tabla articles) desde los JSON del CMS';

    public function handle(ArticleIndexer $indexer): int
    {
        $blog = Blog::find($this->argument('blog'));

        if (! $blog) {
            $this->error('No existe ese blog.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $blog->articles()->withTrashed()->forceDelete();
        }

        $count = $indexer->reindex($blog);

        $this->info("{$count} artículos indexados en «{$blog->name}».");

        return self::SUCCESS;
    }
}
