<?php

namespace Tests\Feature;

use App\Jobs\PublishArticle;
use App\Jobs\UnpublishArticle;
use App\Models\Article;
use App\Models\Blog;
use App\Services\ArticleService;
use App\Services\ListingBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithBlogs;
use Tests\TestCase;

class ListingTest extends TestCase
{
    use InteractsWithBlogs, RefreshDatabase;

    private Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBlogs();
        $this->blog = $this->makeBlog(['verify_http' => true, 'posts_per_page' => 2]);
        $this->fakeVerification();
    }

    protected function tearDown(): void
    {
        $this->tearDownBlogs();
        parent::tearDown();
    }

    /** Publica un artículo; $day fija el orden por fecha. */
    private function publishArticle(string $title, int $day): Article
    {
        $service = app(ArticleService::class);
        $article = $service->createDraft($this->blog);
        $service->save($article, [
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

    public function test_listing_is_paginated_newest_first_with_clean_urls(): void
    {
        foreach (range(1, 5) as $i) {
            $this->publishArticle("Artículo {$i}", $i);
        }

        $p1 = $this->remote('index.html');
        $p2 = $this->remote('pagina/2/index.html');
        $p3 = $this->remote('pagina/3/index.html');

        $this->assertFileDoesNotExist($this->remoteDir.'/pagina/4/index.html');

        // 5 artículos, 2 por página: [5,4] [3,2] [1]
        $this->assertStringContainsString('Artículo 5', $p1);
        $this->assertStringContainsString('Artículo 4', $p1);
        $this->assertLessThan(strpos($p1, 'Artículo 4'), strpos($p1, 'Artículo 5'));
        $this->assertStringNotContainsString('Artículo 3', $p1);
        $this->assertStringContainsString('Artículo 3', $p2);
        $this->assertStringContainsString('Artículo 1', $p3);

        $this->assertStringContainsString('href="https://cliente-a.com/blog/articulo-5"', $p1);
        $this->assertStringContainsString('<link rel="canonical" href="https://cliente-a.com/blog/">', $p1);
        $this->assertStringContainsString('<link rel="canonical" href="https://cliente-a.com/blog/pagina/2/">', $p2);
        $this->assertStringContainsString('<link rel="next" href="https://cliente-a.com/blog/pagina/2/">', $p1);
        $this->assertStringContainsString('<link rel="prev" href="https://cliente-a.com/blog/">', $p2);
        $this->assertStringNotContainsString('rel="next"', $p3);
        $this->assertSame(1, preg_match_all('/<h1[\s>]/', $p1));
    }

    public function test_every_published_article_is_linked_from_some_page(): void
    {
        $articles = array_map(fn ($i) => $this->publishArticle("Nota {$i}", $i), range(1, 5));

        $all = $this->remote('index.html').$this->remote('pagina/2/index.html').$this->remote('pagina/3/index.html');

        foreach ($articles as $a) {
            $this->assertStringContainsString('/blog/'.$a->slug.'"', $all);
        }
    }

    public function test_sitemap_is_valid_and_contains_only_published_urls(): void
    {
        $a = $this->publishArticle('Uno', 1);
        $b = $this->publishArticle('Dos', 2);
        app(ArticleService::class)->createDraft($this->blog); // borrador: no debe salir

        $xml = simplexml_load_string($this->remote('sitemap.xml'));
        $this->assertNotFalse($xml);
        $urls = array_map('strval', iterator_to_array($xml->url->loc ?? [], false));
        $urls = [];
        foreach ($xml->url as $u) {
            $urls[] = (string) $u->loc;
            $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $u->lastmod);
        }

        $this->assertEqualsCanonicalizing(['https://cliente-a.com/blog/uno', 'https://cliente-a.com/blog/dos'], $urls);

        UnpublishArticle::dispatchSync($a->id);

        $xml = simplexml_load_string($this->remote('sitemap.xml'));
        $this->assertCount(1, $xml->url);
        $this->assertStringNotContainsString('/blog/uno', $this->remote('sitemap.xml'));
        $this->assertStringNotContainsString('/blog/uno"', $this->remote('index.html'));
    }

    public function test_default_sitemap_limit_is_500_urls_per_file(): void
    {
        $this->assertSame(500, config('publiblog.sitemap_limit'));
    }

    public function test_sitemap_is_split_into_an_index_when_over_the_limit(): void
    {
        config(['publiblog.sitemap_limit' => 2]);

        foreach (range(1, 5) as $i) {
            $this->publishArticle("Parte {$i}", $i);
        }

        $index = simplexml_load_string($this->remote('sitemap.xml'));
        $this->assertSame('sitemapindex', $index->getName());
        $this->assertCount(3, $index->sitemap);
        $this->assertStringContainsString('https://cliente-a.com/blog/sitemap-1.xml', $this->remote('sitemap.xml'));
        $this->assertCount(1, simplexml_load_string($this->remote('sitemap-3.xml'))->url);
        $this->assertCount(2, simplexml_load_string($this->remote('sitemap-1.xml'))->url);

        // Un artículo nuevo solo cambia el último archivo; los anteriores no se vuelven a subir.
        $before = [hash_file('sha256', $this->remoteDir.'/sitemap-1.xml'), hash_file('sha256', $this->remoteDir.'/sitemap-2.xml')];
        $this->publishArticle('Parte 6', 6);
        $this->assertSame($before, [hash_file('sha256', $this->remoteDir.'/sitemap-1.xml'), hash_file('sha256', $this->remoteDir.'/sitemap-2.xml')]);
        $this->assertCount(2, simplexml_load_string($this->remote('sitemap-3.xml'))->url);

        // Al bajar del límite vuelve a un único sitemap y se borran las partes sobrantes.
        config(['publiblog.sitemap_limit' => 500]);
        $this->publishArticle('Otro', 6);

        $this->assertSame('urlset', simplexml_load_string($this->remote('sitemap.xml'))->getName());
        $this->assertFileDoesNotExist($this->remoteDir.'/sitemap-1.xml');
    }

    public function test_republishing_an_unchanged_article_uploads_only_its_html(): void
    {
        $article = $this->publishArticle('Estable', 1);
        $this->publishArticle('Otro', 2);

        PublishArticle::dispatchSync($article->id);
        $deployment = $article->fresh()->lastDeployment;

        $this->assertSame('ok', $deployment->status);
        $this->assertSame(1, $deployment->files_count); // solo el HTML del artículo
    }

    public function test_publishing_a_new_article_only_uploads_what_changed(): void
    {
        $this->blog->update(['posts_per_page' => 12]);
        $this->publishArticle('Primero', 1);
        $before = $this->remote('index.html');
        $mtimes = fn () => array_map(fn ($f) => hash_file('sha256', $this->remoteDir.'/'.$f), ['sitemap.xml', 'index.html']);

        $second = $this->publishArticle('Segundo', 2);

        // HTML + .htaccess + index.html + sitemap.xml (no hay más páginas)
        $this->assertSame(4, $second->lastDeployment->files_count);
        $this->assertNotSame($before, $this->remote('index.html'));
        $this->assertFileDoesNotExist($this->remoteDir.'/pagina/2/index.html');
        $this->assertCount(2, $mtimes());
    }

    public function test_empty_blog_listing_and_removed_pages_are_cleaned_up(): void
    {
        $a = $this->publishArticle('A', 1);
        $b = $this->publishArticle('B', 2);
        $c = $this->publishArticle('C', 3); // 3 artículos, 2 por página → hay página 2
        $this->assertFileExists($this->remoteDir.'/pagina/2/index.html');

        UnpublishArticle::dispatchSync($c->id);
        $this->assertFileDoesNotExist($this->remoteDir.'/pagina/2/index.html');

        UnpublishArticle::dispatchSync($a->id);
        UnpublishArticle::dispatchSync($b->id);

        $this->assertStringContainsString('Todavía no hay artículos', $this->remote('index.html'));
        $this->assertCount(0, simplexml_load_string($this->remote('sitemap.xml'))->url);
    }

    public function test_deleted_index_is_restored_on_next_publication(): void
    {
        $this->publishArticle('A', 1);
        unlink($this->remoteDir.'/index.html');

        $this->publishArticle('B', 2);

        $this->assertFileExists($this->remoteDir.'/index.html');
    }

    public function test_script_in_title_is_escaped_in_listing(): void
    {
        $this->publishArticle('<script>alert(1)</script> peligro', 1);

        $html = $this->remote('index.html');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_builder_output_matches_what_was_stored_in_the_cms_build_folder(): void
    {
        $article = $this->publishArticle('Guardado', 1);

        $built = app(ListingBuilder::class)->build($this->blog->fresh());

        $this->assertSame(['index.html', 'sitemap.xml'], array_keys($built));
        $this->assertSame($built['sitemap.xml'], $this->remote('sitemap.xml'));
        $this->assertFileExists(\Storage::disk('blogs')->path("{$this->blog->id}/build/manifest.json"));
        $this->assertFileExists(\Storage::disk('blogs')->path("{$this->blog->id}/build/index.html"));
    }
}
