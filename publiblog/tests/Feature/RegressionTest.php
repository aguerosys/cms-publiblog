<?php

namespace Tests\Feature;

use App\Jobs\PublishArticle;
use App\Models\Article;
use App\Models\Blog;
use App\Models\Deployment;
use App\Models\Media;
use App\Services\ArticleService;
use App\Services\MediaService;
use App\Services\Remote\RemoteErrorMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use League\Flysystem\PhpseclibV3\UnableToAuthenticate;
use League\Flysystem\PhpseclibV3\UnableToConnectToSftpHost;
use League\Flysystem\PhpseclibV3\UnableToEstablishAuthenticityOfHost;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToWriteFile;
use Tests\Support\InteractsWithBlogs;
use Tests\TestCase;

/** Fallos que solo aparecieron al probar contra un servidor SFTP real. */
class RegressionTest extends TestCase
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

    /** Flysystem envuelve la causa real: UnableToWriteFile → UnableToAuthenticate. */
    public function test_wrapped_connection_errors_are_mapped_by_their_root_cause(): void
    {
        $wrap = fn (\Throwable $cause) => new UnableToWriteFile('Unable to write file at location: x.html. not able to write the file', 0, $cause);

        $this->assertSame('auth', RemoteErrorMapper::map($wrap(UnableToAuthenticate::withPassword('Login failed')))->errorCode);
        $this->assertSame('hostkey', RemoteErrorMapper::map($wrap(UnableToEstablishAuthenticityOfHost::becauseTheAuthenticityCantBeEstablished('h')))->errorCode);
        $this->assertSame('connect', RemoteErrorMapper::map($wrap(UnableToConnectToSftpHost::atHostname('h')))->errorCode);
        $this->assertSame('timeout', RemoteErrorMapper::map($wrap(UnableToConnectToSftpHost::atHostname('h', new \RuntimeException('Connection timed out'))))->errorCode);

        // Un fallo de lectura/borrado envuelto también se reconoce.
        $deleteWrapped = new UnableToDeleteFile('x', 0, UnableToAuthenticate::withPassword('Login failed'));
        $this->assertSame('auth', RemoteErrorMapper::map($deleteWrapped)->errorCode);

        // Sin causa de conexión, sigue diferenciando permisos y disco.
        $this->assertSame('permission', RemoteErrorMapper::map(UnableToWriteFile::atLocation('/a.html', 'Permission denied'))->errorCode);
        $this->assertSame('disk_full', RemoteErrorMapper::map(UnableToWriteFile::atLocation('/a.html', 'No space left on device'))->errorCode);
        $this->assertSame('incomplete', RemoteErrorMapper::map(UnableToWriteFile::atLocation('/a.html', 'algo raro'))->errorCode);
    }

    public function test_deleting_a_blog_with_articles_media_and_history_works(): void
    {
        $blog = $this->makeBlog();
        $service = app(ArticleService::class);
        $article = $service->createDraft($blog);
        $media = app(MediaService::class)->store($blog, $article, $this->jpegUpload(900, 600));
        $service->save($article, [
            'title' => 'T', 'body_html' => '<p>x</p>', 'featured_media_id' => $media->id,
            'published_at' => '2026-11-10T09:00', 'version' => $article->version,
        ]);
        PublishArticle::dispatchSync($article->id);

        $this->assertSame(1, Deployment::count());

        $blog->delete();

        $this->assertSame(0, Article::withTrashed()->count());
        $this->assertSame(0, Media::count());
        $this->assertSame(0, Deployment::count());
    }

    public function test_force_deleting_an_article_cleans_its_media_and_deployments(): void
    {
        $blog = $this->makeBlog();
        $article = app(ArticleService::class)->createDraft($blog);
        app(MediaService::class)->store($blog, $article, $this->jpegUpload(600, 400));

        $article->forceDelete();

        $this->assertSame(0, Media::count());
    }
}
