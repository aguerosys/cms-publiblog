<?php

namespace App\Console\Commands;

use App\Jobs\RegenerateBlog;
use App\Models\Blog;
use App\Models\Deployment;
use App\Services\BlogRegenerator;
use App\Services\Import\ImportReport;
use App\Services\Import\WordPressImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Importa el XML de exportación de WordPress (Herramientas → Exportar → Entradas).
 *
 *   blog:import-wordpress 3 dialogf.xml --dry-run          comprueba sin escribir nada
 *   blog:import-wordpress 3 dialogf.xml                    crea los artículos como borradores (+ imágenes)
 *   blog:import-wordpress 3 dialogf.xml --publish          …y publica en lote los que estaban publicados
 *   blog:import-wordpress 3 dialogf.xml --images-dir=/ruta/wp-content/uploads    imágenes desde una copia local
 */
class ImportWordPress extends Command
{
    protected $signature = 'blog:import-wordpress
        {blog : ID del blog destino}
        {file : Ruta del XML de WordPress (WXR)}
        {--dry-run : Solo comprobar y generar el informe, sin escribir nada}
        {--publish : Publicar al terminar las entradas que estaban publicadas en WordPress}
        {--queue : Con --publish, encolar la publicación en lugar de ejecutarla ahora}
        {--images-dir= : Carpeta con una copia de wp-content/uploads (se usa antes de descargar)}
        {--no-download : No descargar imágenes de internet}
        {--source-host= : Dominio del sitio de origen (para detectar enlaces internos), si el XML no lo indica}
        {--limit= : Importar solo las N primeras entradas (pruebas)}';

    protected $description = 'Importa entradas de un XML de WordPress como artículos del blog';

    public function handle(WordPressImporter $importer, BlogRegenerator $regenerator): int
    {
        $blog = Blog::find($this->argument('blog'));

        if (! $blog) {
            $this->error('No existe ese blog.');

            return self::FAILURE;
        }

        if ($this->option('publish') && ! $this->option('dry-run') && ! $blog->canPublish()) {
            $this->error('El blog no está listo para publicar (revisa la conexión). Importa sin --publish y publica después.');

            return self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? 'Simulando' : 'Importando')." {$this->argument('file')} en «{$blog->name}»…");

        try {
            $report = $importer->import($blog, $this->argument('file'), [
                'images_dir' => $this->option('images-dir'),
                'download' => ! $this->option('no-download'),
                'dry_run' => (bool) $this->option('dry-run'),
                'limit' => $this->option('limit') ? (int) $this->option('limit') : null,
                'source_host' => $this->option('source-host'),
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Resultado', 'Entradas'],
            collect([ImportReport::IMPORTED, ImportReport::DRY_RUN, ImportReport::SKIPPED, ImportReport::ERROR])
                ->filter(fn ($s) => $report->count($s) > 0)
                ->map(fn ($s) => [$s, $report->count($s)])->values()->all()
        );

        if ($report->warnings()) {
            $this->warn("{$report->warnings()} entradas importadas con avisos (imágenes, shortcodes, enlaces…). Mira el informe.");
        }

        $path = $report->save($blog->id);
        $this->line('Informe: '.storage_path('app/private/'.$path));

        if ($this->option('publish') && ! $this->option('dry-run')) {
            $deployment = $regenerator->startImport($blog);

            if ($deployment->total_items === 0) {
                $this->line('No hay entradas pendientes de publicar.');
            } elseif ($this->option('queue')) {
                RegenerateBlog::dispatch($deployment->id);
                $this->info("Publicación encolada ({$deployment->total_items} artículos). La procesará el cron de la cola.");
            } else {
                $this->info("Publicando {$deployment->total_items} artículos…");
                $regenerator->runAll($deployment, fn (Deployment $d) => $this->line(sprintf('  %d/%d', $d->done_items, $d->total_items)));
                $deployment->refresh();

                if ($deployment->status !== Deployment::STATUS_OK) {
                    $this->error($deployment->error_message ?: 'La publicación terminó con errores.');

                    return self::FAILURE;
                }

                $this->info('Publicado.');
            }
        }

        return $report->count(ImportReport::ERROR) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
