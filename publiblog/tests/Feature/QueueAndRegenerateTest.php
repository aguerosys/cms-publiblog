<?php

namespace Tests\Feature;

use App\Exceptions\PublishException;
use App\Jobs\PublishArticle;
use App\Jobs\RegenerateBlog;
use App\Jobs\UnpublishArticle;
use App\Models\Article;
use App\Models\Blog;
use App\Models\Deployment;
use App\Models\User;
use App\Services\ArticleService;
use App\Services\BlogRegenerator;
use App\Services\TemplateFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InteractsWithBlogs;
use Tests\TestCase;

/** F2-4: cola con cron, publicación en cola y regeneración masiva por diferencias con progreso. */
class QueueAndRegenerateTest extends TestCase
{
    use InteractsWithBlogs, RefreshDatabase;

    private const TEMPLATE = 'tpltest';

    private Blog $blog;
    private string $templateDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBlogs();

        // Plantilla propia de la prueba: se puede "cambiar el pie" sin tocar la real.
        $this->templateDir = resource_path('views/blogs/'.self::TEMPLATE);
        File::deleteDirectory($this->templateDir);
        File::copyDirectory(resource_path('views/blogs/demoblog'), $this->templateDir);

        foreach (File::allFiles($this->templateDir) as $file) {
            File::put($file->getPathname(), str_replace('blogs.demoblog.', 'blogs.'.self::TEMPLATE.'.', File::get($file->getPathname())));
        }

        $this->blog = $this->makeBlog(['template' => self::TEMPLATE, 'posts_per_page' => 2]);
        $this->fakeVerification();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->templateDir);
        $this->tearDownBlogs();
        parent::tearDown();
    }

    private function publishArticle(string $title, int $day): Article
    {
        $service = app(ArticleService::class);
        $article = $service->createDraft($this->blog);
        $service->save($article, $this->chooseCategory($this->blog) + [
            'title' => $title, 'body_html' => "<p>Cuerpo de {$title}</p>", 'excerpt' => "Extracto de {$title}",
            'published_at' => sprintf('2026-11-%02dT09:00', $day), 'version' => $article->version,
        ]);
        PublishArticle::dispatchSync($article->id);

        return $article->fresh();
    }

    private function remote(string $path): string
    {
        return file_get_contents($this->remoteDir.'/'.$path);
    }

    /** Cambia un archivo de la plantilla (y renueva su fecha para que Blade recompile). */
    private function editTemplate(string $file, string $from, string $to): void
    {
        $path = $this->templateDir.'/'.$file;
        $contents = File::get($path);
        $this->assertStringContainsString($from, $contents, "No se encontró «{$from}» en {$file}");
        File::put($path, str_replace($from, $to, $contents));
        touch($path, time() + 5);
        clearstatcache();
        // Como `php artisan view:clear` tras desplegar una plantilla nueva.
        $this->artisan('view:clear');
    }

    private function regenerate(bool $force = false, ?User $user = null): Deployment
    {
        $regenerator = app(BlogRegenerator::class);

        return $regenerator->runAll($regenerator->start($this->blog->fresh(), $user, $force));
    }

    // ───────── regeneración masiva ─────────

    public function test_footer_change_regenerates_every_article_and_uploads_the_affected_files(): void
    {
        $a = $this->publishArticle('Uno', 1);
        $b = $this->publishArticle('Dos', 2);
        $this->assertStringNotContainsString('PIE-NUEVO', $this->remote($a->remote_path));

        $this->editTemplate('partials/footer.blade.php', '<footer', '<footer data-x="PIE-NUEVO"');

        $d = $this->regenerate();

        $this->assertSame(Deployment::STATUS_OK, $d->status);
        $this->assertSame(2, $d->done_items);
        $this->assertSame(2, $d->total_items);
        $this->assertSame(0, $d->failed_items);
        $this->assertStringContainsString('PIE-NUEVO', $this->remote($a->remote_path));
        $this->assertStringContainsString('PIE-NUEVO', $this->remote($b->remote_path));
        $this->assertStringContainsString('PIE-NUEVO', $this->remote('index.html')); // el listado comparte el pie
        $this->assertSame(
            $this->blog->fresh()->template_hash,
            TemplateFingerprint::for($this->blog),
        );
    }

    public function test_a_second_regeneration_with_nothing_changed_uploads_nothing(): void
    {
        $this->publishArticle('Uno', 1);
        $this->publishArticle('Dos', 2);

        $d = $this->regenerate();

        $this->assertSame(Deployment::STATUS_OK, $d->status);
        $this->assertSame(0, $d->files_count);
        $this->assertSame(2, $d->done_items);
    }

    public function test_a_change_that_only_affects_the_listing_does_not_reupload_articles(): void
    {
        $a = $this->publishArticle('Uno', 1);
        $mtime = filemtime($this->remoteDir.'/'.$a->remote_path);
        clearstatcache();

        $this->editTemplate('index.blade.php', '<main', '<main data-listado="NUEVO"');

        $d = $this->regenerate();

        $this->assertSame(2, $d->files_count); // solo los listados: /blog/ y el de su categoría; ningún artículo
        $this->assertStringContainsString('data-listado="NUEVO"', $this->remote('index.html'));
        $this->assertSame($mtime, filemtime($this->remoteDir.'/'.$a->remote_path));
    }

    public function test_force_reuploads_everything_to_restore_a_lost_site(): void
    {
        $a = $this->publishArticle('Uno', 1);
        $this->publishArticle('Dos', 2);

        File::deleteDirectory($this->remoteDir);
        File::ensureDirectoryExists($this->remoteDir);

        $d = $this->regenerate(force: true);

        $this->assertSame(Deployment::STATUS_OK, $d->status);
        $this->assertFileExists($this->remoteDir.'/'.$a->remote_path);
        $this->assertFileExists($this->remoteDir.'/.htaccess');
        $this->assertFileExists($this->remoteDir.'/index.html');
        $this->assertFileExists($this->remoteDir.'/sitemap.xml');
        $this->assertSame(3, substr_count($this->remote('.htaccess'), 'RewriteRule '));
    }

    public function test_regeneration_never_publishes_unpublished_edits(): void
    {
        $a = $this->publishArticle('Título publicado', 1);

        // Edición sin publicar: sigue siendo un borrador.
        app(ArticleService::class)->save($a, [
            'title' => 'Título EN BORRADOR', 'body_html' => '<p>Borrador</p>', 'published_at' => '2026-11-01T09:00',
            'version' => $a->version,
        ]);
        $this->assertTrue($a->fresh()->hasUnpublishedChanges());

        $this->editTemplate('partials/footer.blade.php', '<footer', '<footer data-x="V2"');
        $this->regenerate();

        $this->assertStringContainsString('Título publicado', $this->remote($a->remote_path));
        $this->assertStringNotContainsString('EN BORRADOR', $this->remote($a->remote_path));
        $this->assertStringContainsString('data-x="V2"', $this->remote($a->remote_path));
        $this->assertStringNotContainsString('EN BORRADOR', $this->remote('index.html'));
        $this->assertTrue($a->fresh()->hasUnpublishedChanges(), 'los cambios sin publicar siguen pendientes');
    }

    public function test_listing_does_not_leak_a_draft_edit_when_another_article_is_published(): void
    {
        $a = $this->publishArticle('Original', 1);

        app(ArticleService::class)->save($a, [
            'title' => 'Editado sin publicar', 'body_html' => '<p>x</p>', 'published_at' => '2026-11-01T09:00', 'version' => $a->version,
        ]);

        $this->publishArticle('Otro', 2);

        $this->assertStringContainsString('Original', $this->remote('index.html'));
        $this->assertStringNotContainsString('Editado sin publicar', $this->remote('index.html'));
    }

    public function test_work_is_split_into_batches_with_progress(): void
    {
        config(['publiblog.regenerate_batch' => 2]);

        foreach (range(1, 5) as $i) {
            $this->publishArticle("Artículo {$i}", $i);
        }
        $this->editTemplate('partials/footer.blade.php', '<footer', '<footer data-x="LOTES"');

        $regenerator = app(BlogRegenerator::class);
        $d = $regenerator->start($this->blog, null);
        $this->assertSame(5, $d->total_items);
        $this->assertSame(Deployment::STATUS_QUEUED, $d->status);

        $cursor = $regenerator->runBatch($d, 0);
        $d->refresh();
        $this->assertNotNull($cursor);
        $this->assertSame(Deployment::STATUS_RUNNING, $d->status);
        $this->assertSame(2, $d->done_items);

        $cursor = $regenerator->runBatch($d, $cursor);
        $this->assertSame(4, $d->refresh()->done_items);

        $this->assertNull($regenerator->runBatch($d, $cursor));
        $d->refresh();
        $this->assertSame(Deployment::STATUS_OK, $d->status);
        $this->assertSame(5, $d->done_items);
        $this->assertNotNull($d->finished_at);
    }

    public function test_queue_job_chains_batches_until_done(): void
    {
        config(['publiblog.regenerate_batch' => 2]);

        foreach (range(1, 5) as $i) {
            $this->publishArticle("Artículo {$i}", $i);
        }
        $this->editTemplate('partials/footer.blade.php', '<footer', '<footer data-x="COLA"');

        $d = app(BlogRegenerator::class)->start($this->blog, null);
        RegenerateBlog::dispatch($d->id); // cola "sync" en pruebas: encadena los lotes

        $d->refresh();
        $this->assertSame(Deployment::STATUS_OK, $d->status);
        $this->assertSame(5, $d->done_items);
        $this->assertStringContainsString('data-x="COLA"', $this->remote('index.html'));
    }

    public function test_job_queues_the_next_batch_instead_of_running_it_inline(): void
    {
        config(['publiblog.regenerate_batch' => 2]);
        foreach (range(1, 3) as $i) {
            $this->publishArticle("Artículo {$i}", $i);
        }

        Queue::fake();
        $d = app(BlogRegenerator::class)->start($this->blog, null);
        (new RegenerateBlog($d->id))->handle(app(BlogRegenerator::class));

        Queue::assertPushed(RegenerateBlog::class, fn ($job) => $job->deploymentId === $d->id && $job->afterId > 0);
    }

    public function test_one_failing_article_does_not_stop_the_rest(): void
    {
        $a = $this->publishArticle('Roto', 1);
        $b = $this->publishArticle('Sano', 2);

        // Se pierde el JSON publicado y el editable del primero.
        \Storage::disk('blogs')->delete([
            "{$this->blog->id}/articles/{$a->shard()}/{$a->public_id}.published.json",
            "{$this->blog->id}/articles/{$a->shard()}/{$a->public_id}.json",
        ]);

        $this->editTemplate('partials/footer.blade.php', '<footer', '<footer data-x="OK"');
        $d = $this->regenerate();

        $this->assertSame(Deployment::STATUS_ERROR, $d->status);
        $this->assertSame(1, $d->failed_items);
        $this->assertSame(2, $d->done_items);
        $this->assertStringContainsString('data-x="OK"', $this->remote($b->remote_path));
        $this->assertSame(Article::STATUS_ERROR, $a->fresh()->status);
        $this->assertSame(Article::STATUS_PUBLISHED, $b->fresh()->status);
        $this->assertSame(1, Deployment::where('article_id', $a->id)->where('action', 'regenerate')->where('status', 'error')->count());
    }

    public function test_connection_problems_abort_the_whole_regeneration(): void
    {
        $this->publishArticle('Uno', 1);
        $this->blog->update(['status' => Blog::STATUS_HOSTKEY_CHANGED]);

        $d = $this->regenerate();

        $this->assertSame(Deployment::STATUS_ERROR, $d->status);
        $this->assertSame('blocked', $d->error_code);
        $this->assertSame(0, $d->done_items);
    }

    public function test_busy_blog_makes_the_batch_wait_instead_of_failing(): void
    {
        $this->publishArticle('Uno', 1);
        config(['publiblog.publish.lock_wait' => 0]);

        $lock = Cache::lock("blog:{$this->blog->id}", 30);
        $this->assertTrue($lock->get());

        $d = app(BlogRegenerator::class)->start($this->blog, null);

        try {
            app(BlogRegenerator::class)->runBatch($d, 0);
            $this->fail('Debía lanzar LOCK_TIMEOUT');
        } catch (PublishException $e) {
            $this->assertSame(PublishException::LOCK_TIMEOUT, $e->errorCode);
        }

        $this->assertNotSame(Deployment::STATUS_ERROR, $d->refresh()->status);

        // El trabajo de cola se libera para reintentar en lugar de marcar error.
        $job = (new RegenerateBlog($d->id))->setJob($fake = new class extends \Illuminate\Queue\Jobs\SyncJob {
            public $released = null;

            public function __construct() {}

            public function release($delay = 0) { $this->released = $delay; }

            public function attempts() { return 1; }
        });
        $job->handle(app(BlogRegenerator::class));
        $this->assertSame(30, $fake->released);

        $lock->release();
    }

    public function test_only_one_regeneration_runs_at_a_time(): void
    {
        $this->publishArticle('Uno', 1);
        $regenerator = app(BlogRegenerator::class);

        $this->assertFalse($regenerator->isRunning($this->blog));
        $regenerator->start($this->blog, null);
        $this->assertTrue($regenerator->isRunning($this->blog));
    }

    // ───────── comando ─────────

    public function test_command_runs_with_progress_and_changed_option(): void
    {
        $this->publishArticle('Uno', 1);

        $this->artisan('blog:regenerate', ['blog' => $this->blog->id, '--changed' => true])
            ->expectsOutputToContain('1/1')
            ->assertSuccessful();

        $last = Deployment::where('action', 'regenerate')->latest('id')->first();
        $this->assertFalse((bool) $last->force);
        $this->assertSame(0, $last->files_count);
    }

    public function test_command_can_queue_instead_of_running(): void
    {
        $this->publishArticle('Uno', 1);
        Queue::fake();

        $this->artisan('blog:regenerate', ['blog' => $this->blog->id, '--queue' => true])->assertSuccessful();

        Queue::assertPushed(RegenerateBlog::class);
        $this->assertSame(Deployment::STATUS_QUEUED, Deployment::where('action', 'regenerate')->first()->status);
    }

    public function test_command_refuses_to_start_while_another_regeneration_is_running(): void
    {
        $this->publishArticle('Uno', 1);
        app(BlogRegenerator::class)->start($this->blog, null);

        $this->artisan('blog:regenerate', ['blog' => $this->blog->id])->assertFailed();
    }

    // ───────── panel ─────────

    public function test_superadmin_starts_a_regeneration_from_the_panel_and_sees_progress(): void
    {
        $this->publishArticle('Uno', 1);
        $this->editTemplate('partials/footer.blade.php', '<footer', '<footer data-x="PANEL"');

        $this->actingAs($this->admin());
        $this->get(route('blogs.show', $this->blog))->assertSee('La plantilla ha cambiado');

        $this->post(route('blogs.regenerate', $this->blog))->assertRedirect(route('blogs.show', $this->blog));

        $this->get(route('blogs.show', $this->blog))
            ->assertOk()->assertSee('Terminada')->assertSee('1/1 artículos')
            ->assertDontSee('La plantilla ha cambiado');
    }

    public function test_panel_shows_a_running_regeneration_with_auto_refresh(): void
    {
        $this->publishArticle('Uno', 1);
        $d = app(BlogRegenerator::class)->start($this->blog, null);
        $d->forceFill(['status' => 'running', 'done_items' => 0])->save();

        $this->actingAs($this->admin())->get(route('blogs.show', $this->blog))
            ->assertOk()->assertSee('En curso')->assertSee('http-equiv="refresh"', false);

        $this->post(route('blogs.regenerate', $this->blog))->assertSessionHas('error');
    }

    public function test_blog_users_cannot_regenerate(): void
    {
        $user = User::factory()->create(['role' => 'blog_user']);
        $user->blogs()->attach($this->blog->id, ['role' => 'editor']);

        $this->actingAs($user)->post(route('blogs.regenerate', $this->blog))->assertForbidden();
        $this->get(route('blogs.show', $this->blog))->assertDontSee('Regenerar blog');
    }

    // ───────── publicación en cola ─────────

    public function test_queue_mode_registers_a_queued_deployment_and_does_not_touch_the_server(): void
    {
        config(['publiblog.queue_publish' => true]);
        Queue::fake();

        $service = app(ArticleService::class);
        $article = $service->createDraft($this->blog);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('articles.update', [$this->blog, $article]), $this->chooseCategory($this->blog) + [
            'action' => 'publish', 'title' => 'En cola', 'body_html' => '<p>x</p>',
            'published_at' => '2026-11-10T09:00', 'version' => $article->version,
        ])->assertRedirect(route('articles.edit', [$this->blog, $article]))->assertSessionHas('status');

        $article->refresh();
        $deployment = Deployment::where('article_id', $article->id)->sole();
        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->status);
        $this->assertSame($deployment->id, $article->last_deployment_id);
        $this->assertDirectoryDoesNotExist($this->remoteDir.'/general'); // no se ha tocado el servidor
        Queue::assertPushed(PublishArticle::class, fn ($j) => $j->deploymentId === $deployment->id && $j->userId === $admin->id);

        $this->get(route('articles.edit', [$this->blog, $article]))
            ->assertSee('En cola')->assertSee('http-equiv="refresh"', false);
    }

    public function test_queued_job_completes_the_same_deployment_when_the_worker_runs_it(): void
    {
        config(['publiblog.queue_publish' => true]);
        Queue::fake();

        $service = app(ArticleService::class);
        $article = $service->createDraft($this->blog);
        $this->actingAs($this->admin())->put(route('articles.update', [$this->blog, $article]), $this->chooseCategory($this->blog) + [
            'action' => 'publish', 'title' => 'Con worker', 'body_html' => '<p>x</p>',
            'published_at' => '2026-11-10T09:00', 'version' => $article->version,
        ]);

        $deployment = Deployment::where('article_id', $article->id)->sole();

        // El cron ejecuta el trabajo.
        (new PublishArticle($article->id, null, $deployment->id))->handle(app(\App\Services\ArticlePublisher::class));

        $this->assertSame(1, Deployment::where('article_id', $article->id)->count(), 'no se crea un segundo registro');
        $this->assertSame(Deployment::STATUS_OK, $deployment->fresh()->status);
        $this->assertSame(Article::STATUS_PUBLISHED, $article->fresh()->status);
        $this->assertFileExists($this->remoteDir.'/'.$article->fresh()->remote_path);
        $this->assertNotNull($deployment->fresh()->started_at);
    }

    public function test_queue_mode_blocks_a_second_operation_while_one_is_pending(): void
    {
        config(['publiblog.queue_publish' => true]);
        Queue::fake();

        $article = app(ArticleService::class)->createDraft($this->blog);
        $this->actingAs($this->admin());
        $payload = $this->chooseCategory($this->blog) + ['action' => 'publish', 'title' => 'Doble', 'body_html' => '<p>x</p>', 'published_at' => '2026-11-10T09:00'];

        $this->put(route('articles.update', [$this->blog, $article]), $payload + ['version' => $article->version]);
        $this->put(route('articles.update', [$this->blog, $article]), $payload + ['version' => $article->fresh()->version])
            ->assertSessionHas('error');

        $this->delete(route('articles.destroy', [$this->blog, $article]))->assertSessionHas('error');
        $this->assertSame(1, Deployment::where('article_id', $article->id)->count());
    }

    public function test_queue_mode_reports_validation_errors_immediately(): void
    {
        config(['publiblog.queue_publish' => true]);
        Queue::fake();

        $article = app(ArticleService::class)->createDraft($this->blog);

        $this->actingAs($this->admin())->put(route('articles.update', [$this->blog, $article]), [
            'action' => 'publish', 'title' => 'Sin cuerpo', 'body_html' => '<p>x</p>', 'published_at' => '2026-11-10T09:00',
            'version' => $article->version, 'slug' => 'reservado',
        ]);

        // Un artículo incompleto no llega a la cola.
        $article2 = app(ArticleService::class)->createDraft($this->blog);
        app(ArticleService::class)->save($article2, ['title' => 'Solo título', 'published_at' => '2026-11-10T09:00', 'version' => $article2->version]);

        $this->post(route('articles.retry', [$this->blog, $article2]))->assertSessionHas('error');
        Queue::assertNotPushed(PublishArticle::class, fn ($j) => $j->articleId === $article2->id);
    }

    public function test_unpublish_in_queue_mode_is_queued_and_then_applied(): void
    {
        $a = $this->publishArticle('Para borrar', 1);
        config(['publiblog.queue_publish' => true]);
        Queue::fake();

        $this->actingAs($this->admin())->delete(route('articles.destroy', [$this->blog, $a]))
            ->assertRedirect(route('articles.edit', [$this->blog, $a]))->assertSessionHas('status');

        $deployment = Deployment::where('article_id', $a->id)->where('action', 'unpublish')->sole();
        $this->assertSame(Deployment::STATUS_QUEUED, $deployment->status);
        $this->assertFileExists($this->remoteDir.'/'.$a->remote_path); // aún no se borra

        (new UnpublishArticle($a->id, null, $deployment->id))->handle(app(\App\Services\ArticlePublisher::class));

        $this->assertFileDoesNotExist($this->remoteDir.'/'.$a->remote_path);
        $this->assertSame(Deployment::STATUS_OK, $deployment->fresh()->status);
    }

    public function test_sync_mode_is_unchanged(): void
    {
        config(['publiblog.queue_publish' => false]);
        $a = $this->publishArticle('Directo', 1);

        $this->assertSame(Deployment::STATUS_OK, $a->lastDeployment->status);
        $this->assertFileExists($this->remoteDir.'/'.$a->remote_path);
    }

    public function test_failed_queue_job_leaves_a_retryable_error(): void
    {
        $article = app(ArticleService::class)->createDraft($this->blog);
        $d = Deployment::create(['blog_id' => $this->blog->id, 'article_id' => $article->id, 'action' => 'publish', 'status' => 'running']);

        (new PublishArticle($article->id, null, $d->id))->failed(new \RuntimeException('boom'));

        $this->assertSame(Deployment::STATUS_ERROR, $d->fresh()->status);
        $this->assertSame(Article::STATUS_ERROR, $article->fresh()->status);
    }

    // ───────── limpieza y planificador ─────────

    public function test_reaper_marks_stuck_operations_as_errors_but_not_recent_ones(): void
    {
        $article = app(ArticleService::class)->createDraft($this->blog);

        $stuck = Deployment::create(['blog_id' => $this->blog->id, 'article_id' => $article->id, 'action' => 'publish', 'status' => 'running']);
        $article->forceFill(['last_deployment_id' => $stuck->id])->save();
        \DB::table('deployments')->where('id', $stuck->id)->update(['updated_at' => now()->subHour()]);

        $recent = Deployment::create(['blog_id' => $this->blog->id, 'article_id' => null, 'action' => 'regenerate', 'status' => 'queued']);

        $this->artisan('deployments:reap')->assertSuccessful();

        $this->assertSame(Deployment::STATUS_ERROR, $stuck->fresh()->status);
        $this->assertSame(Article::STATUS_ERROR, $article->fresh()->status);
        $this->assertSame(Deployment::STATUS_QUEUED, $recent->fresh()->status);
    }

    public function test_scheduler_has_the_queue_worker_and_the_reaper(): void
    {
        $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' | ');

        $this->assertStringContainsString('queue:work --stop-when-empty', $commands);
        $this->assertStringContainsString('deployments:reap', $commands);
    }
}
