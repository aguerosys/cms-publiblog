<?php

namespace App\Console\Commands;

use App\Jobs\RegenerateBlog;
use App\Models\Blog;
use App\Models\Deployment;
use App\Services\BlogRegenerator;
use Illuminate\Console\Command;

/**
 * Regenera con la plantilla actual los artículos publicados de un blog y los vuelve a subir.
 *
 *   blog:regenerate 3             restaura: sube todo (imágenes, HTML, reglas, listado y sitemap)
 *   blog:regenerate 3 --changed   solo lo que el diseño nuevo cambia (tras tocar la plantilla)
 *   blog:regenerate 3 --queue     lo encola; lo procesa el cron (ver README)
 */
class BlogRegenerate extends Command
{
    protected $signature = 'blog:regenerate {blog : ID del blog} {--changed : Subir solo los archivos que cambian} {--queue : Encolar en lugar de ejecutar ahora}';

    protected $description = 'Regenera y vuelve a subir los artículos publicados de un blog';

    public function handle(BlogRegenerator $regenerator): int
    {
        $blog = Blog::find($this->argument('blog'));

        if (! $blog) {
            $this->error('No existe ese blog.');

            return self::FAILURE;
        }

        if ($regenerator->isRunning($blog)) {
            $this->error('Ya hay una regeneración de este blog en curso.');

            return self::FAILURE;
        }

        $deployment = $regenerator->start($blog, null, force: ! $this->option('changed'));

        if ($this->option('queue')) {
            RegenerateBlog::dispatch($deployment->id);
            $this->info("Regeneración encolada ({$deployment->total_items} artículos). La procesará el cron de la cola.");

            return self::SUCCESS;
        }

        $this->info("Regenerando {$deployment->total_items} artículos de «{$blog->name}»…");

        $regenerator->runAll($deployment, function (Deployment $d) {
            $this->line(sprintf('  %d/%d  (%d archivos, %d con error)', $d->done_items, $d->total_items, $d->files_count, $d->failed_items));
        });

        $deployment->refresh();

        if ($deployment->status === Deployment::STATUS_OK) {
            $this->info("Todo publicado: {$deployment->files_count} archivos subidos.");

            return self::SUCCESS;
        }

        $this->error($deployment->error_message ?: 'La regeneración falló.');

        return self::FAILURE;
    }
}
