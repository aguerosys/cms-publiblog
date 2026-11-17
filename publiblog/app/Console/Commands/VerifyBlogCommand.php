<?php

namespace App\Console\Commands;

use App\Jobs\VerifyBlog;
use App\Models\Article;
use App\Models\Blog;
use App\Models\Deployment;
use App\Services\BlogVerifier;
use Illuminate\Console\Command;

/**
 * Compara lo publicado en el servidor de cada cliente con lo que el CMS cree que subió.
 *
 *   blog:verify 3            comprueba el blog 3 ahora y lista las diferencias
 *   blog:verify --queue      encola la comprobación de todos los blogs listos (la procesa el cron)
 */
class VerifyBlogCommand extends Command
{
    protected $signature = 'blog:verify {blog? : ID del blog (todos los que estén listos si se omite)} {--queue : Encolar en lugar de ejecutar ahora}';

    protected $description = 'Verifica por SFTP que el HTML publicado coincide con el del CMS';

    private const LABELS = [
        'missing' => 'falta el archivo', 'modified' => 'distinto del publicado (editado fuera del CMS)',
        'missing_media' => 'faltan imágenes', 'no_rule' => 'falta su regla en el .htaccess',
    ];

    public function handle(BlogVerifier $verifier): int
    {
        $blogs = $this->argument('blog')
            ? Blog::whereKey($this->argument('blog'))->get()
            : Blog::all()->filter->canPublish();

        if ($blogs->isEmpty()) {
            $this->error($this->argument('blog') ? 'No existe ese blog.' : 'No hay blogs listos para comprobar.');

            return self::FAILURE;
        }

        $status = self::SUCCESS;

        foreach ($blogs as $blog) {
            if ($verifier->isRunning($blog)) {
                $this->warn("{$blog->name}: ya hay una comprobación en curso.");

                continue;
            }

            $deployment = $verifier->start($blog);

            if ($this->option('queue')) {
                VerifyBlog::dispatch($deployment->id);
                $this->info("{$blog->name}: comprobación encolada ({$deployment->total_items} artículos).");

                continue;
            }

            $deployment = $verifier->runAll($deployment);

            if ($deployment->status !== Deployment::STATUS_OK) {
                $this->error("{$blog->name}: {$deployment->error_message}");
                $status = self::FAILURE;

                continue;
            }

            if ($deployment->failed_items === 0) {
                $this->info("{$blog->name}: {$deployment->done_items} artículos, todo coincide.");

                continue;
            }

            $this->warn("{$blog->name}: {$deployment->failed_items} de {$deployment->done_items} artículos con diferencias:");

            Article::where('blog_id', $blog->id)->whereIn('remote_state', BlogVerifier::PROBLEMS)->orderBy('public_id')->each(
                fn (Article $a) => $this->line(sprintf('  %s  %s  — %s', $a->public_id, $a->slug, self::LABELS[$a->remote_state] ?? $a->remote_state))
            );

            $this->line('Para corregirlas: panel del blog → «Corregir diferencias», o blog:regenerate.');
            $status = self::FAILURE;
        }

        return $status;
    }
}
