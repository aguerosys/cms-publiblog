<?php

namespace Tests\Feature;

use App\Jobs\PublishArticle;
use App\Models\Activity;
use App\Models\Article;
use App\Models\Blog;
use App\Models\Deployment;
use App\Models\Media;
use App\Models\User;
use App\Services\ArticleService;
use App\Services\BlogRegenerator;
use App\Services\BlogVerifier;
use App\Services\MediaCleaner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithBlogs;
use Tests\TestCase;

/** F2-6: verificación del HTML remoto contra published_hash y limpieza de imágenes huérfanas. */
class VerifyAndCleanTest extends TestCase
{
    use InteractsWithBlogs, RefreshDatabase;

    private Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBlogs();
        $this->blog = $this->makeBlog(['name' => 'Cliente A', 'domain' => 'a.com', 'base_url' => 'https://a.com']);
        $this->fakeVerification();
    }

    protected function tearDown(): void
    {
        $this->tearDownBlogs();
        parent::tearDown();
    }

    private function publish(string $title, int $day, string $body = null, bool $withImage = false): Article
    {
        $service = app(ArticleService::class);
        $article = $service->createDraft($this->blog);
        $media = null;

        if ($withImage) {
            $media = app(\App\Services\MediaService::class)->store($this->blog, $article, $this->jpegUpload(900, 600));
            $body = '<p>Con imagen</p><p><img src="/blog/'.$media->remote_path.'" alt="x"></p>';
        }

        $service->save($article, $this->chooseCategory($this->blog) + [
            'title' => $title, 'body_html' => $body ?? "<p>Cuerpo de {$title}</p>",
            'published_at' => sprintf('2026-11-%02dT09:00', $day), 'version' => $article->version,
        ]);
        PublishArticle::dispatchSync($article->id);

        return $article->fresh();
    }

    private function verify(): Deployment
    {
        $verifier = app(BlogVerifier::class);

        return $verifier->runAll($verifier->start($this->blog->fresh()));
    }

    // ───────── verificación ─────────

    public function test_everything_matches_right_after_publishing(): void
    {
        $a = $this->publish('Uno', 1);
        $b = $this->publish('Dos', 2, withImage: true);

        $d = $this->verify();

        $this->assertSame(Deployment::STATUS_OK, $d->status);
        $this->assertSame(2, $d->done_items);
        $this->assertSame(0, $d->failed_items);
        $this->assertSame('ok', $a->fresh()->remote_state);
        $this->assertSame('ok', $b->fresh()->remote_state);
        $this->assertNotNull($a->fresh()->remote_checked_at);
    }

    public function test_hand_edited_file_is_reported_as_modified(): void
    {
        $a = $this->publish('Uno', 1);
        file_put_contents($this->remoteDir.'/'.$a->remote_path, '<html>alguien lo cambió a mano</html>');

        $d = $this->verify();

        $this->assertSame(1, $d->failed_items);
        $this->assertSame('modified', $a->fresh()->remote_state);
    }

    public function test_deleted_file_is_reported_as_missing(): void
    {
        $a = $this->publish('Uno', 1);
        unlink($this->remoteDir.'/'.$a->remote_path);

        $this->verify();

        $this->assertSame('missing', $a->fresh()->remote_state);
    }

    public function test_missing_image_is_reported(): void
    {
        $a = $this->publish('Con foto', 1, withImage: true);
        unlink($this->remoteDir.'/'.Media::sole()->remote_path);

        $this->verify();

        $this->assertSame('missing_media', $a->fresh()->remote_state);
    }

    public function test_lost_htaccess_rule_is_reported(): void
    {
        $a = $this->publish('Uno', 1);
        $b = $this->publish('Dos', 2);

        $this->verify();
        $this->assertSame('ok', $a->fresh()->remote_state);

        // Sin la regla genérica ningún artículo se serviría: se avisa en todos.
        $ht = file_get_contents($this->remoteDir.'/.htaccess');
        file_put_contents($this->remoteDir.'/.htaccess', str_replace('RewriteRule ^(.+)$ $1.html [END]', '', $ht));

        $this->verify();

        $this->assertSame('no_rule', $a->fresh()->remote_state);
        $this->assertSame('no_rule', $b->fresh()->remote_state);
    }

    public function test_whole_site_lost_flags_every_article(): void
    {
        $this->publish('Uno', 1);
        $this->publish('Dos', 2);
        File::deleteDirectory($this->remoteDir);
        File::ensureDirectoryExists($this->remoteDir);

        $d = $this->verify();

        $this->assertSame(2, $d->failed_items);
        $this->assertSame(2, Article::where('remote_state', 'missing')->count());
    }

    public function test_listing_files_changed_outside_the_cms_are_recorded(): void
    {
        $this->publish('Uno', 1);
        file_put_contents($this->remoteDir.'/index.html', 'portada rota');
        unlink($this->remoteDir.'/sitemap.xml');

        $this->verify();

        $details = Activity::withoutGlobalScopes()->where('event', 'blog.verified')->latest('id')->first()->details;
        $this->assertCount(2, $details['listing']);
        $this->assertStringContainsString('index.html', $details['listing'][0]);
        $this->assertStringContainsString('sitemap.xml: falta', $details['listing'][1]);
    }

    public function test_verification_only_reads_the_server(): void
    {
        $a = $this->publish('Uno', 1);
        file_put_contents($this->remoteDir.'/'.$a->remote_path, 'cambiado');
        $before = $this->snapshot();

        $this->verify();

        $this->assertSame($before, $this->snapshot(), 'la verificación no debe escribir ni borrar nada en el servidor');
    }

    private function snapshot(): array
    {
        $out = [];
        foreach (File::allFiles($this->remoteDir, true) as $file) {
            $out[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
        }
        ksort($out);

        return $out;
    }

    public function test_connection_problems_end_the_verification_with_an_error(): void
    {
        $this->publish('Uno', 1);
        $this->blog->update(['status' => Blog::STATUS_HOSTKEY_CHANGED]);

        $d = $this->verify();

        $this->assertSame(Deployment::STATUS_ERROR, $d->status);
        $this->assertSame('blocked', $d->error_code);
    }

    public function test_verification_works_in_batches(): void
    {
        config(['publiblog.regenerate_batch' => 2]);
        foreach (range(1, 5) as $i) {
            $this->publish("Artículo {$i}", $i);
        }

        $d = $this->verify();

        $this->assertSame(5, $d->done_items);
        $this->assertSame(Deployment::STATUS_OK, $d->status);
    }

    public function test_republishing_clears_the_difference(): void
    {
        $a = $this->publish('Uno', 1);
        unlink($this->remoteDir.'/'.$a->remote_path);
        $this->verify();
        $this->assertSame('missing', $a->fresh()->remote_state);

        PublishArticle::dispatchSync($a->id);

        $this->assertSame('ok', $a->fresh()->remote_state);
        $this->assertFileExists($this->remoteDir.'/'.$a->remote_path);
    }

    public function test_fixing_differences_only_reuploads_the_affected_articles(): void
    {
        $a = $this->publish('Uno', 1);
        $b = $this->publish('Dos', 2);
        $c = $this->publish('Tres', 3);
        unlink($this->remoteDir.'/'.$b->remote_path);
        file_put_contents($this->remoteDir.'/'.$c->remote_path, 'roto');
        $this->verify();

        $okMtime = filemtime($this->remoteDir.'/'.$a->remote_path);
        clearstatcache();

        $regenerator = app(BlogRegenerator::class);
        $deployment = $regenerator->start($this->blog->fresh(), null, onlyProblems: true);
        $this->assertSame(2, $deployment->total_items);
        $this->assertSame('problems', $deployment->scope);

        $deployment = $regenerator->runAll($deployment);

        $this->assertSame(Deployment::STATUS_OK, $deployment->status);
        $this->assertSame(2, $deployment->done_items);
        $this->assertFileExists($this->remoteDir.'/'.$b->remote_path);
        $this->assertStringContainsString('Cuerpo de Tres', file_get_contents($this->remoteDir.'/'.$c->remote_path));
        $this->assertSame($okMtime, filemtime($this->remoteDir.'/'.$a->remote_path));

        $again = $this->verify();
        $this->assertSame(0, $again->failed_items);
    }

    // ───────── panel y comando ─────────

    public function test_panel_runs_the_check_and_lists_the_differences(): void
    {
        $a = $this->publish('Se estropea', 1);
        $this->publish('Sigue bien', 2);
        file_put_contents($this->remoteDir.'/'.$a->remote_path, 'cambiado');

        $this->actingAs($this->admin());
        $this->post(route('blogs.verify', $this->blog))->assertRedirect(route('blogs.show', $this->blog));

        $this->get(route('blogs.show', $this->blog))->assertOk()
            ->assertSee('Última comprobación')
            ->assertSee('1 con diferencias')
            ->assertSee('Se estropea')
            ->assertSee('Distinto del publicado')
            ->assertSee('Corregir diferencias');

        $this->post(route('blogs.regenerate', $this->blog), ['only_problems' => 1]);
        $this->get(route('blogs.show', $this->blog))->assertSee('todo coincide', false)->assertDontSee('Corregir diferencias');
    }

    public function test_article_list_shows_the_difference_badge(): void
    {
        $a = $this->publish('Con aviso', 1);
        unlink($this->remoteDir.'/'.$a->remote_path);
        $this->verify();

        $this->actingAs($this->admin())->get(route('articles.index', $this->blog))
            ->assertOk()->assertSee('Falta en el servidor');
    }

    public function test_blog_users_cannot_start_checks_or_see_the_server_card(): void
    {
        $user = User::factory()->create(['role' => 'blog_user']);
        $user->blogs()->attach($this->blog->id, ['role' => 'editor']);

        $this->actingAs($user)->post(route('blogs.verify', $this->blog))->assertForbidden();
        $this->get(route('blogs.show', $this->blog))->assertDontSee('Comprobar el servidor');
    }

    public function test_command_lists_the_differences(): void
    {
        $a = $this->publish('Uno', 1);
        $this->publish('Dos', 2);

        $this->artisan('blog:verify', ['blog' => $this->blog->id])->expectsOutputToContain('todo coincide')->assertSuccessful();

        unlink($this->remoteDir.'/'.$a->remote_path);

        $this->artisan('blog:verify', ['blog' => $this->blog->id])
            ->expectsOutputToContain('1 de 2 artículos con diferencias')
            ->expectsOutputToContain('falta el archivo')
            ->assertFailed();
    }

    public function test_command_without_arguments_only_checks_ready_blogs(): void
    {
        $this->publish('Uno', 1);
        $this->makeBlog(['name' => 'Roto', 'domain' => 'roto.com', 'base_url' => 'https://roto.com', 'status' => Blog::STATUS_HOSTKEY_CHANGED, 'remote_dir' => $this->remoteDir.'-x']);

        $this->artisan('blog:verify')->expectsOutputToContain('Cliente A')->doesntExpectOutputToContain('Roto')->assertSuccessful();
    }

    // ───────── imágenes huérfanas ─────────

    private function orphanMedia(Article $article, int $ageDays, bool $uploaded = false): Media
    {
        $media = app(\App\Services\MediaService::class)->store($this->blog, $article, $this->jpegUpload(500, 400));
        DB::table('media')->where('id', $media->id)->update(['created_at' => now()->subDays($ageDays)]);

        if ($uploaded) {
            File::ensureDirectoryExists(dirname($this->remoteDir.'/'.$media->remote_path));
            copy(Storage::disk('blogs')->path($media->local_path), $this->remoteDir.'/'.$media->remote_path);
            $media->forceFill(['uploaded_at' => now()])->save();
        }

        return $media->fresh();
    }

    public function test_unreferenced_old_images_are_removed_locally_and_on_the_server(): void
    {
        $article = $this->publish('Uno', 1);
        $orphan = $this->orphanMedia($article, 30, uploaded: true);
        $draftOnly = $this->orphanMedia($article, 30);

        $r = app(MediaCleaner::class)->clean($this->blog);

        $this->assertSame(2, $r['rows']);
        $this->assertSame(1, $r['remote']);
        $this->assertSame(0, Media::count());
        $this->assertFalse(Storage::disk('blogs')->exists($orphan->local_path));
        $this->assertFileDoesNotExist($this->remoteDir.'/'.$orphan->remote_path);
        $this->assertGreaterThan(0, $r['bytes']);
        $this->assertSame(1, Activity::withoutGlobalScopes()->where('event', 'blog.media_cleaned')->count());
    }

    public function test_recent_images_are_kept_during_the_grace_period(): void
    {
        $article = $this->publish('Uno', 1);
        $recent = $this->orphanMedia($article, 2);

        $r = app(MediaCleaner::class)->clean($this->blog);

        $this->assertSame(0, $r['rows']);
        $this->assertNotNull(Media::find($recent->id));
        $this->assertTrue(Storage::disk('blogs')->exists($recent->local_path));
    }

    public function test_images_used_by_the_draft_the_published_copy_or_another_article_are_kept(): void
    {
        // Usada en el cuerpo publicado.
        $published = $this->publish('Publicado', 1, withImage: true);
        DB::table('media')->update(['created_at' => now()->subDays(60)]);
        $usedByPublished = Media::sole();

        // El borrador la quita, pero lo publicado todavía la usa.
        app(ArticleService::class)->save($published, ['title' => 'Publicado', 'body_html' => '<p>sin imagen</p>', 'published_at' => '2026-11-01T09:00', 'version' => $published->version]);

        // Otra imagen que solo usa el borrador de otro artículo.
        $draft = app(ArticleService::class)->createDraft($this->blog);
        $draftImage = $this->orphanMedia($draft, 60);
        app(ArticleService::class)->save($draft, ['title' => 'Borrador', 'body_html' => '<p><img src="/blog/'.$draftImage->remote_path.'"></p>', 'published_at' => '2026-11-02T09:00', 'version' => $draft->version]);

        // Una imagen de un artículo que otro artículo reutiliza.
        $shared = $this->orphanMedia($draft, 60);
        $other = $this->publish('Reutiliza', 3, '<p><img src="/blog/'.$shared->remote_path.'"></p>');

        $r = app(MediaCleaner::class)->clean($this->blog);

        $this->assertSame(0, $r['rows']);
        $this->assertSame(3, Media::count());
        $this->assertNotNull(Media::find($usedByPublished->id));
        $this->assertNotNull($other);
    }

    public function test_stray_files_without_a_database_row_are_removed_after_the_grace_period(): void
    {
        $old = "{$this->blog->id}/media/2026/suelto-viejo.jpg";
        $new = "{$this->blog->id}/media/2026/suelto-nuevo.jpg";
        Storage::disk('blogs')->put($old, 'x');
        Storage::disk('blogs')->put($new, 'x');
        touch(Storage::disk('blogs')->path($old), time() - 40 * 86400);

        $r = app(MediaCleaner::class)->clean($this->blog);

        $this->assertSame(1, $r['files']);
        $this->assertFalse(Storage::disk('blogs')->exists($old));
        $this->assertTrue(Storage::disk('blogs')->exists($new));
    }

    public function test_dry_run_changes_nothing(): void
    {
        $article = $this->publish('Uno', 1);
        $orphan = $this->orphanMedia($article, 30, uploaded: true);

        $r = app(MediaCleaner::class)->clean($this->blog, dryRun: true);

        $this->assertSame(1, $r['rows']);
        $this->assertNotNull(Media::find($orphan->id));
        $this->assertFileExists($this->remoteDir.'/'.$orphan->remote_path);
        $this->assertSame(0, Activity::withoutGlobalScopes()->where('event', 'blog.media_cleaned')->count());
    }

    public function test_server_problems_keep_uploaded_images_for_the_next_pass(): void
    {
        $article = $this->publish('Uno', 1);
        $uploaded = $this->orphanMedia($article, 30, uploaded: true);
        $local = $this->orphanMedia($article, 30);
        $this->blog->update(['status' => Blog::STATUS_HOSTKEY_CHANGED]);

        $r = app(MediaCleaner::class)->clean($this->blog->fresh());

        $this->assertNotNull($r['error']);
        $this->assertNotNull(Media::find($uploaded->id), 'sin poder borrar en el servidor no se pierde el registro');
        $this->assertNull(Media::find($local->id), 'la que nunca se subió sí se puede limpiar');
        $this->assertFileExists($this->remoteDir.'/'.$uploaded->remote_path);
    }

    public function test_local_only_mode_never_touches_the_server(): void
    {
        $article = $this->publish('Uno', 1);
        $uploaded = $this->orphanMedia($article, 30, uploaded: true);

        $r = app(MediaCleaner::class)->clean($this->blog, includeRemote: false);

        $this->assertSame(0, $r['rows']);
        $this->assertNotNull(Media::find($uploaded->id));
        $this->assertFileExists($this->remoteDir.'/'.$uploaded->remote_path);
    }

    public function test_command_reports_and_cleans(): void
    {
        $article = $this->publish('Uno', 1);
        $this->orphanMedia($article, 30);

        $this->artisan('blog:clean-media', ['--dry-run' => true])->expectsOutputToContain('1 imágenes')->assertSuccessful();
        $this->assertSame(1, Media::count());

        $this->artisan('blog:clean-media', ['--days' => 14])->assertSuccessful();
        $this->assertSame(0, Media::count());

        $this->artisan('blog:clean-media', ['blog' => 9999])->assertFailed();
    }

    public function test_scheduler_includes_the_cleanup(): void
    {
        $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' | ');

        $this->assertStringContainsString('blog:clean-media', $commands);
    }
}
