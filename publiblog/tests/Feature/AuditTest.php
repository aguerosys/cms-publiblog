<?php

namespace Tests\Feature;

use App\Jobs\PublishArticle;
use App\Jobs\UnpublishArticle;
use App\Models\Article;
use App\Models\Blog;
use App\Models\Media;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\ArticleIndexer;
use App\Services\ArticleService;
use App\Services\MediaService;
use App\Services\Remote\RemoteFilesystemFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use Tests\Support\InteractsWithBlogs;
use Tests\TestCase;

/** Huecos detectados al auditar la fase 1 y 1b contra el plan. */
class AuditTest extends TestCase
{
    use InteractsWithBlogs, RefreshDatabase;

    private Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBlogs();
        $this->blog = $this->makeBlog(['verify_http' => true]);
        $this->fakeVerification();
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        $this->tearDownBlogs();
        parent::tearDown();
    }

    private function article(string $title = 'Artículo de prueba', int $day = 10, bool $image = false): Article
    {
        $service = app(ArticleService::class);
        $article = $service->createDraft($this->blog);
        $body = '<p>Cuerpo</p>';
        $featured = null;

        if ($image) {
            $media = app(MediaService::class)->store($this->blog, $article, $this->jpegUpload(1800, 1200));
            $body .= '<img src="'.$media->previewUrl().'" alt="x">';
            $featured = $media->id;
        }

        return $service->save($article, $this->chooseCategory($this->blog) + [
            'title' => $title, 'body_html' => $body, 'featured_media_id' => $featured,
            'published_at' => sprintf('2026-11-%02dT09:00', $day), 'version' => $article->version,
        ]);
    }

    private function failWrites(string $pattern, string $message = 'Permission denied'): void
    {
        $dir = $this->remoteDir;

        $this->app->bind(RemoteFilesystemFactory::class, fn () => new class($dir, $pattern, $message) extends RemoteFilesystemFactory {
            public function __construct(private string $dir, private string $pattern, private string $message)
            {
            }

            public function make(Blog $blog): FilesystemOperator
            {
                return new Filesystem(new class($this->dir, $this->pattern, $this->message) extends LocalFilesystemAdapter {
                    public function __construct(string $dir, private string $pattern, private string $message)
                    {
                        parent::__construct($dir);
                    }

                    public function write(string $path, string $contents, \League\Flysystem\Config $config): void
                    {
                        if (preg_match($this->pattern, $path)) {
                            throw UnableToWriteFile::atLocation($path, $this->message);
                        }
                        parent::write($path, $contents, $config);
                    }
                });
            }
        });
    }

    private function restoreRemote(): void
    {
        $this->app->bind(RemoteFilesystemFactory::class, fn () => new RemoteFilesystemFactory());
    }

    public function test_failed_publication_records_the_files_already_uploaded(): void
    {
        $article = $this->article(image: true);
        $this->failWrites('/\.htaccess/'); // imagen + HTML suben; el .htaccess falla

        PublishArticle::dispatchSync($article->id);
        $deployment = $article->fresh()->lastDeployment;

        $this->assertSame('error', $deployment->status);
        $this->assertSame('permission', $deployment->error_code);
        $this->assertSame(2, $deployment->files_count);
    }

    public function test_retry_after_a_failed_delete_deletes_again_instead_of_republishing(): void
    {
        $article = $this->article();
        PublishArticle::dispatchSync($article->id);
        $this->assertFileExists($this->remoteDir.'/'.$article->fresh()->remote_path);

        $this->failWrites('/index\.html/'); // el listado se actualiza tras borrar el archivo del artículo
        UnpublishArticle::dispatchSync($article->id);

        $article->refresh();
        $this->assertSame('error', $article->status);
        $this->assertNull($article->deleted_at);
        $this->assertSame('unpublish', $article->lastDeployment->action);

        $this->restoreRemote();
        $this->actingAs($this->admin())
            ->post(route('articles.retry', [$this->blog, $article]))
            ->assertRedirect(route('articles.index', $this->blog));

        $this->assertSoftDeleted('articles', ['id' => $article->id]);
        $this->assertFileDoesNotExist($this->remoteDir.'/'.$article->remote_path);
    }

    public function test_sftp_blog_without_confirmed_fingerprint_never_connects(): void
    {
        $blog = $this->makeBlog([
            'domain' => 'sftp.test', 'sftp_auth' => 'password', 'sftp_host' => 'sftp.test', 'sftp_user' => 'u',
            'sftp_secret' => 'pw', 'host_key_fingerprint' => null, 'status' => 'pending',
        ]);
        $this->app->bind(RemoteFilesystemFactory::class, fn () => new class extends RemoteFilesystemFactory {
            public function make(Blog $blog): FilesystemOperator
            {
                throw new \LogicException('No debería intentar conectar sin huella confirmada.');
            }
        });

        $article = app(ArticleService::class)->createDraft($blog);
        app(ArticleService::class)->save($article, ['title' => 'T', 'body_html' => '<p>x</p>', 'published_at' => '2026-11-10T09:00', 'version' => $article->version]);
        PublishArticle::dispatchSync($article->id);

        $deployment = $article->fresh()->lastDeployment;
        $this->assertSame('blocked', $deployment->error_code);
        $this->assertStringContainsString('huella', $deployment->error_message);
    }

    public function test_failure_logs_never_contain_credentials(): void
    {
        $blog = $this->makeBlog([
            'domain' => 'log.test', 'sftp_auth' => 'password', 'sftp_host' => 'h.test', 'sftp_user' => 'usuario-sftp',
            'sftp_secret' => 'Sup3rSecretPw', 'host_key_fingerprint' => 'sha256:'.str_repeat('A', 43),
        ]);
        $this->app->bind(RemoteFilesystemFactory::class, fn () => new class extends RemoteFilesystemFactory {
            public function make(Blog $blog): FilesystemOperator
            {
                throw new \RuntimeException('Fallo de conexión con usuario-sftp y clave Sup3rSecretPw');
            }
        });

        Log::spy();
        $article = app(ArticleService::class)->createDraft($blog);
        app(ArticleService::class)->save($article, ['title' => 'T', 'body_html' => '<p>x</p>', 'published_at' => '2026-11-10T09:00', 'version' => $article->version]);
        PublishArticle::dispatchSync($article->id);

        Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context) {
            $dump = json_encode($context);

            return ! str_contains($dump, 'Sup3rSecretPw') && str_contains($dump, '***');
        });
    }

    public function test_oversized_upload_gets_a_clear_message(): void
    {
        $article = $this->article();

        $this->actingAs($this->admin())
            ->withServerVariables(['CONTENT_LENGTH' => 900 * 1024 * 1024])
            ->postJson(route('media.store', $this->blog), ['article_id' => $article->id])
            ->assertStatus(413)
            ->assertJsonPath('message', 'La imagen es demasiado pesada. El máximo es '.MediaService::humanBytes(MediaService::maxUploadBytes()).'.');

        config(['publiblog.images.max_upload_kb' => 5]);
        $this->actingAs($this->admin())
            ->post(route('media.store', $this->blog), ['article_id' => $article->id, 'image' => $this->jpegUpload(1200, 900)], ['Accept' => 'application/json'])
            ->assertStatus(413);
    }

    public function test_reindex_rebuilds_media_table(): void
    {
        $article = $this->article(image: true);
        PublishArticle::dispatchSync($article->id);
        $before = Media::first()->only(['remote_path', 'local_path', 'sha256', 'width', 'height', 'bytes', 'mime']);

        Media::query()->delete();
        $this->assertSame(0, Media::count());

        app(ArticleIndexer::class)->reindex($this->blog);

        $this->assertSame(1, Media::count());
        $media = Media::first();
        $this->assertSame($before, $media->only(['remote_path', 'local_path', 'sha256', 'width', 'height', 'bytes', 'mime']));
        $this->assertSame($article->id, $media->article_id);
        $this->assertNull($media->uploaded_at);

        // Tras la reconstrucción se puede volver a publicar: la imagen se vuelve a subir si falta.
        unlink($this->remoteDir.'/'.$media->remote_path);
        PublishArticle::dispatchSync($article->id);
        $this->assertSame('ok', $article->fresh()->lastDeployment->status);
        $this->assertFileExists($this->remoteDir.'/'.$media->remote_path);
    }

    public function test_regenerate_restores_a_wiped_client_site(): void
    {
        $a = $this->article('Uno', 1, image: true);
        $b = $this->article('Dos', 2);
        PublishArticle::dispatchSync($a->id);
        PublishArticle::dispatchSync($b->id);

        // El sitio del cliente se pierde por completo.
        File::deleteDirectory($this->remoteDir);
        File::ensureDirectoryExists($this->remoteDir);

        $this->artisan('blog:regenerate', ['blog' => $this->blog->id])->assertSuccessful();

        foreach ([$a, $b] as $article) {
            $this->assertFileExists($this->remoteDir.'/'.$article->fresh()->remote_path);
        }
        $this->assertFileExists($this->remoteDir.'/'.Media::first()->remote_path);
        $this->assertFileExists($this->remoteDir.'/.htaccess');
        $this->assertFileExists($this->remoteDir.'/index.html');
        $this->assertFileExists($this->remoteDir.'/sitemap.xml');
        $this->assertSame(3, substr_count(file_get_contents($this->remoteDir.'/.htaccess'), 'RewriteRule '));
    }

    public function test_regenerate_stops_on_connection_problems(): void
    {
        $this->article();
        $a = Article::first();
        PublishArticle::dispatchSync($a->id);
        $this->blog->update(['status' => Blog::STATUS_HOSTKEY_CHANGED]);

        $this->artisan('blog:regenerate', ['blog' => $this->blog->id])->assertFailed();
    }

    public function test_users_without_assigned_blogs_cannot_use_other_blogs(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);

        $this->actingAs($editor)->get('/blogs')->assertOk()->assertDontSee($this->blog->name);
        $this->actingAs($editor)->post(route('articles.store', $this->blog))->assertNotFound();
        $this->actingAs($editor)->post(route('logout'))->assertRedirect(route('login'));
    }

    public function test_panel_responses_carry_security_headers(): void
    {
        $response = $this->actingAs($this->admin())->get('/blogs');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
        $this->get('/login')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_trusted_proxies_are_read_from_config_so_config_cache_works(): void
    {
        config(['publiblog.trusted_proxies' => '*']);
        (new AppServiceProvider($this->app))->boot();

        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '172.16.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9']);
        (new TrustProxies())->handle($request, fn () => response('ok'));

        $this->assertSame('203.0.113.9', $request->ip());

        // Sin configurar, nadie es de confianza: una cabecera falsa no cambia la IP.
        TrustProxies::flushState();
        config(['publiblog.trusted_proxies' => null]);
        (new AppServiceProvider($this->app))->boot();

        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '172.16.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9']);
        (new TrustProxies())->handle($request, fn () => response('ok'));

        $this->assertSame('172.16.0.5', $request->ip());
    }

    public function test_blog_template_must_have_article_and_index_views(): void
    {
        $dir = resource_path('views/blogs/zz_only_article');
        File::ensureDirectoryExists($dir);
        File::put($dir.'/article.blade.php', 'x');

        try {
            $this->actingAs($this->admin())->post(route('blogs.store'), [
                'name' => 'X', 'domain' => 'x.com', 'base_url' => 'https://x.com', 'remote_dir' => '/blog/',
                'locale' => 'es', 'timezone' => 'Europe/Madrid', 'posts_per_page' => 12, 'template' => 'zz_only_article',
                'sftp_auth' => 'password', 'sftp_host' => 'h', 'sftp_user' => 'u', 'sftp_secret' => 's',
            ])->assertSessionHasErrors('template');
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_all_panel_pages_render(): void
    {
        $article = $this->article();
        $this->actingAs($this->admin());

        foreach ([
            route('blogs.index'), route('blogs.create'), route('blogs.show', $this->blog), route('blogs.edit', $this->blog),
            route('articles.index', $this->blog), route('articles.edit', [$this->blog, $article]),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
