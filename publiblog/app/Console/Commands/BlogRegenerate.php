<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Blog;
use App\Models\Deployment;
use App\Services\ArticlePublisher;
use Illuminate\Console\Command;

/**
 * Regenera con la plantilla actual y vuelve a subir todos los artículos publicados de un blog
 * (p. ej. tras restaurar la web del cliente o cambiar la plantilla). Cada artículo sigue el
 * flujo normal de publicación; el listado y el sitemap solo se suben si cambian.
 */
class BlogRegenerate extends Command
{
    protected $signature = 'blog:regenerate {blog : ID del blog} {--only= : Solo este ID público (000123)}';

    protected $description = 'Regenera y vuelve a subir todos los artículos publicados de un blog';

    public function handle(ArticlePublisher $publisher): int
    {
        $blog = Blog::find($this->argument('blog'));

        if (! $blog) {
            $this->error('No existe ese blog.');

            return self::FAILURE;
        }

        $articles = Article::with('blog')
            ->where('blog_id', $blog->id)
            ->whereNotNull('remote_path')
            ->when($this->option('only'), fn ($q, $id) => $q->where('public_id', $id))
            ->orderBy('public_id')
            ->get();

        $this->info("Regenerando {$articles->count()} artículos de «{$blog->name}»…");
        $failed = 0;

        foreach ($articles as $article) {
            $deployment = $publisher->publish($article);
            $ok = $deployment->status === Deployment::STATUS_OK;
            $failed += $ok ? 0 : 1;

            $this->line(sprintf('  %s %s  %s', $ok ? '✔' : '✘', $article->public_id, $ok ? "{$deployment->files_count} archivos" : $deployment->error_message));

            // Un fallo de conexión no mejora artículo a artículo: se corta.
            if (! $ok && in_array($deployment->error_code, ['auth', 'hostkey', 'blocked', 'connect', 'timeout'], true)) {
                $this->error('Se detiene: el problema es de conexión, no del artículo.');

                return self::FAILURE;
            }
        }

        $failed ? $this->warn("{$failed} con error. Revisa el historial del artículo y reintenta.") : $this->info('Todo publicado.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
