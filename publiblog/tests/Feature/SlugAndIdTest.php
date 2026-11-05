<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Services\ArticleIdService;
use App\Services\SlugService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithBlogs;
use Tests\TestCase;

class SlugAndIdTest extends TestCase
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

    public function test_slug_is_transliterated_and_clean(): void
    {
        $slugs = app(SlugService::class);

        $this->assertSame('5-consejos-para-reformar-tu-cocina', $slugs->normalize('¡5 Consejos para reformar tu cocina!'));
        $this->assertSame('cafe-nandu-no', $slugs->normalize('Café ñandú  no'));
        $this->assertSame('', $slugs->normalize('???'));
    }

    public function test_slug_respects_max_length(): void
    {
        $slug = app(SlugService::class)->normalize(str_repeat('palabra ', 30));

        $this->assertLessThanOrEqual(80, strlen($slug));
        $this->assertStringEndsNotWith('-', $slug);
    }

    public function test_reserved_slugs_are_never_generated(): void
    {
        $blog = $this->makeBlog();
        $slugs = app(SlugService::class);

        foreach (['index', 'sitemap', 'categoria', 'pagina', 'img', 'assets', 'admin'] as $reserved) {
            $this->assertTrue($slugs->isReserved($reserved));
            $this->assertNotSame($reserved, $slugs->unique($blog, $reserved));
        }
    }

    public function test_slug_is_unique_per_blog_and_validates_shape(): void
    {
        $a = $this->makeBlog();
        $b = $this->makeBlog(['domain' => 'otro.com', 'name' => 'Otro']);
        $slugs = app(SlugService::class);

        Article::create(['blog_id' => $a->id, 'public_id' => '000001', 'slug' => 'hola']);

        $this->assertSame('hola-2', $slugs->unique($a, 'Hola'));
        $this->assertSame('hola', $slugs->unique($b, 'Hola'));

        $this->assertFalse($slugs->isValid('../etc/passwd'));
        $this->assertFalse($slugs->isValid('con espacios'));
        $this->assertFalse($slugs->isValid('Mayusculas'));
        $this->assertFalse($slugs->isValid('doble--guion'));
        $this->assertTrue($slugs->isValid('valido-123'));
    }

    public function test_ids_are_sequential_per_blog_with_six_digits(): void
    {
        $a = $this->makeBlog();
        $b = $this->makeBlog(['domain' => 'otro.com', 'name' => 'Otro']);
        $ids = app(ArticleIdService::class);

        $this->assertSame('000001', $ids->next($a));
        $this->assertSame('000002', $ids->next($a));
        $this->assertSame('000001', $ids->next($b));
        $this->assertSame(2, $a->fresh()->last_public_id);
    }

    public function test_stale_blog_instances_do_not_produce_duplicate_ids(): void
    {
        $blog = $this->makeBlog();
        $stale1 = \App\Models\Blog::find($blog->id);
        $stale2 = \App\Models\Blog::find($blog->id);
        $ids = app(ArticleIdService::class);

        // Dos "peticiones" con el contador en memoria desactualizado.
        $this->assertSame('000001', $ids->next($stale1));
        $this->assertSame('000002', $ids->next($stale2));
    }

    public function test_id_space_is_bounded(): void
    {
        $blog = $this->makeBlog();
        $blog->update(['last_public_id' => 999999]);

        $this->expectException(\RuntimeException::class);
        app(ArticleIdService::class)->next($blog);
    }
}
