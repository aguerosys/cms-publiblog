<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Blog;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\InteractsWithBlogs;
use Tests\TestCase;

class PanelTest extends TestCase
{
    use InteractsWithBlogs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBlogs();
    }

    protected function tearDown(): void
    {
        $this->tearDownBlogs();
        parent::tearDown();
    }

    public function test_panel_requires_login(): void
    {
        $blog = $this->makeBlog();

        $this->get('/blogs')->assertRedirect('/login');
        $this->get(route('blogs.show', $blog))->assertRedirect('/login');
        $this->get(route('articles.index', $blog))->assertRedirect('/login');
        $this->post(route('articles.store', $blog))->assertRedirect('/login');
        $this->get(route('media.show', [$blog, 1]))->assertRedirect('/login');
    }

    public function test_login_works_and_wrong_password_fails(): void
    {
        $user = User::factory()->create(['email' => 'a@b.com', 'password' => 'secreto-123']);

        $this->post('/login', ['email' => 'a@b.com', 'password' => 'mal'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => 'a@b.com', 'password' => 'secreto-123'])->assertRedirect('/blogs');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_is_rate_limited_after_five_failures(): void
    {
        User::factory()->create(['email' => 'a@b.com', 'password' => 'secreto-123']);
        RateLimiter::clear('a@b.com|127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'a@b.com', 'password' => 'mal']);
        }

        // Incluso con la contraseña correcta, el sexto intento queda bloqueado.
        $response = $this->post('/login', ['email' => 'a@b.com', 'password' => 'secreto-123']);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Demasiados intentos', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_blog_secret_is_encrypted_and_never_shown(): void
    {
        $this->actingAs($this->admin())->post(route('blogs.store'), [
            'name' => 'Cliente B', 'domain' => 'cliente-b.com', 'base_url' => 'https://cliente-b.com/',
            'remote_dir' => 'blog', 'locale' => 'es', 'timezone' => 'Europe/Madrid', 'posts_per_page' => 12,
            'template' => 'demoblog', 'sftp_auth' => 'password', 'sftp_host' => 'sftp.cliente-b.com', 'sftp_port' => 22,
            'sftp_user' => 'blog', 'sftp_secret' => 'SuperSecretPassword!', 'verify_http' => 1,
        ])->assertRedirect();

        $blog = Blog::where('domain', 'cliente-b.com')->firstOrFail();

        $this->assertSame('SuperSecretPassword!', $blog->sftp_secret);
        $this->assertNotSame('SuperSecretPassword!', $blog->getRawOriginal('sftp_secret'));
        $this->assertSame('https://cliente-b.com', $blog->base_url);
        $this->assertSame('/blog/', $blog->remote_dir);
        $this->assertSame('pending', $blog->status);
        $this->assertStringNotContainsString('SuperSecretPassword!', json_encode($blog->toArray()));

        foreach ([route('blogs.show', $blog), route('blogs.edit', $blog), route('blogs.index')] as $url) {
            $this->get($url)->assertOk()->assertDontSee('SuperSecretPassword!');
        }

        // En blanco = conservar; con valor nuevo = reemplazar.
        $payload = [
            'name' => 'Cliente B', 'domain' => 'cliente-b.com', 'base_url' => 'https://cliente-b.com',
            'remote_dir' => '/blog/', 'locale' => 'es', 'timezone' => 'Europe/Madrid', 'posts_per_page' => 12,
            'template' => 'demoblog', 'sftp_auth' => 'password', 'sftp_host' => 'sftp.cliente-b.com', 'sftp_port' => 22,
            'sftp_user' => 'blog', 'sftp_secret' => '', 'verify_http' => 1,
        ];

        $this->put(route('blogs.update', $blog), $payload)->assertRedirect();
        $this->assertSame('SuperSecretPassword!', $blog->fresh()->sftp_secret);

        $this->put(route('blogs.update', $blog), ['sftp_secret' => 'otra-clave'] + $payload)->assertRedirect();
        $this->assertSame('otra-clave', $blog->fresh()->sftp_secret);
    }

    public function test_changing_server_resets_fingerprint(): void
    {
        $blog = $this->makeBlog([
            'sftp_auth' => 'password', 'sftp_host' => 'a.com', 'sftp_user' => 'u', 'sftp_secret' => 'x',
            'host_key_fingerprint' => 'sha256:'.str_repeat('A', 43), 'remote_dir' => '/blog/',
        ]);

        $this->actingAs($this->admin())->put(route('blogs.update', $blog), [
            'name' => 'Cliente A', 'domain' => 'cliente-a.com', 'base_url' => 'https://cliente-a.com', 'remote_dir' => '/blog/',
            'locale' => 'es', 'timezone' => 'Europe/Madrid', 'posts_per_page' => 12, 'template' => 'demoblog',
            'sftp_auth' => 'password', 'sftp_host' => 'otro-servidor.com', 'sftp_port' => 22, 'sftp_user' => 'u',
        ])->assertRedirect();

        $this->assertNull($blog->fresh()->host_key_fingerprint);
        $this->assertSame('pending', $blog->fresh()->status);
    }

    public function test_blog_validation_rejects_unknown_template_and_path_traversal(): void
    {
        $this->actingAs($this->admin())->post(route('blogs.store'), [
            'name' => 'X', 'domain' => 'x.com', 'base_url' => 'https://x.com', 'remote_dir' => '/blog/../etc',
            'locale' => 'es', 'timezone' => 'Europe/Madrid', 'posts_per_page' => 12, 'template' => '../hack',
            'sftp_auth' => 'password', 'sftp_host' => 'h', 'sftp_user' => 'u', 'sftp_secret' => 's',
        ])->assertSessionHasErrors(['remote_dir', 'template']);
    }

    public function test_local_transport_is_not_allowed_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->actingAs($this->admin())->withSession(['_token' => 't'])->post(route('blogs.store'), [
            '_token' => 't',
            'name' => 'X', 'domain' => 'x.com', 'base_url' => 'https://x.com', 'remote_dir' => '/tmp',
            'locale' => 'es', 'timezone' => 'Europe/Madrid', 'posts_per_page' => 12, 'template' => 'demoblog',
            'sftp_auth' => 'local',
        ])->assertSessionHasErrors('sftp_auth');
    }

    public function test_end_to_end_through_the_panel(): void
    {
        $user = $this->admin();
        $blog = $this->makeBlog(['verify_http' => true]);
        $this->fakeVerification();
        $this->actingAs($user);

        // Nuevo artículo: borrador con ID asignado.
        $this->post(route('articles.store', $blog))->assertRedirect();
        $article = Article::firstOrFail();
        $this->assertSame('000001', $article->public_id);

        $this->get(route('articles.edit', [$blog, $article]))->assertOk()->assertSee($blog->domain);

        // Imagen de 10 MB aprox. desde el editor.
        $upload = $this->jpegUpload(3200, 2400);
        $media = $this->postJson(route('media.store', $blog), ['article_id' => $article->id, 'image' => $upload])->assertOk();

        // Guardar borrador con la URL de vista previa dentro del cuerpo.
        $body = '<h2>Subtítulo</h2><ul><li>Uno</li></ul><p><a href="https://otro.com">enlace</a></p>'
            .'<table><tbody><tr><td>c</td></tr></tbody></table><img src="'.$media->json('url').'"><script>alert(1)</script>';

        $this->put(route('articles.update', [$blog, $article]), [
            'action' => 'save', 'version' => $article->version, 'title' => 'Artículo de prueba', 'slug' => '',
            'excerpt' => 'Extracto', 'body_html' => $body, 'featured_media_id' => $media->json('id'), 'featured_alt' => 'Portada',
            'published_at' => '2026-11-10T09:30', 'author_name' => 'Ana',
        ])->assertRedirect(route('articles.edit', [$blog, $article]))->assertSessionHas('status');

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('articulo-de-prueba', $article->slug);
        $this->assertSame([], $this->remoteFilesList()); // guardar borrador no sube nada

        // Reabrir: el cuerpo vuelve con la URL de vista previa y sin el script.
        $edit = $this->get(route('articles.edit', [$blog, $article]))->assertOk();
        $edit->assertSee($media->json('url'), false);
        $edit->assertDontSee('alert(1)', false);

        // Publicar.
        $this->put(route('articles.update', [$blog, $article]), [
            'action' => 'publish', 'version' => $article->version, 'title' => 'Artículo de prueba', 'slug' => 'articulo-de-prueba',
            'body_html' => $body,
            'featured_media_id' => $media->json('id'), 'published_at' => '2026-11-10T09:30',
        ])->assertRedirect()->assertSessionHas('status');

        $article->refresh();
        $this->assertSame('published', $article->status);

        $html = file_get_contents($this->remoteDir.'/000001-articulo-de-prueba.html');
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('/blogs/', $html); // ninguna URL del CMS en el HTML público
        $this->assertStringContainsString('rel="noopener"', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertFileExists($this->remoteDir.'/'.Media::first()->remote_path);
        $this->assertLessThanOrEqual(1600, Media::first()->width);

        // Eliminar.
        $this->delete(route('articles.destroy', [$blog, $article]))->assertRedirect(route('articles.index', $blog));
        $this->assertSoftDeleted('articles', ['id' => $article->id]);
        $this->assertFileDoesNotExist($this->remoteDir.'/000001-articulo-de-prueba.html');
    }

    private function remoteFilesList(): array
    {
        return array_values(array_diff(scandir($this->remoteDir), ['.', '..']));
    }

    public function test_publish_requires_complete_fields(): void
    {
        $blog = $this->makeBlog();
        $this->actingAs($this->admin())->post(route('articles.store', $blog));
        $article = Article::firstOrFail();

        $this->put(route('articles.update', [$blog, $article]), ['action' => 'publish', 'version' => $article->version])
            ->assertSessionHasErrors(['title', 'body_html', 'published_at']);
    }

    public function test_reserved_and_duplicate_slugs_are_rejected_in_the_form(): void
    {
        $blog = $this->makeBlog();
        $this->actingAs($this->admin());
        $this->post(route('articles.store', $blog));
        $this->post(route('articles.store', $blog));
        [$a, $b] = Article::orderBy('id')->get();

        $base = ['action' => 'save', 'title' => 'T', 'published_at' => '2026-11-10T09:30'];

        $this->put(route('articles.update', [$blog, $a]), $base + ['version' => $a->version, 'slug' => 'sitemap'])->assertSessionHasErrors('slug');
        $this->put(route('articles.update', [$blog, $a]), $base + ['version' => $a->version, 'slug' => 'mi-slug'])->assertSessionHasNoErrors();
        $this->put(route('articles.update', [$blog, $b]), $base + ['version' => $b->version, 'slug' => 'mi-slug'])->assertSessionHasErrors('slug');
    }

    public function test_concurrent_edit_shows_warning_and_force_overrides(): void
    {
        $blog = $this->makeBlog();
        $this->actingAs($this->admin())->post(route('articles.store', $blog));
        $article = Article::firstOrFail();
        $opened = $article->version;

        $this->put(route('articles.update', [$blog, $article]), ['action' => 'save', 'version' => $opened, 'title' => 'Persona A'])->assertSessionHas('status');

        $this->from(route('articles.edit', [$blog, $article]))
            ->put(route('articles.update', [$blog, $article]), ['action' => 'save', 'version' => $opened, 'title' => 'Persona B'])
            ->assertSessionHas('conflict')->assertSessionHas('error');
        $this->assertSame('Persona A', $article->fresh()->title);

        $this->put(route('articles.update', [$blog, $article]), ['action' => 'save', 'version' => $opened, 'title' => 'Persona B', 'force' => 1])
            ->assertSessionHas('status');
        $this->assertSame('Persona B', $article->fresh()->title);
    }

    public function test_articles_of_another_blog_are_not_reachable_through_this_one(): void
    {
        $a = $this->makeBlog();
        $b = $this->makeBlog(['domain' => 'otro.com', 'name' => 'Otro']);
        $this->actingAs($this->admin())->post(route('articles.store', $b));
        $article = Article::firstOrFail();

        $this->get(route('articles.edit', [$a, $article]))->assertNotFound();
    }

    public function test_article_listing_filters_by_status(): void
    {
        $blog = $this->makeBlog();
        Article::create(['blog_id' => $blog->id, 'public_id' => '000001', 'slug' => 'uno', 'title' => 'Artículo uno', 'status' => 'draft']);
        Article::create(['blog_id' => $blog->id, 'public_id' => '000002', 'slug' => 'dos', 'title' => 'Artículo dos', 'status' => 'published']);

        $this->actingAs($this->admin());
        $this->get(route('articles.index', $blog))->assertSee('Artículo uno')->assertSee('Artículo dos');
        $this->get(route('articles.index', [$blog, 'status' => 'published']))->assertDontSee('Artículo uno')->assertSee('Artículo dos');
    }

    public function test_local_connection_test_marks_blog_active(): void
    {
        $blog = $this->makeBlog(['status' => 'pending']);

        $this->actingAs($this->admin())->post(route('blogs.test-connection', $blog))->assertRedirect()->assertSessionHas('status');

        $this->assertSame('active', $blog->fresh()->status);
        $this->assertSame([], $this->remoteFilesList()); // el archivo de prueba se borra
    }

    public function test_ids_are_not_reused_after_concurrent_drafts(): void
    {
        $blog = $this->makeBlog();
        $this->actingAs($this->admin());

        $this->post(route('articles.store', $blog));
        $this->post(route('articles.store', $blog));

        $this->assertSame(['000001', '000002'], Article::orderBy('id')->pluck('public_id')->all());
        $this->assertSame(2, DB::table('blogs')->where('id', $blog->id)->value('last_public_id'));
    }
}
