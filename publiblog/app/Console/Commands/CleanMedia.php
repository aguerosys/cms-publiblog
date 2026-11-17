<?php

namespace App\Console\Commands;

use App\Models\Blog;
use App\Services\MediaCleaner;
use Illuminate\Console\Command;

/**
 * Borra las imágenes que ningún artículo usa ya (ni su borrador ni lo publicado).
 *
 *   blog:clean-media --dry-run          cuenta lo que borraría
 *   blog:clean-media 3 --days=30        solo el blog 3, con 30 días de gracia
 *   blog:clean-media --local-only       no toca los servidores de los clientes
 */
class CleanMedia extends Command
{
    protected $signature = 'blog:clean-media {blog? : ID del blog (todos si se omite)} {--days=14 : Días de gracia desde la subida} {--dry-run : Solo contar} {--local-only : No borrar nada en los servidores de los clientes}';

    protected $description = 'Elimina imágenes huérfanas del CMS y de los servidores de los clientes';

    public function handle(MediaCleaner $cleaner): int
    {
        $blogs = $this->argument('blog') ? Blog::whereKey($this->argument('blog'))->get() : Blog::all();

        if ($blogs->isEmpty()) {
            $this->error('No existe ese blog.');

            return self::FAILURE;
        }

        $status = self::SUCCESS;

        foreach ($blogs as $blog) {
            $r = $cleaner->clean($blog, (int) $this->option('days'), (bool) $this->option('dry-run'), ! $this->option('local-only'));

            $this->line(sprintf(
                '%s: %d imágenes, %d archivos sueltos, %s%s',
                $blog->name, $r['rows'], $r['files'], number_format($r['bytes'] / 1024 / 1024, 1).' MB',
                $this->option('dry-run') ? ' (simulación)' : ($r['remote'] ? ", {$r['remote']} borradas en el servidor" : '')
            ));

            if ($r['error']) {
                $this->warn("  {$r['error']}");
                $status = self::FAILURE;
            }
        }

        return $status;
    }
}
