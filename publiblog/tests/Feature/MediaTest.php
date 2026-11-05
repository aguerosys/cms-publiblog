<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Services\ArticleService;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Support\InteractsWithBlogs;
use Tests\TestCase;

class MediaTest extends TestCase
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

    private function draft()
    {
        $blog = $this->makeBlog();

        return [$blog, app(ArticleService::class)->createDraft($blog)];
    }

    public function test_php_renamed_to_jpg_is_rejected(): void
    {
        [$blog, $article] = $this->draft();

        $path = tempnam(sys_get_temp_dir(), 'pbphp');
        file_put_contents($path, '<?php system($_GET["c"]); ?>');
        $file = new UploadedFile($path, 'shell.jpg', 'image/jpeg', null, true);

        $this->expectException(InvalidArgumentException::class);
        app(MediaService::class)->store($blog, $article, $file);
    }

    public function test_html_disguised_as_png_is_rejected(): void
    {
        [$blog, $article] = $this->draft();

        $path = tempnam(sys_get_temp_dir(), 'pbhtml');
        file_put_contents($path, '<html><script>alert(1)</script></html>');

        $this->expectException(InvalidArgumentException::class);
        app(MediaService::class)->store($blog, $article, new UploadedFile($path, 'a.png', 'image/png', null, true));
    }

    public function test_large_jpeg_is_reduced_and_exif_is_stripped(): void
    {
        [$blog, $article] = $this->draft();
        $upload = $this->jpegUpload(3600, 2400, exif: true);
        $originalBytes = $upload->getSize();

        $this->assertStringContainsString('SECRETGPS', file_get_contents($upload->getRealPath()));

        $media = app(MediaService::class)->store($blog, $article, $upload);
        $stored = Storage::disk('blogs')->get($media->local_path);

        $this->assertLessThanOrEqual(1600, max($media->width, $media->height));
        $this->assertSame(1600, $media->width);
        $this->assertSame(1067, $media->height);
        $this->assertLessThan($originalBytes / 2, $media->bytes);
        $this->assertStringNotContainsString('SECRETGPS', $stored);
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertSame('image/jpeg', $media->mime);
        $this->assertSame(hash('sha256', $stored), $media->sha256);
        $this->assertMatchesRegularExpression('#^img/\d{4}/000001-1-[0-9a-f]{8}\.jpg$#', $media->remote_path);
        $this->assertSame('/blog/'.$media->remote_path, $media->publicPath());
    }

    public function test_small_png_keeps_transparency_and_size(): void
    {
        [$blog, $article] = $this->draft();

        $img = imagecreatetruecolor(100, 50);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 255, 0, 0, 127));
        $path = tempnam(sys_get_temp_dir(), 'pbpng');
        imagepng($img, $path);

        $media = app(MediaService::class)->store($blog, $article, new UploadedFile($path, 'x.png', 'image/png', null, true));

        $this->assertSame([100, 50], [$media->width, $media->height]);
        $this->assertSame('image/png', $media->mime);
        $this->assertStringEndsWith('.png', $media->remote_path);
    }

    public function test_preview_urls_become_public_paths_with_dimensions_and_back(): void
    {
        [$blog, $article] = $this->draft();
        $media = app(MediaService::class)->store($blog, $article, $this->jpegUpload(800, 600));
        $service = app(MediaService::class);

        $public = $service->toPublic('<p>x</p><img src="'.$media->previewUrl().'" alt="foto">', $blog);

        $this->assertStringContainsString('src="'.$media->publicPath().'"', $public);
        $this->assertStringContainsString('width="800"', $public);
        $this->assertStringContainsString('height="600"', $public);
        $this->assertStringNotContainsString('/media/', $public);

        $this->assertStringContainsString('src="'.$media->previewUrl().'"', $service->toEditor($public, $blog));
    }

    public function test_media_from_another_blog_is_not_resolved(): void
    {
        [$blog, $article] = $this->draft();
        $other = $this->makeBlog(['domain' => 'otro.com', 'name' => 'Otro']);
        $otherArticle = app(ArticleService::class)->createDraft($other);
        $media = app(MediaService::class)->store($other, $otherArticle, $this->jpegUpload(400, 300));

        $html = app(MediaService::class)->toPublic('<img src="'.$media->previewUrl().'">', $blog);

        $this->assertStringNotContainsString($media->remote_path, $html);
    }

    public function test_upload_endpoint_requires_login_and_returns_preview_url(): void
    {
        [$blog, $article] = $this->draft();

        $this->postJson(route('media.store', $blog), ['article_id' => $article->id])->assertUnauthorized();

        $res = $this->actingAs($this->admin())
            ->post(route('media.store', $blog), ['article_id' => $article->id, 'image' => $this->jpegUpload(2400, 1600)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonStructure(['id', 'url', 'width', 'height']);

        $this->get($res->json('url'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(1, Media::count());
    }

    public function test_upload_endpoint_rejects_non_images_with_422(): void
    {
        [$blog, $article] = $this->draft();
        $path = tempnam(sys_get_temp_dir(), 'pbtxt');
        file_put_contents($path, 'hola');

        $this->actingAs($this->admin())
            ->post(route('media.store', $blog), ['article_id' => $article->id, 'image' => new UploadedFile($path, 'a.jpg', 'image/jpeg', null, true)], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Solo se admiten imágenes JPEG, PNG y WebP.');
    }
}
