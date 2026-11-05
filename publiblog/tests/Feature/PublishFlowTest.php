<?php

namespace Tests\Feature;

use App\Jobs\PublishArticle;
use App\Jobs\UnpublishArticle;
use App\Models\Article;
use App\Models\Blog;
use App\Models\Deployment;
use App\Services\ArticleIndexer;
use App\Services\ArticleService;
use App\Services\ArticleStorage;
use App\Services\HtaccessManager;
use App\Services\MediaService;
use App\Services\Remote\RemoteFilesystemFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PhpseclibV3\UnableToAuthenticate;
use League\Flysystem\PhpseclibV3\UnableToConnectToSftpHost;
use League\Flysystem\UnableToWriteFile;
use Tests\Support\InteractsWithBlogs;
use Tests\TestCase;

class PublishFlowTest extends TestCase
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
        $this->tearDownBlogs();
        parent::tearDown();
    }

    private function article(array $input = [], bool $withImage = false): Article
    {
        $service = app(ArticleService::class);
        $article = $service->createDraft($this->blog);

        $body = '<h2>Cómo planificar</h2><p>Texto de <strong>prueba</strong>.</p>';
        $featured = null;

        if ($withImage) {
            $media = app(MediaService::class)->store($this->blog, $article, $this->jpegUpload(2400, 1600));
            $body .= '<img src="'.$media->previewUrl().'" alt="foto">';
            $featured = $media->id;
        }

        return $service->save($article, $input + [
            'title' => '5 consejos para reformar tu cocina',
            'body_html' => $body,
            'excerpt' => 'Un extracto',
            'featured_media_id' => $featured,
            'featured_alt' => 'Cocina',
            'published_at' => '2026-11-10T09:30',
            'version' => $article->version,
        ]);
    }

    private function publish(Article $article): Deployment
    {
        PublishArticle::dispatchSync($article->id);

        return $article->fresh()->lastDeployment;
    }

    private function remoteFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->remoteDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $files[] = str_replace('\\', '/', substr($f->getPathname(), strlen($this->remoteDir) + 1));
        }
        sort($files);

        return $files;
    }

    public function test_full_publication_uploads_images_html_and_rule(): void
    {
        $article = $this->article(withImage: true);
        $deployment = $this->publish($article);
        $article->refresh();

        $this->assertSame('ok', $deployment->status, (string) $deployment->error_message);
        $this->assertSame('published', $article->status);
        $this->assertSame('000001-5-consejos-para-reformar-tu-cocina.html', $article->remote_path);
        $this->assertNotNull($article->first_published_at);
        $this->assertSame($article->content_hash, $article->published_hash);
        $this->assertFalse($article->hasUnpublishedChanges());
        $this->assertSame(5, $deployment->files_count); // imagen + html + .htaccess + index.html + sitemap.xml

        $files = $this->remoteFiles();
        $this->assertContains('000001-5-consejos-para-reformar-tu-cocina.html', $files);
        $this->assertContains('.htaccess', $files);
        $this->assertCount(1, array_filter($files, fn ($f) => str_starts_with($f, 'img/')));
        $this->assertNotContains('.htaccess.bak', $files); // no había .htaccess previo

        $html = file_get_contents($this->remoteDir.'/'.$article->remote_path);
        $this->assertStringContainsString('<meta name="blog-article-id" content="000001">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://cliente-a.com/blog/5-consejos-para-reformar-tu-cocina">', $html);
        $this->assertStringContainsString('<html lang="es">', $html);
        $this->assertSame(1, preg_match_all('/<h1[\s>]/', $html));
        $this->assertStringContainsString('/blog/img/'.date('Y').'/', $html);

        $this->assertStringContainsString(
            'RewriteRule ^5-consejos-para-reformar-tu-cocina/?$ 000001-5-consejos-para-reformar-tu-cocina.html [END]',
            file_get_contents($this->remoteDir.'/.htaccess')
        );
    }

    public function test_files_in_cms_follow_the_documented_layout(): void
    {
        $article = $this->article();
        $storage = app(ArticleStorage::class);

        $this->assertSame("{$this->blog->id}/articles/000/000001.json", $storage->relativePath($this->blog->id, '000001', 'json'));
        $this->assertSame("{$this->blog->id}/articles/123/123456.html", $storage->relativePath($this->blog->id, '123456', 'html'));

        $json = $storage->readJson($this->blog->id, '000001');
        $this->assertSame(1, $json['schema_version']);
        $this->assertSame('000001', $json['public_id']);
        $this->assertSame('5-consejos-para-reformar-tu-cocina', $json['slug']);
        $this->assertSame('https://cliente-a.com/blog/5-consejos-para-reformar-tu-cocina', $json['seo']['canonical']);
        $this->assertNotNull($storage->readHtml($this->blog->id, '000001'));
    }

    public function test_edit_and_republish_overwrites_without_duplicating_files(): void
    {
        $article = $this->article();
        $this->publish($article);
        $before = $this->remoteFiles();

        $article->refresh();
        $service = app(ArticleService::class);
        $service->save($article, [
            'title' => '5 consejos para reformar tu cocina',
            'slug' => 'otro-slug-que-debe-ignorarse', // el slug queda fijo tras publicar
            'body_html' => '<p>Párrafo editado</p>',
            'published_at' => '2026-11-10T09:30',
            'version' => $article->version,
        ]);

        $article->refresh();
        $this->assertSame('5-consejos-para-reformar-tu-cocina', $article->slug);
        $this->assertTrue($article->hasUnpublishedChanges());

        $this->assertSame('ok', $this->publish($article)->status);

        $this->assertSame($before, $this->remoteFiles());
        $this->assertStringContainsString('Párrafo editado', file_get_contents($this->remoteDir.'/'.$article->remote_path));
        $this->assertSame(1, substr_count(file_get_contents($this->remoteDir.'/.htaccess'), 'RewriteRule'));
        $this->assertSame('update', $article->fresh()->lastDeployment->action);
    }

    public function test_manual_change_on_client_html_is_overwritten(): void
    {
        $article = $this->article();
        $this->publish($article);

        file_put_contents($this->remoteDir.'/'.$article->fresh()->remote_path, 'CAMBIO MANUAL');
        $this->publish($article->fresh());

        $this->assertStringNotContainsString('CAMBIO MANUAL', file_get_contents($this->remoteDir.'/'.$article->fresh()->remote_path));
    }

    public function test_htaccess_keeps_foreign_content_and_only_manages_its_block(): void
    {
        file_put_contents($this->remoteDir.'/.htaccess', "# reglas del cliente\nOptions -Indexes\n");

        $one = $this->article();
        $this->publish($one);
        $two = $this->article(['title' => 'Otro artículo']);
        $this->publish($two);

        $ht = file_get_contents($this->remoteDir.'/.htaccess');
        $this->assertStringContainsString("# reglas del cliente\nOptions -Indexes", $ht);
        $this->assertSame(1, substr_count($ht, HtaccessManager::BEGIN));
        $this->assertSame(2, substr_count($ht, 'RewriteRule'));
        $this->assertFileExists($this->remoteDir.'/.htaccess.bak');

        // Eliminar uno: solo queda la regla del otro y lo ajeno no cambia.
        UnpublishArticle::dispatchSync($one->id);

        $ht = file_get_contents($this->remoteDir.'/.htaccess');
        $this->assertStringContainsString('Options -Indexes', $ht);
        $this->assertSame(1, substr_count($ht, 'RewriteRule'));
        $this->assertStringNotContainsString($one->slug, $ht);
        $this->assertFileDoesNotExist($this->remoteDir.'/'.$one->fresh()->fileName());
    }

    public function test_delete_returns_404_state_and_keeps_json(): void
    {
        $article = $this->article();
        $this->publish($article);

        UnpublishArticle::dispatchSync($article->id);

        $this->assertSoftDeleted('articles', ['id' => $article->id]);
        $this->assertNotNull(app(ArticleStorage::class)->readJson($this->blog->id, $article->public_id));
        $this->assertNull(Article::withTrashed()->find($article->id)->remote_path);

        $this->fakeVerification();
        $this->assertStringNotContainsString('RewriteRule ^'.$article->slug, file_get_contents($this->remoteDir.'/.htaccess'));
    }

    public function test_deleting_a_never_published_draft_touches_nothing_remote(): void
    {
        $article = $this->article();

        UnpublishArticle::dispatchSync($article->id);

        $this->assertSoftDeleted('articles', ['id' => $article->id]);
        $this->assertSame([], $this->remoteFiles());
    }

    public function test_incomplete_article_is_not_published(): void
    {
        $article = app(ArticleService::class)->createDraft($this->blog);
        $deployment = $this->publish($article);

        $this->assertSame('error', $deployment->status);
        $this->assertSame('validation', $deployment->error_code);
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertSame([], $this->remoteFiles());
    }

    public function test_verification_failure_marks_error_and_never_reports_published(): void
    {
        $article = $this->article();
        $this->fakeVerification(404);

        $deployment = $this->publish($article);

        $this->assertSame('verify', $deployment->error_code);
        $this->assertSame('error', $article->fresh()->status);
        $this->assertNotNull(app(ArticleStorage::class)->readJson($this->blog->id, $article->public_id)); // contenido guardado
    }

    public function test_http_500_restores_previous_htaccess(): void
    {
        file_put_contents($this->remoteDir.'/.htaccess', "# original\n");
        $article = $this->article();

        $this->fakeVerification(500);
        $deployment = $this->publish($article);

        $this->assertSame('verify', $deployment->error_code);
        $this->assertStringContainsString('se restauró', $deployment->error_message);
        $this->assertSame("# original\n", file_get_contents($this->remoteDir.'/.htaccess'));
    }

    public function test_http_500_without_previous_htaccess_removes_it(): void
    {
        $article = $this->article();

        $this->fakeVerification(500);
        $this->publish($article);

        $this->assertFileDoesNotExist($this->remoteDir.'/.htaccess');
    }

    public function test_missing_blog_article_id_in_response_fails_verification(): void
    {
        $article = $this->article();
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('<html>otra cosa</html>', 200)]);

        $this->assertSame('verify', $this->publish($article)->error_code);
    }

    /** @return FilesystemOperator que falla al escribir ficheros que casen con $pattern */
    private function failingRemote(string $pattern, string $message = 'Permission denied'): void
    {
        $dir = $this->remoteDir;

        $this->app->bind(RemoteFilesystemFactory::class, fn () => new class($dir, $pattern, $message) extends RemoteFilesystemFactory {
            public function __construct(private string $dir, private string $pattern, private string $message)
            {
            }

            public function make(Blog $blog): FilesystemOperator
            {
                $pattern = $this->pattern;
                $message = $this->message;

                return new Filesystem(new class($this->dir, $pattern, $message) extends LocalFilesystemAdapter {
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

    public function test_permission_failure_keeps_content_and_retry_publishes(): void
    {
        $article = $this->article();
        $this->failingRemote('/\.html\.tmp$|\.html$/');

        $deployment = $this->publish($article);

        $this->assertSame('permission', $deployment->error_code);
        $this->assertStringContainsString('.html', $deployment->error_message);
        $this->assertSame('error', $article->fresh()->status);
        $this->assertNotNull(app(ArticleStorage::class)->readJson($this->blog->id, $article->public_id));
        $this->assertNull($article->fresh()->remote_path);

        // El servidor vuelve a funcionar: Reintentar repite todo el trabajo.
        $this->app->bind(RemoteFilesystemFactory::class, fn () => new RemoteFilesystemFactory());

        $this->assertSame('ok', $this->publish($article->fresh())->status);
        $this->assertSame('published', $article->fresh()->status);
        $this->assertFileExists($this->remoteDir.'/'.$article->fresh()->remote_path);
    }

    public function test_interrupted_upload_before_htaccess_recovers_on_retry(): void
    {
        $article = $this->article();
        // El HTML se sube pero falla el .htaccess (corte a mitad de publicación).
        $this->failingRemote('/\.htaccess/', 'Connection lost');

        $deployment = $this->publish($article);
        $this->assertSame('error', $deployment->status);
        $this->assertSame('error', $article->fresh()->status);

        $this->app->bind(RemoteFilesystemFactory::class, fn () => new RemoteFilesystemFactory());
        $this->assertSame('ok', $this->publish($article->fresh())->status);
        $this->assertSame('published', $article->fresh()->status);
        $this->assertSame(1, substr_count(file_get_contents($this->remoteDir.'/.htaccess'), 'RewriteRule'));
    }

    public function test_connection_failure_maps_to_connect_and_keeps_blog_usable(): void
    {
        $article = $this->article();
        $this->app->bind(RemoteFilesystemFactory::class, fn () => new class extends RemoteFilesystemFactory {
            public function make(Blog $blog): FilesystemOperator
            {
                throw UnableToConnectToSftpHost::atHostname('cliente-a.com');
            }
        });

        $deployment = $this->publish($article);

        $this->assertSame('connect', $deployment->error_code);
        $this->assertSame('No se pudo conectar con el servidor.', $deployment->error_message);
        $this->assertSame('error', $article->fresh()->status);
        $this->assertSame(Blog::STATUS_ACTIVE, $this->blog->fresh()->status);
    }

    public function test_rejected_credentials_flag_the_blog(): void
    {
        $article = $this->article();
        $this->app->bind(RemoteFilesystemFactory::class, fn () => new class extends RemoteFilesystemFactory {
            public function make(Blog $blog): FilesystemOperator
            {
                throw UnableToAuthenticate::withPassword('Login failed');
            }
        });

        $deployment = $this->publish($article);

        $this->assertSame('auth', $deployment->error_code);
        $this->assertSame(Blog::STATUS_CONNECTION_ERROR, $this->blog->fresh()->status);
    }

    public function test_changed_host_fingerprint_blocks_publishing_until_confirmed(): void
    {
        $article = $this->article();
        $this->blog->update(['status' => Blog::STATUS_HOSTKEY_CHANGED]);

        $deployment = $this->publish($article);

        $this->assertSame('blocked', $deployment->error_code);
        $this->assertSame([], $this->remoteFiles());
        $this->assertSame('draft', $article->fresh()->status);
    }

    public function test_second_publication_waits_for_the_blog_lock_and_times_out(): void
    {
        config(['publiblog.publish.lock_wait' => 0]);
        $article = $this->article();

        $lock = Cache::lock('blog:'.$this->blog->id, 30);
        $this->assertTrue($lock->get());

        $deployment = $this->publish($article);
        $lock->release();

        $this->assertSame('lock_timeout', $deployment->error_code);
        $this->assertSame([], $this->remoteFiles());

        // Liberado el bloqueo, se puede reintentar sin haber pisado nada.
        $this->assertSame('ok', $this->publish($article->fresh())->status);
    }

    public function test_lock_is_released_after_each_job(): void
    {
        $this->publish($this->article());

        $lock = Cache::lock('blog:'.$this->blog->id, 5);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_two_edits_with_different_version_warn(): void
    {
        $article = $this->article();
        $service = app(ArticleService::class);
        $openedVersion = $article->version;

        $service->save($article, ['title' => 'Cambio de A', 'version' => $openedVersion]);

        $this->expectException(\App\Exceptions\VersionConflictException::class);
        $service->save($article->fresh(), ['title' => 'Cambio de B', 'version' => $openedVersion]);
    }

    public function test_forced_save_overrides_version_conflict(): void
    {
        $article = $this->article();
        $service = app(ArticleService::class);
        $stale = $article->version;

        $service->save($article, ['title' => 'A', 'version' => $stale]);
        $service->save($article->fresh(), ['title' => 'B', 'version' => $stale], null, true);

        $this->assertSame('B', $article->fresh()->title);
    }

    public function test_remote_paths_never_leave_the_blog_folder(): void
    {
        $article = $this->article(['slug' => '../../etc/passwd', 'title' => 'Intento de salida']);

        // El slug se normaliza: nunca quedan puntos ni barras en rutas ni reglas.
        $this->assertDoesNotMatchRegularExpression('#[./\\\\]#', $article->slug);
        $this->assertSame('etcpasswd', $article->slug);

        $this->assertSame('ok', $this->publish($article)->status);

        foreach ($this->remoteFiles() as $file) {
            $this->assertStringNotContainsString('..', $file);
        }
        $this->assertStringNotContainsString('..', file_get_contents($this->remoteDir.'/.htaccess'));
        $this->assertSame(realpath($this->remoteDir), realpath(dirname($this->remoteDir.'/'.$article->fresh()->remote_path)));
    }

    public function test_htaccess_block_ignores_invalid_slugs(): void
    {
        $article = $this->article();
        // Alguien manipula la base de datos con un slug peligroso: la regla no se genera.
        $article->forceFill(['slug' => "x\nRewriteRule .* http://evil.test [R]", 'remote_path' => '000001-x.html'])->save();

        $block = app(HtaccessManager::class)->buildBlock($this->blog);

        $this->assertStringNotContainsString('evil.test', $block);
        $this->assertSame(0, substr_count($block, 'RewriteRule '));
    }

    public function test_reindex_rebuilds_the_same_index(): void
    {
        $a = $this->article();
        $this->publish($a);
        $b = $this->article(['title' => 'Segundo']);
        $c = $this->article(['title' => 'Tercero']);
        $this->publish($c);
        UnpublishArticle::dispatchSync($c->id);

        $expected = Article::withTrashed()->orderBy('public_id')->get()
            ->map(fn ($x) => $x->only(['public_id', 'slug', 'title', 'status', 'version', 'remote_path', 'published_hash']) + [
                'published_at' => $x->published_at?->toIso8601String(),
                'deleted' => $x->trashed(),
            ])->all();

        Article::withTrashed()->forceDelete();
        $this->blog->update(['last_public_id' => 0]);
        $this->assertSame(0, Article::withTrashed()->count());

        $count = app(ArticleIndexer::class)->reindex($this->blog->fresh());

        $this->assertSame(3, $count);
        $actual = Article::withTrashed()->orderBy('public_id')->get()
            ->map(fn ($x) => $x->only(['public_id', 'slug', 'title', 'status', 'version', 'remote_path', 'published_hash']) + [
                'published_at' => $x->published_at?->toIso8601String(),
                'deleted' => $x->trashed(),
            ])->all();

        $this->assertSame($expected, $actual);
        $this->assertSame(3, $this->blog->fresh()->last_public_id);
        $this->assertSame('000004', app(\App\Services\ArticleIdService::class)->next($this->blog->fresh()));
    }

    public function test_reindex_command(): void
    {
        $this->article();
        Article::query()->forceDelete();

        $this->artisan('blog:reindex', ['blog' => $this->blog->id])->assertSuccessful();

        $this->assertSame(1, Article::count());
    }
}
