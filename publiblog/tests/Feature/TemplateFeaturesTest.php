<?php

namespace Tests\Feature;

use App\Jobs\PublishArticle;
use App\Models\Article;
use App\Models\Blog;
use App\Services\ArticleRenderer;
use App\Services\ArticleService;
use App\Services\BlogRegenerator;
use App\Services\TemplateAssets;
use App\Services\TemplateValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\View\ViewException;
use Tests\Support\InteractsWithBlogs;
use Tests\TestCase;

/** Plantillas por cliente: assets propios (/blog/_assets/), datos estructurados, tiempo de lectura y números de página. */
class TemplateFeaturesTest extends TestCase
{
    use InteractsWithBlogs, RefreshDatabase;

    private const TEMPLATE = 'assettest';
    private const CSS = "body { color: #123; }\n";

    private Blog $blog;
    private string $templateDir;
    private string $font;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBlogs();

        $this->templateDir = resource_path('views/blogs/'.self::TEMPLATE);
        File::deleteDirectory($this->templateDir);
        File::copyDirectory(resource_path('views/blogs/demoblog'), $this->templateDir);

        // La plantilla de prueba enlaza su CSS con @templateAsset en vez de /css/blog.css.
        foreach (['article', 'index'] as $view) {
            $path = "{$this->templateDir}/{$view}.blade.php";
            File::put($path, str_replace('href="/css/blog.css"', 'href="@templateAsset(\'blog.css\')"', File::get($path)));
        }

        File::ensureDirectoryExists($this->templateDir.'/assets/fonts');
        File::put($this->templateDir.'/assets/blog.css', self::CSS);
        $this->font = "wOF2".random_bytes(64);
        File::put($this->templateDir.'/assets/fonts/texto.woff2', $this->font);

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

    private function publishArticle(string $title, int $day = 1, ?string $body = null): Article
    {
        $service = app(ArticleService::class);
        $article = $service->createDraft($this->blog);
        $service->save($article, $this->chooseCategory($this->blog) + [
            'title' => $title, 'body_html' => $body ?? "<p>Cuerpo de prueba</p>", 'excerpt' => "Extracto de {$title}",
            'published_at' => sprintf('2026-11-%02dT09:00', $day), 'version' => $article->version,
        ]);
        PublishArticle::dispatchSync($article->id);

        return $article->fresh();
    }

    private function remote(string $path): string
    {
        return file_get_contents($this->remoteDir.'/'.$path);
    }

    private function version(string $contents): string
    {
        return substr(sha1($contents), 0, 8);
    }

    private function editAsset(string $file, string $contents): void
    {
        $path = "{$this->templateDir}/assets/{$file}";
        File::put($path, $contents);
        touch($path, time() + 5);
        clearstatcache();
        $this->artisan('view:clear');
    }

    /** @return array<string,mixed> */
    private function jsonLd(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m), 'falta el bloque JSON-LD');
        $data = json_decode($m[1], true);
        $this->assertIsArray($data, 'el JSON-LD no es válido');

        return $data;
    }

    // ───────── assets de la plantilla ─────────

    public function test_publishing_uploads_the_template_assets_and_links_them_with_a_content_hash(): void
    {
        $article = $this->publishArticle('Con estilos');

        $this->assertSame(self::CSS, $this->remote('_assets/blog.css'));
        $this->assertSame($this->font, $this->remote('_assets/fonts/texto.woff2')); // binario intacto

        $html = $this->remote($article->remote_path);
        $this->assertStringContainsString('href="/blog/_assets/blog.css?v='.$this->version(self::CSS).'"', $html);
        $this->assertStringContainsString('href="/blog/_assets/blog.css?v='.$this->version(self::CSS).'"', $this->remote('index.html'));
    }

    public function test_assets_are_not_uploaded_again_when_nothing_changed(): void
    {
        $this->publishArticle('Primero', 1);
        $old = time() - 1000;
        touch($this->remoteDir.'/_assets/blog.css', $old);
        touch($this->remoteDir.'/_assets/fonts/texto.woff2', $old);
        clearstatcache();

        $this->publishArticle('Segundo', 2);
        clearstatcache();

        $this->assertSame($old, filemtime($this->remoteDir.'/_assets/blog.css'));
        $this->assertSame($old, filemtime($this->remoteDir.'/_assets/fonts/texto.woff2'));
    }

    public function test_changing_the_css_uploads_the_new_file_and_changes_the_link_until_the_blog_is_regenerated(): void
    {
        $first = $this->publishArticle('Primero', 1);
        $new = "body { color: #fff; }\n";
        $this->editAsset('blog.css', $new);

        $second = $this->publishArticle('Segundo', 2);

        $this->assertSame($new, $this->remote('_assets/blog.css'));
        $this->assertStringContainsString('blog.css?v='.$this->version($new), $this->remote($second->remote_path));
        $this->assertStringContainsString('blog.css?v='.$this->version(self::CSS), $this->remote($first->remote_path)); // aún sin regenerar

        $d = app(BlogRegenerator::class)->runAll(app(BlogRegenerator::class)->start($this->blog));

        $this->assertSame('ok', $d->status, (string) $d->error_message);
        $this->assertStringContainsString('blog.css?v='.$this->version($new), $this->remote($first->fresh()->remote_path));
    }

    public function test_a_removed_asset_disappears_from_the_server_with_its_empty_folder(): void
    {
        $this->publishArticle('Primero', 1);
        $this->assertFileExists($this->remoteDir.'/_assets/fonts/texto.woff2');

        File::deleteDirectory($this->templateDir.'/assets/fonts');
        clearstatcache();
        $this->publishArticle('Segundo', 2);

        $this->assertFileDoesNotExist($this->remoteDir.'/_assets/fonts/texto.woff2');
        $this->assertDirectoryDoesNotExist($this->remoteDir.'/_assets/fonts');
        $this->assertFileExists($this->remoteDir.'/_assets/blog.css');
    }

    public function test_only_design_files_are_published_and_the_validator_names_the_rest(): void
    {
        File::put($this->templateDir.'/assets/puerta.php', '<?php echo 1;');
        File::put($this->templateDir.'/assets/.htaccess', 'Options +Includes');
        File::put($this->templateDir.'/assets/pagina.html', '<p>x</p>');
        File::put($this->templateDir.'/assets/raro nombre.css', 'a{}');

        $this->publishArticle('Con archivos raros');

        foreach (['puerta.php', '.htaccess', 'pagina.html', 'raro nombre.css'] as $name) {
            $this->assertFileDoesNotExist($this->remoteDir.'/_assets/'.$name);
        }

        $errors = app(TemplateValidator::class)->validate(self::TEMPLATE);
        $text = implode("\n", $errors);

        foreach (['puerta.php', '.htaccess', 'pagina.html', 'raro nombre.css'] as $name) {
            $this->assertStringContainsString("assets/{$name}", $text);
        }

        $this->assertTrue(TemplateAssets::isSafeName('fonts/texto.woff2'));
        $this->assertFalse(TemplateAssets::isSafeName('../fuera.css'));
        $this->assertFalse(TemplateAssets::isSafeName('a//b.css'));
        $this->assertFalse(TemplateAssets::isSafeName('x.PHP'));
    }

    public function test_a_reference_to_a_missing_asset_fails_validation_and_rendering(): void
    {
        $path = $this->templateDir.'/index.blade.php';
        File::put($path, str_replace("@templateAsset('blog.css')", "@templateAsset('no-existe.css')", File::get($path)));
        touch($path, time() + 5);
        $this->artisan('view:clear');

        $errors = implode("\n", app(TemplateValidator::class)->validate(self::TEMPLATE));
        $this->assertStringContainsString("@templateAsset('no-existe.css')", $errors);

        // Blade envuelve el error de la plantilla; el mensaje sigue diciendo qué archivo falta.
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('assets/no-existe.css');
        app(ArticleRenderer::class)->renderIndex($this->blog, [], 1, 1);
    }

    public function test_a_template_with_assets_and_structured_data_passes_the_validator(): void
    {
        $this->assertSame([], app(TemplateValidator::class)->validate(self::TEMPLATE));
        $this->assertSame([], app(TemplateValidator::class)->validate('demoblog'));
    }

    public function test_assets_without_a_folder_change_nothing_for_other_templates(): void
    {
        $other = $this->makeBlog(['template' => 'demoblog', 'domain' => 'otro.com', 'base_url' => 'https://otro.com']);

        $this->assertSame([], app(TemplateAssets::class)->files($other));
    }

    // ───────── plantilla de Dialogfy ─────────

    public function test_the_dialogfy_template_is_valid_and_publishes_with_its_own_stylesheet_header_and_footer(): void
    {
        $this->assertSame([], app(TemplateValidator::class)->validate('dialogfy'));

        $this->blog->update(['template' => 'dialogfy']);
        $article = $this->publishArticle('Los agentes de IA cambian la atención', 1, '<h2>Subtítulo</h2><p>Texto</p>');

        $css = file_get_contents(resource_path('views/blogs/dialogfy/assets/blog.css'));
        $this->assertSame($css, $this->remote('_assets/blog.css'));

        $page = $this->remote($article->remote_path);
        $this->assertStringContainsString('href="https://dialogfy.com/styles.css"', $page);
        $this->assertStringContainsString('href="/blog/_assets/blog.css?v='.$this->version($css).'"', $page);
        $this->assertStringContainsString('<symbol id="i-flecha"', $page);          // sprite de iconos (cabecera)
        $this->assertStringContainsString('class="pie__grid"', $page);              // pie
        $this->assertStringContainsString('src="https://dialogfy.com/main.js"', $page);
        $this->assertStringContainsString('<div class="post-body">', $page);
        $this->assertSame(1, preg_match_all('#<h1[\s>]#', $page));

        $index = $this->remote('index.html');
        $this->assertStringContainsString('class="blog-tarjeta"', $index);
        $this->assertStringContainsString('nav class="blog-categorias"', $index); // menú de categorías (hay una con artículos)
        $this->assertStringContainsString('· 1 min', $index);                     // tiempo de lectura en la tarjeta
    }

    public function test_dialogfy_shares_its_header_and_footer_in_ssi_mode(): void
    {
        $this->blog->update(['template' => 'dialogfy', 'ssi_enabled' => true]);
        $article = $this->publishArticle('Con SSI', 1);

        $page = $this->remote($article->remote_path);
        $this->assertStringContainsString('<!--#include virtual="/blog/_inc/header.html" -->', $page);
        $this->assertStringNotContainsString('<symbol id="i-flecha"', $page);
        $this->assertStringContainsString('<symbol id="i-flecha"', $this->remote('_inc/header.html'));
        $this->assertStringContainsString('class="pie__grid"', $this->remote('_inc/footer.html'));
    }

    // ───────── datos estructurados ─────────

    public function test_the_article_carries_valid_structured_data_with_breadcrumbs(): void
    {
        $article = $this->publishArticle('Mi artículo', 3);
        $ld = $this->jsonLd($this->remote($article->remote_path));
        $graph = collect($ld['@graph'])->keyBy('@type');

        $this->assertSame('https://schema.org', $ld['@context']);
        $this->assertSame('Mi artículo', $graph['BlogPosting']['headline']);
        $this->assertSame('https://cliente-a.com/blog/general/mi-articulo', $graph['BlogPosting']['url']);
        $this->assertStringStartsWith('2026-11-03T09:00:00', $graph['BlogPosting']['datePublished']);
        $this->assertSame('General', $graph['BlogPosting']['articleSection']);

        $crumbs = array_column($graph['BreadcrumbList']['itemListElement'], 'name');
        $this->assertSame(['Cliente A', 'Blog', 'General', 'Mi artículo'], $crumbs);
        $this->assertSame([1, 2, 3, 4], array_column($graph['BreadcrumbList']['itemListElement'], 'position'));
    }

    public function test_structured_data_survives_quotes_ampersands_and_a_closing_script_tag_in_the_title(): void
    {
        $title = 'Él dijo "hola" & </script><b>x</b> \'adiós\'';
        $article = $this->publishArticle($title, 4);
        $html = $this->remote($article->remote_path);

        $this->assertStringNotContainsString('</script><b>', $html);
        $graph = collect($this->jsonLd($html)['@graph'])->keyBy('@type');
        $this->assertSame($title, $graph['BlogPosting']['headline']); // el JSON decodificado es el título tal cual
    }

    public function test_listings_carry_structured_data_for_the_blog_and_for_each_category(): void
    {
        $this->publishArticle('Uno', 1);
        $this->publishArticle('Dos', 2);

        $general = collect($this->jsonLd($this->remote('index.html'))['@graph'])->keyBy('@type');
        $this->assertSame(2, count($general['Blog']['blogPost']));
        $this->assertSame(['Cliente A', 'Blog'], array_column($general['BreadcrumbList']['itemListElement'], 'name'));

        $category = collect($this->jsonLd($this->remote('general/index.html'))['@graph'])->keyBy('@type');
        $this->assertSame('General', $category['CollectionPage']['name']);
        $this->assertSame(2, count($category['CollectionPage']['mainEntity']['itemListElement']));
        $this->assertSame(['Cliente A', 'Blog', 'General'], array_column($category['BreadcrumbList']['itemListElement'], 'name'));
    }

    // ───────── tiempo de lectura ─────────

    public function test_reading_time_is_calculated_from_the_words_with_a_minimum_of_one_minute(): void
    {
        $long = $this->publishArticle('Largo', 1, '<p>'.str_repeat('palabra ', 450).'</p>');
        $short = $this->publishArticle('Corto', 2, '<p>Muy poco</p>');

        $this->assertStringContainsString('3 min de lectura', $this->remote($long->remote_path)); // 450 / 200 → 3
        $this->assertStringContainsString('1 min de lectura', $this->remote($short->remote_path));
        $this->assertSame(3, $long->live_summary['reading_minutes']);
        $this->assertSame(1, $short->live_summary['reading_minutes']);

        $this->assertStringContainsString('· 3 min', $this->remote('index.html'));
    }

    public function test_a_listing_still_builds_for_articles_published_before_the_reading_time_existed(): void
    {
        $article = $this->publishArticle('Antiguo', 1);
        $summary = $article->live_summary;
        unset($summary['reading_minutes']);
        $article->forceFill(['live_summary' => $summary])->saveQuietly();

        $built = app(\App\Services\ListingBuilder::class)->build($this->blog->fresh());

        $this->assertStringContainsString('Antiguo', $built['index.html']);
        $this->assertStringNotContainsString(' min', $built['index.html']); // sin dato, la plantilla no pinta el tiempo
    }

    // ───────── números de página ─────────

    public function test_page_window_shows_first_last_and_two_around_the_current_page(): void
    {
        $url = fn (int $n) => "/p/{$n}";
        $render = fn (array $pages) => implode(' ', array_map(fn ($p) => $p->gap ? '…' : ($p->current ? "[{$p->number}]" : (string) $p->number), $pages));
        $renderer = app(ArticleRenderer::class);

        $this->assertSame('1 … 4 5 [6] 7 8 … 20', $render($renderer->pageWindow(6, 20, $url)));
        $this->assertSame('[1] 2 3 … 20', $render($renderer->pageWindow(1, 20, $url)));
        $this->assertSame('1 … 18 19 [20]', $render($renderer->pageWindow(20, 20, $url)));
        $this->assertSame('1 2 [3] 4 5', $render($renderer->pageWindow(3, 5, $url)));
        $this->assertSame('[1]', $render($renderer->pageWindow(1, 1, $url)));
        $this->assertSame('/p/4', $renderer->pageWindow(6, 20, $url)[2]->url);
    }

    public function test_listing_pages_render_numbered_links_with_the_current_one_marked(): void
    {
        foreach (range(1, 5) as $i) {
            $this->publishArticle("Artículo {$i}", $i);
        }

        $page2 = $this->remote('pagina/2/index.html');

        $this->assertStringContainsString('aria-current="page">2</span>', $page2);
        $this->assertStringContainsString('<a href="https://cliente-a.com/blog/">1</a>', $page2);
        $this->assertStringContainsString('<a href="https://cliente-a.com/blog/pagina/3/">3</a>', $page2);
    }
}
