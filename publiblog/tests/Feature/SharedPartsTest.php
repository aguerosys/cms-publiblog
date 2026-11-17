<?php

namespace Tests\Feature;

use App\Jobs\PublishArticle;
use App\Models\Article;
use App\Models\Blog;
use App\Models\Deployment;
use App\Models\User;
use App\Services\ArticleService;
use App\Services\BlogRegenerator;
use App\Services\BlogVerifier;
use App\Services\HtaccessManager;
use App\Services\SitePartials;
use App\Services\SsiManager;
use App\Services\TemplateValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InteractsWithBlogs;
use Tests\TestCase;

/** F3: cabecera y pie compartidos (SSI) — activación segura, regeneración rápida y vuelta atrás. */
class SharedPartsTest extends TestCase
{
    use InteractsWithBlogs, RefreshDatabase;

    private const TEMPLATE = 'ssitest';

    private Blog $blog;
    private string $templateDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBlogs();

        $this->templateDir = resource_path('views/blogs/'.self::TEMPLATE);
        File::deleteDirectory($this->templateDir);
        File::copyDirectory(resource_path('views/blogs/demoblog'), $this->templateDir);

        $this->blog = $this->makeBlog(['template' => self::TEMPLATE, 'verify_http' => true, 'posts_per_page' => 2]);
        $this->fakeVerification();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->templateDir);
        $this->artisan('view:clear');
        $this->tearDownBlogs();
        parent::tearDown();
    }

    private function editTemplate(string $file, string $from, string $to): void
    {
        $path = $this->templateDir.'/'.$file;
        $contents = File::get($path);
        $this->assertStringContainsString($from, $contents, "No se encontró «{$from}» en {$file}");
        File::put($path, str_replace($from, $to, $contents));
        touch($path, time() + 5);
        clearstatcache();
        $this->artisan('view:clear');
    }

    private function publishArticle(string $title, int $day): Article
    {
        $service = app(ArticleService::class);
        $article = $service->createDraft($this->blog->fresh());
        $service->save($article, $this->chooseCategory($this->blog) + [
            'title' => $title, 'body_html' => "<p>Cuerpo de {$title}</p>", 'published_at' => sprintf('2026-11-%02dT09:00', $day),
            'version' => $article->version,
        ]);
        PublishArticle::dispatchSync($article->id);

        return $article->fresh();
    }

    private function remote(string $path): string
    {
        return file_get_contents($this->remoteDir.'/'.$path);
    }

    private function regenerate(bool $force = false): Deployment
    {
        $regenerator = app(BlogRegenerator::class);

        return $regenerator->runAll($regenerator->start($this->blog->fresh(), null, $force));
    }

    /** Activa SSI y procesa la regeneración que deja en cola. */
    private function enableSsi(): array
    {
        // La regeneración queda en cola de base de datos (sin worker): se ejecuta a mano justo después.
        // (Queue::fake() también anularía el dispatchSync de las publicaciones siguientes.)
        config(['queue.default' => 'database']);
        DB::table('jobs')->delete();
        $result = app(SsiManager::class)->enable($this->blog->fresh());
        $this->assertSame($result['ok'] ? 1 : 0, DB::table('jobs')->count());

        if ($result['ok']) {
            $deployment = Deployment::where('action', 'regenerate')->latest('id')->first();
            $deployment = app(BlogRegenerator::class)->runAll($deployment);
            $this->assertSame('ok', $deployment->status, (string) $deployment->error_message);
        }

        return $result;
    }

    // ───────── modo por defecto ─────────

    public function test_by_default_the_header_and_footer_are_copied_into_every_page(): void
    {
        $article = $this->publishArticle('Uno', 1);

        $html = $this->remote($article->remote_path);
        $this->assertStringContainsString('class="site-header"', $html);
        $this->assertStringContainsString('class="site-footer"', $html);
        $this->assertStringNotContainsString('<!--#include', $html);
        $this->assertStringNotContainsString('Options +IncludesNoExec', $this->remote('.htaccess'));
        $this->assertDirectoryDoesNotExist($this->remoteDir.'/_inc');
    }

    public function test_directives_render_the_same_html_as_the_old_include(): void
    {
        $withDirective = view('blogs.'.self::TEMPLATE.'.partials.header', ['blog' => app(\App\Services\ArticleRenderer::class)->blogData($this->blog)])->render();
        $article = $this->publishArticle('Uno', 1);

        $this->assertStringContainsString(trim($withDirective), $this->remote($article->remote_path));
    }

    // ───────── activar ─────────

    public function test_enabling_checks_the_server_then_switches_every_page_to_includes(): void
    {
        $a = $this->publishArticle('Uno', 1);
        $b = $this->publishArticle('Dos', 2);
        $this->assertStringContainsString('class="site-header"', $this->remote($a->remote_path));

        $result = $this->enableSsi();

        $this->assertTrue($result['ok'], $result['message']);
        $blog = $this->blog->fresh();
        $this->assertTrue($blog->ssi_enabled);
        $this->assertNotNull($blog->ssi_verified_at);

        // Fragmentos compartidos y directivas en el .htaccess.
        $this->assertStringContainsString('class="site-header"', $this->remote('_inc/header.html'));
        $this->assertStringContainsString('class="site-footer"', $this->remote('_inc/footer.html'));
        $ht = $this->remote('.htaccess');
        $this->assertStringContainsString('Options +IncludesNoExec', $ht);
        $this->assertStringContainsString('AddOutputFilter INCLUDES .html', $ht);

        // Las páginas ya no llevan la cabecera copiada, sino la inclusión (artículos y listados).
        foreach ([$a, $b] as $article) {
            $html = $this->remote($article->fresh()->remote_path);
            $this->assertStringNotContainsString('class="site-header"', $html);
            $this->assertSame(1, substr_count($html, '<!--#include virtual="/blog/_inc/header.html" -->'));
            $this->assertSame(1, substr_count($html, '<!--#include virtual="/blog/_inc/footer.html" -->'));
            $this->assertStringContainsString('<meta name="blog-article-id"', $html); // el <head> sigue siendo propio de cada página
        }

        $this->assertStringContainsString('<!--#include virtual="/blog/_inc/header.html" -->', $this->remote('index.html'));

        // Y el sitio entero sigue mostrándose completo (como lo sirve Apache con SSI).
        $served = $this->serve($a->fresh()->remote_path);
        $this->assertStringContainsString('class="site-header"', $served);
        $this->assertStringNotContainsString('<!--#include', $served);

        // La carpeta de la sonda no queda en el servidor.
        $this->assertDirectoryDoesNotExist($this->remoteDir.'/_ssi_probe');
    }

    public function test_a_new_publication_in_ssi_mode_verifies_that_the_includes_run(): void
    {
        $this->publishArticle('Uno', 1);
        $this->assertTrue($this->enableSsi()['ok']);

        $second = $this->publishArticle('Dos', 2);

        $this->assertSame('ok', $second->lastDeployment->status, (string) $second->lastDeployment->error_message);
        $this->assertStringContainsString('<!--#include virtual="/blog/_inc/header.html" -->', $this->remote($second->remote_path));

        // Si el servidor deja de ejecutar SSI, publicar avisa en vez de dejar páginas sin cabecera.
        $this->serverRunsSsi = false;
        $third = $this->publishArticle('Tres', 3);

        $this->assertSame('error', $third->lastDeployment->status);
        $this->assertSame('verify', $third->lastDeployment->error_code);
        $this->assertStringContainsString('inclusiones SSI', $third->lastDeployment->error_message);
    }

    // ───────── el beneficio: cambiar cabecera o pie ─────────

    public function test_changing_the_footer_only_uploads_the_shared_file(): void
    {
        $a = $this->publishArticle('Uno', 1);
        $this->publishArticle('Dos', 2);
        $this->publishArticle('Tres', 3);
        $this->assertTrue($this->enableSsi()['ok']);

        $mtime = filemtime($this->remoteDir.'/'.$a->fresh()->remote_path);
        clearstatcache();

        $this->editTemplate('partials/footer.blade.php', '<footer class="site-footer">', '<footer class="site-footer" data-nuevo="SI">');
        $deployment = $this->regenerate();

        $this->assertSame('ok', $deployment->status, (string) $deployment->error_message);
        $this->assertSame(1, $deployment->files_count); // solo _inc/footer.html
        $this->assertStringContainsString('data-nuevo="SI"', $this->remote('_inc/footer.html'));
        $this->assertSame($mtime, filemtime($this->remoteDir.'/'.$a->fresh()->remote_path)); // ningún artículo se tocó

        // Visto a través del servidor, todas las páginas llevan ya el pie nuevo.
        $this->assertStringContainsString('data-nuevo="SI"', $this->serve($a->fresh()->remote_path));
    }

    // ───────── el servidor no lo permite ─────────

    public function test_enabling_fails_cleanly_when_the_server_does_not_run_includes(): void
    {
        $a = $this->publishArticle('Uno', 1);
        $before = $this->remote('.htaccess');
        $this->serverRunsSsi = false;

        $result = $this->enableSsi();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no ejecuta las inclusiones', $result['message']);
        $this->assertFalse($this->blog->fresh()->ssi_enabled);
        $this->assertSame($before, $this->remote('.htaccess')); // el .htaccess real no se tocó
        $this->assertStringContainsString('class="site-header"', $this->remote($a->remote_path));
        $this->assertDirectoryDoesNotExist($this->remoteDir.'/_ssi_probe');
        $this->assertDirectoryDoesNotExist($this->remoteDir.'/_inc');
    }

    public function test_enabling_explains_a_500_caused_by_options_not_being_allowed(): void
    {
        $this->publishArticle('Uno', 1);
        $before = $this->remote('.htaccess');
        $this->probeStatus = 500;

        $result = $this->enableSsi();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('AllowOverride Options', $result['message']);
        $this->assertFalse($this->blog->fresh()->ssi_enabled);
        $this->assertSame($before, $this->remote('.htaccess'));
        $this->assertDirectoryDoesNotExist($this->remoteDir.'/_ssi_probe'); // la carpeta de pruebas se borra aunque falle
    }

    public function test_enabling_reports_a_probe_that_cannot_be_read(): void
    {
        $this->probeStatus = 403;

        $result = $this->enableSsi();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('HTTP 403', $result['message']);
        $this->assertFalse($this->blog->fresh()->ssi_enabled);
    }

    public function test_enabling_is_refused_for_a_template_that_does_not_use_the_directives(): void
    {
        $this->editTemplate('article.blade.php', '@siteHeader', "@include('blogs.".self::TEMPLATE.".partials.header')");

        $this->assertFalse(SitePartials::templateSupports(self::TEMPLATE));
        $result = $this->enableSsi();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('@siteHeader', $result['message']);
        $this->assertFalse($this->blog->fresh()->ssi_enabled);
        $this->assertDirectoryDoesNotExist($this->remoteDir.'/_ssi_probe'); // ni se llegó a probar
    }

    public function test_enabling_waits_for_nothing_else_running(): void
    {
        $this->publishArticle('Uno', 1);
        $regenerator = app(BlogRegenerator::class);
        $regenerator->start($this->blog->fresh()); // una regeneración en cola

        $result = app(SsiManager::class)->enable($this->blog->fresh());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('regeneración en curso', $result['message']);
    }

    // ───────── desactivar ─────────

    public function test_disabling_brings_the_header_back_into_every_page_and_removes_the_shared_files(): void
    {
        $a = $this->publishArticle('Uno', 1);
        $this->assertTrue($this->enableSsi()['ok']);
        $this->assertFileExists($this->remoteDir.'/_inc/header.html');

        Queue::fake();
        $result = app(SsiManager::class)->disable($this->blog->fresh());
        $this->assertTrue($result['ok']);
        $deployment = Deployment::where('action', 'regenerate')->latest('id')->first();
        app(BlogRegenerator::class)->runAll($deployment);

        $this->assertFalse($this->blog->fresh()->ssi_enabled);
        $html = $this->remote($a->fresh()->remote_path);
        $this->assertStringContainsString('class="site-header"', $html);
        $this->assertStringNotContainsString('<!--#include', $html);
        $this->assertDirectoryDoesNotExist($this->remoteDir.'/_inc');
        $this->assertStringNotContainsString('Options +IncludesNoExec', $this->remote('.htaccess'));
        $this->assertStringNotContainsString('INCLUDES', $this->remote('.htaccess'));
    }

    public function test_the_htaccess_outside_the_managed_block_is_never_touched_by_ssi(): void
    {
        file_put_contents($this->remoteDir.'/.htaccess', "# del cliente\nOptions -Indexes\n");
        $this->publishArticle('Uno', 1);
        $this->assertTrue($this->enableSsi()['ok']);

        $ht = $this->remote('.htaccess');
        $this->assertStringContainsString("# del cliente\nOptions -Indexes", $ht);
        $this->assertSame(1, substr_count($ht, HtaccessManager::BEGIN));
    }

    // ───────── comprobación del servidor ─────────

    public function test_verify_flags_a_missing_shared_file_and_a_lost_directive(): void
    {
        $a = $this->publishArticle('Uno', 1);
        $this->assertTrue($this->enableSsi()['ok']);

        $verifier = app(BlogVerifier::class);
        $verifier->runAll($verifier->start($this->blog->fresh()));
        $this->assertSame('ok', $a->fresh()->remote_state);

        unlink($this->remoteDir.'/_inc/footer.html');
        file_put_contents($this->remoteDir.'/.htaccess', str_replace('AddOutputFilter INCLUDES .html', '', $this->remote('.htaccess')));

        $d = $verifier->runAll($verifier->start($this->blog->fresh()));

        $this->assertSame('no_rule', $a->fresh()->remote_state); // falta la directiva del bloque
        $activity = \App\Models\Activity::where('event', 'blog.verified')->latest('id')->first();
        $this->assertStringContainsString('footer.html', json_encode($activity->details, JSON_UNESCAPED_UNICODE));
    }

    // ───────── validador de plantillas ─────────

    public function test_validator_accepts_a_template_with_shared_parts(): void
    {
        $this->assertSame([], app(TemplateValidator::class)->validate(self::TEMPLATE));
    }

    public function test_validator_rejects_a_header_that_uses_the_article_or_is_a_full_document(): void
    {
        $this->editTemplate('partials/header.blade.php', '<header class="site-header">', '<header class="site-header" title="{{ $article->title }}">');
        $errors = app(TemplateValidator::class)->validate(self::TEMPLATE);
        $this->assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, '[parcial header]')));

        File::put($this->templateDir.'/partials/header.blade.php', "<body><header class=\"site-header\"></header></body>");
        $this->artisan('view:clear');
        $errors = app(TemplateValidator::class)->validate(self::TEMPLATE);
        $this->assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'no debe llevar <html>')));
    }

    public function test_validator_flags_a_half_converted_template(): void
    {
        $this->editTemplate('index.blade.php', '@siteFooter', "@include('blogs.".self::TEMPLATE.".partials.footer')");

        $errors = app(TemplateValidator::class)->validate(self::TEMPLATE);

        $this->assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, '[cabecera y pie]')));
    }

    public function test_a_template_with_no_directives_is_still_valid_it_just_cannot_share_parts(): void
    {
        $this->editTemplate('article.blade.php', '@siteHeader', "@include('blogs.".self::TEMPLATE.".partials.header')");
        $this->editTemplate('article.blade.php', '@siteFooter', "@include('blogs.".self::TEMPLATE.".partials.footer')");
        $this->editTemplate('index.blade.php', '@siteHeader', "@include('blogs.".self::TEMPLATE.".partials.header')");
        $this->editTemplate('index.blade.php', '@siteFooter', "@include('blogs.".self::TEMPLATE.".partials.footer')");

        $this->assertSame([], app(TemplateValidator::class)->validate(self::TEMPLATE));
        $this->assertFalse(SitePartials::templateSupports(self::TEMPLATE));
    }

    // ───────── panel ─────────

    public function test_only_a_superadmin_can_switch_ssi_and_the_panel_shows_the_state(): void
    {
        $this->publishArticle('Uno', 1);
        Queue::fake();

        $editor = User::factory()->create(['role' => User::ROLE_BLOG_USER]);
        $editor->blogs()->attach($this->blog->id, ['role' => 'editor']);

        $this->actingAs($editor)->post(route('blogs.ssi.enable', $this->blog))->assertForbidden();
        $this->assertFalse($this->blog->fresh()->ssi_enabled);

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('blogs.show', $this->blog))->assertOk()
            ->assertSee('Cabecera y pie compartidos')->assertSee('Comprobar el servidor y activar');

        $this->actingAs($admin)->post(route('blogs.ssi.enable', $this->blog))
            ->assertRedirect(route('blogs.show', $this->blog))->assertSessionHas('status');
        $this->assertTrue($this->blog->fresh()->ssi_enabled);
        Queue::assertPushed(\App\Jobs\RegenerateBlog::class);

        $this->actingAs($admin)->get(route('blogs.show', $this->blog))->assertSee('Activado')->assertSee('Desactivar');
        $this->assertDatabaseHas('activity_log', ['event' => 'blog.ssi_enabled']);

        $this->actingAs($admin)->post(route('blogs.ssi.disable', $this->blog))->assertSessionHas('error'); // la regeneración sigue en cola
    }

    public function test_the_panel_shows_an_error_when_the_server_refuses(): void
    {
        $this->publishArticle('Uno', 1);
        Queue::fake();
        $this->serverRunsSsi = false;

        $this->actingAs($this->admin())->post(route('blogs.ssi.enable', $this->blog))
            ->assertRedirect(route('blogs.show', $this->blog))->assertSessionHas('error');

        $this->assertFalse($this->blog->fresh()->ssi_enabled);
        $this->assertDatabaseHas('activity_log', ['event' => 'blog.ssi_failed']);
        Queue::assertNothingPushed();
    }
}
