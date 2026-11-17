<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Blog;
use App\Models\Deployment;
use App\Models\Media;
use App\Services\ArticleIndexer;
use App\Services\ArticleService;
use App\Services\BlogRegenerator;
use App\Services\Import\ImportReport;
use App\Services\Import\WordPressImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithBlogs;
use Tests\TestCase;

/** F2-5: importador del XML de WordPress. */
class WordPressImportTest extends TestCase
{
    use InteractsWithBlogs, RefreshDatabase;

    private Blog $blog;
    private string $xmlFile;
    private string $jpeg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBlogs();
        Storage::fake('local');

        $this->blog = $this->makeBlog(['name' => 'Dialog F', 'domain' => 'dialogf.com', 'base_url' => 'https://dialogf.com']);
        $this->xmlFile = tempnam(sys_get_temp_dir(), 'wxr').'.xml';
        $this->jpeg = file_get_contents($this->jpegUpload(1200, 800)->getRealPath());
    }

    protected function tearDown(): void
    {
        @unlink($this->xmlFile);
        $this->tearDownBlogs();
        parent::tearDown();
    }

    // ───────── construcción del XML ─────────

    private function cdata(string $s): string
    {
        return '<![CDATA['.str_replace(']]>', ']]]]><![CDATA[>', $s).']]>';
    }

    /** @param array<string,mixed> $o */
    private function wpPost(array $o): string
    {
        $o += [
            'id' => 10, 'title' => 'Mi entrada', 'name' => 'mi-entrada', 'status' => 'publish', 'type' => 'post',
            'date_gmt' => '2024-03-15 08:30:00', 'content' => "Primer párrafo.\n\nSegundo párrafo.", 'excerpt' => '',
            'creator' => 'laura', 'link' => 'https://old.dialogf.com/mi-entrada/', 'meta' => [], 'attachment_url' => null,
        ];

        $meta = '';
        foreach ($o['meta'] as $k => $v) {
            $meta .= "<wp:postmeta><wp:meta_key>{$this->cdata($k)}</wp:meta_key><wp:meta_value>{$this->cdata($v)}</wp:meta_value></wp:postmeta>";
        }

        $attachment = $o['attachment_url'] ? "<wp:attachment_url>{$this->cdata($o['attachment_url'])}</wp:attachment_url>" : '';

        return <<<XML
<item>
  <title>{$this->cdata($o['title'])}</title>
  <link>{$o['link']}</link>
  <dc:creator>{$this->cdata($o['creator'])}</dc:creator>
  <content:encoded>{$this->cdata($o['content'])}</content:encoded>
  <excerpt:encoded>{$this->cdata($o['excerpt'])}</excerpt:encoded>
  <wp:post_id>{$o['id']}</wp:post_id>
  <wp:post_date>{$this->cdata($o['date_gmt'])}</wp:post_date>
  <wp:post_date_gmt>{$this->cdata($o['date_gmt'])}</wp:post_date_gmt>
  <wp:post_name>{$this->cdata($o['name'])}</wp:post_name>
  <wp:status>{$this->cdata($o['status'])}</wp:status>
  <wp:post_type>{$this->cdata($o['type'])}</wp:post_type>
  {$attachment}
  {$meta}
</item>
XML;
    }

    private function writeXml(string ...$items): void
    {
        $body = implode("\n", $items);
        file_put_contents($this->xmlFile, <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
  xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:dc="http://purl.org/dc/elements/1.1/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
  <title>Dialog F</title>
  <link>https://old.dialogf.com</link>
  <wp:wxr_version>1.2</wp:wxr_version>
  {$body}
</channel>
</rss>
XML);
    }

    private function import(array $options = []): ImportReport
    {
        return app(WordPressImporter::class)->import($this->blog, $this->xmlFile, $options + ['download' => false]);
    }

    private function articleJson(Article $article): array
    {
        return json_decode(Storage::disk('blogs')->get("{$this->blog->id}/articles/{$article->shard()}/{$article->public_id}.json"), true);
    }

    // ───────── datos básicos ─────────

    public function test_keeps_title_slug_date_author_excerpt_and_seo(): void
    {
        $this->writeXml($this->wpPost([
            'title' => 'Cómo elegir &amp; comprar', 'name' => 'como-elegir', 'date_gmt' => '2024-07-01 22:30:00',
            'creator' => 'Laura Gómez', 'excerpt' => '<p>Un <b>resumen</b> corto.</p>',
            'meta' => ['_yoast_wpseo_title' => 'Título SEO', '_yoast_wpseo_metadesc' => 'Descripción SEO'],
        ]));

        $report = $this->import();

        $this->assertSame(1, $report->count(ImportReport::IMPORTED));
        $article = Article::sole();
        $json = $this->articleJson($article);

        $this->assertSame('Cómo elegir & comprar', $article->title);
        $this->assertSame('como-elegir', $article->slug);
        $this->assertSame('000001', $article->public_id, 'recibe un ID nuevo del CMS');
        $this->assertSame('wp:10', $article->source_ref);
        $this->assertSame('Un resumen corto.', $json['excerpt']);
        $this->assertSame('Laura Gómez', $json['author']['name']);
        $this->assertSame('Título SEO', $json['seo']['meta_title']);
        $this->assertSame('Descripción SEO', $json['seo']['meta_description']);
        // GMT 22:30 del 1 de julio = 00:30 del 2 de julio en Madrid (verano, UTC+2).
        $this->assertSame('2024-07-01 22:30:00', $article->published_at->utc()->format('Y-m-d H:i:s'));
        $this->assertStringStartsWith('2024-07-02T00:30:00', $json['published_at']);
        $this->assertSame('wordpress', $json['import']['source']);
        $this->assertSame('10', $json['import']['wp_id']);
        $this->assertSame('https://old.dialogf.com/mi-entrada/', $json['import']['url']);
    }

    public function test_imported_articles_are_drafts_until_published(): void
    {
        $this->writeXml($this->wpPost(['id' => 1]), $this->wpPost(['id' => 2, 'name' => 'otra', 'status' => 'draft']));

        $this->import();

        $this->assertSame(2, Article::where('status', Article::STATUS_DRAFT)->count());
        $this->assertSame(0, Article::whereNotNull('remote_path')->count());
        $this->assertTrue((bool) Article::where('source_ref', 'wp:1')->value('import_publish'));
        $this->assertFalse((bool) Article::where('source_ref', 'wp:2')->value('import_publish'));
        $this->assertDirectoryDoesNotExist($this->remoteDir.'/img');
    }

    public function test_only_posts_are_imported_and_the_rest_is_reported(): void
    {
        $this->writeXml(
            $this->wpPost(['id' => 1]),
            $this->wpPost(['id' => 2, 'type' => 'page', 'name' => 'contacto']),
            $this->wpPost(['id' => 3, 'type' => 'attachment', 'name' => 'foto', 'status' => 'inherit']),
            $this->wpPost(['id' => 4, 'name' => 'privada', 'status' => 'private']),
            $this->wpPost(['id' => 5, 'name' => 'papelera', 'status' => 'trash']),
            $this->wpPost(['id' => 6, 'name' => 'futura', 'status' => 'future']),
        );

        $report = $this->import();

        $this->assertSame(2, Article::count()); // publish + future
        $this->assertSame(2, $report->count(ImportReport::IMPORTED));
        $this->assertSame(2, $report->count(ImportReport::SKIPPED));
        $this->assertStringContainsString('private', collect($report->rows)->firstWhere('wp_id', '4')['notes']);
    }

    public function test_second_run_is_idempotent(): void
    {
        $this->writeXml($this->wpPost(['id' => 1]), $this->wpPost(['id' => 2, 'name' => 'dos']));

        $this->import();
        $second = $this->import();

        $this->assertSame(2, Article::count());
        $this->assertSame(0, $second->count(ImportReport::IMPORTED));
        $this->assertSame(2, $second->count(ImportReport::SKIPPED));
        $this->assertSame('ya importado', collect($second->rows)->first()['notes']);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->writeXml($this->wpPost(['id' => 1, 'content' => '<img src="https://old.dialogf.com/a.jpg">']));
        Http::fake();

        $report = $this->import(['dry_run' => true, 'download' => true]);

        $this->assertSame(1, $report->count(ImportReport::DRY_RUN));
        $this->assertSame(0, Article::count());
        $this->assertSame(0, Media::count());
        Http::assertNothingSent();
        $this->assertSame([], Storage::disk('blogs')->allFiles());
    }

    public function test_slug_conflicts_are_resolved_and_reported(): void
    {
        $this->writeXml(
            $this->wpPost(['id' => 1, 'name' => 'repetido']),
            $this->wpPost(['id' => 2, 'name' => 'repetido', 'title' => 'Otra']),
            $this->wpPost(['id' => 3, 'name' => 'sitemap', 'title' => 'Mapa']),
            $this->wpPost(['id' => 4, 'name' => '', 'title' => 'Sin slug alguno']),
            $this->wpPost(['id' => 5, 'name' => 'caf%c3%a9-con-leche', 'title' => 'Café']),
        );

        $report = $this->import();

        $slugs = Article::orderBy('id')->pluck('slug', 'source_ref')->all();
        $this->assertSame('repetido', $slugs['wp:1']);
        $this->assertSame('repetido-2', $slugs['wp:2']);
        $this->assertSame('sitemap-2', $slugs['wp:3']);
        $this->assertSame('sin-slug-alguno', $slugs['wp:4']);
        $this->assertSame('cafe-con-leche', $slugs['wp:5']);

        $rows = collect($report->rows)->keyBy('wp_id');
        $this->assertStringContainsString('repetido-2', $rows['2']['notes']);
        $this->assertStringContainsString('sitemap-2', $rows['3']['notes']);
        $this->assertStringContainsString('sin slug', $rows['4']['notes']);
    }

    public function test_entry_without_title_is_an_error_not_a_crash(): void
    {
        $this->writeXml($this->wpPost(['id' => 1, 'title' => '']), $this->wpPost(['id' => 2, 'name' => 'ok']));

        $report = $this->import();

        $this->assertSame(1, $report->count(ImportReport::ERROR));
        $this->assertSame(1, $report->count(ImportReport::IMPORTED));
    }

    // ───────── contenido ─────────

    public function test_classic_editor_text_gets_paragraphs_and_headings_are_normalised(): void
    {
        $this->writeXml($this->wpPost(['content' => "<h1>Grande</h1>\n\nPrimera línea\nsegunda línea\n\n<h5>Pequeño</h5>\n\n<ul>\n<li>a</li>\n<li>b</li>\n</ul>"]));

        $this->import();

        $body = $this->articleJson(Article::sole())['body_html'];

        $this->assertStringContainsString('<h2>Grande</h2>', $body);
        $this->assertStringContainsString('<h4>Pequeño</h4>', $body);
        $this->assertStringContainsString('<p>Primera línea<br', $body);
        $this->assertStringContainsString('<li>a</li>', $body);
        $this->assertStringNotContainsString('<h1', $body);
    }

    public function test_gutenberg_markup_is_cleaned(): void
    {
        $this->writeXml($this->wpPost(['content' => "<!-- wp:paragraph -->\n<p>Hola <strong>mundo</strong></p>\n<!-- /wp:paragraph -->"]));

        $this->import();

        $body = $this->articleJson(Article::sole())['body_html'];
        $this->assertStringContainsString('<p>Hola <strong>mundo</strong></p>', $body);
        $this->assertStringNotContainsString('wp:paragraph', $body);
    }

    public function test_shortcodes_and_unsupported_elements_are_removed_and_reported(): void
    {
        $this->writeXml($this->wpPost(['content' => "Texto [gallery ids=\"1,2\"] y más.\n\n<iframe src=\"https://youtube.com/embed/x\"></iframe>\n\n<script>alert(1)</script>"]));

        $report = $this->import();

        $body = $this->articleJson(Article::sole())['body_html'];
        $this->assertStringNotContainsString('[gallery', $body);
        $this->assertStringNotContainsString('<iframe', $body);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('alert(1)', $body);

        $notes = $report->rows[0]['notes'];
        $this->assertStringContainsString('[gallery]', $notes);
        $this->assertStringContainsString('<iframe>', $notes);
        $this->assertStringContainsString('<script>', $notes);
        $this->assertSame(1, $report->warnings());
    }

    public function test_caption_shortcode_becomes_a_figure(): void
    {
        Http::fake(['old.dialogf.com/*' => Http::response($this->jpeg, 200)]);
        $this->writeXml($this->wpPost(['content' => '[caption id="attachment_5" align="aligncenter" width="300"]<img src="https://old.dialogf.com/wp-content/uploads/2024/03/gato.jpg" alt="Un gato" /> El gato del vecino[/caption]']));

        $this->import(['download' => true]);

        $body = $this->articleJson(Article::sole())['body_html'];
        $this->assertStringContainsString('<figure>', $body);
        $this->assertStringContainsString('<figcaption>El gato del vecino</figcaption>', $body);
        $this->assertStringContainsString('alt="Un gato"', $body);
    }

    // ───────── imágenes ─────────

    public function test_body_images_are_downloaded_recoded_and_rewritten(): void
    {
        Http::fake(['old.dialogf.com/*' => Http::response($this->jpeg, 200)]);
        app(\App\Services\CategoryService::class)->create($this->blog, ['name' => 'Reserva', 'is_default' => true]); // sin esto, la nota de «sin categoría» ensucia el informe
        $this->writeXml($this->wpPost(['content' => '<p>Mira: <img class="alignnone size-medium" src="https://old.dialogf.com/wp-content/uploads/2024/03/foto.jpg" alt="Foto" srcset="x 300w" /></p>']));

        $report = $this->import(['download' => true]);

        $article = Article::sole();
        $media = Media::sole();
        $this->assertSame($article->id, $media->article_id);
        $this->assertLessThanOrEqual(1600, $media->width);
        $this->assertStringStartsWith('img/', $media->remote_path);

        $body = $this->articleJson($article)['body_html'];
        $this->assertStringContainsString('src="/blog/'.$media->remote_path.'"', $body);
        $this->assertStringNotContainsString('old.dialogf.com', $body);
        $this->assertStringNotContainsString('srcset', $body);
        $this->assertSame('', $report->rows[0]['notes']);
    }

    public function test_thumbnail_suffix_falls_back_to_the_original_file(): void
    {
        Http::fake(function ($request) {
            return str_contains($request->url(), '-300x200') ? Http::response('no', 404) : Http::response($this->jpeg, 200);
        });
        $this->writeXml($this->wpPost(['content' => '<p><img src="https://old.dialogf.com/wp-content/uploads/2024/03/foto-300x200.jpg"></p>']));

        $this->import(['download' => true]);

        $this->assertSame(1, Media::count());
        Http::assertSent(fn ($r) => $r->url() === 'https://old.dialogf.com/wp-content/uploads/2024/03/foto.jpg');
    }

    public function test_unavailable_or_unsupported_images_are_reported_not_fatal(): void
    {
        Http::fake(['old.dialogf.com/*' => Http::response('not found', 404)]);
        $this->writeXml($this->wpPost(['content' => '<p>Texto</p><p><img src="https://old.dialogf.com/wp-content/uploads/a.jpg"></p>']));

        $report = $this->import(['download' => true]);

        $this->assertSame(1, $report->count(ImportReport::IMPORTED));
        $this->assertStringContainsString('imagen no importada', $report->rows[0]['notes']);
        $this->assertStringContainsString('a.jpg', $report->rows[0]['notes']);
        $this->assertStringNotContainsString('<img', $this->articleJson(Article::sole())['body_html']);
        $this->assertSame(0, Media::count());
    }

    public function test_gif_images_are_reported_as_unsupported(): void
    {
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        Http::fake(['old.dialogf.com/*' => Http::response($gif, 200)]);
        $this->writeXml($this->wpPost(['content' => '<p><img src="https://old.dialogf.com/wp-content/uploads/anim.gif"></p>']));

        $report = $this->import(['download' => true]);

        $this->assertStringContainsString('JPEG, PNG y WebP', $report->rows[0]['notes']);
    }

    public function test_featured_image_comes_from_thumbnail_id_with_its_alt_text(): void
    {
        Http::fake(['old.dialogf.com/*' => Http::response($this->jpeg, 200)]);
        $this->writeXml(
            $this->wpPost(['id' => 99, 'type' => 'attachment', 'status' => 'inherit', 'name' => 'portada', 'attachment_url' => 'https://old.dialogf.com/wp-content/uploads/2024/03/portada.jpg', 'meta' => ['_wp_attachment_image_alt' => 'Portada del artículo']]),
            $this->wpPost(['id' => 1, 'meta' => ['_thumbnail_id' => '99']]),
        );

        $this->import(['download' => true]);

        $featured = $this->articleJson(Article::sole())['featured_image'];
        $this->assertSame(Media::sole()->remote_path, $featured['file']);
        $this->assertSame('Portada del artículo', $featured['alt']);
    }

    public function test_images_can_come_from_a_local_uploads_copy_without_internet(): void
    {
        $dir = sys_get_temp_dir().'/wpuploads-'.bin2hex(random_bytes(3));
        File::ensureDirectoryExists($dir.'/2024/03');
        file_put_contents($dir.'/2024/03/local.jpg', $this->jpeg);
        Http::fake();

        app(\App\Services\CategoryService::class)->create($this->blog, ['name' => 'Reserva', 'is_default' => true]); // sin esto, la nota de «sin categoría» ensucia el informe
        $this->writeXml($this->wpPost(['content' => '<p><img src="https://old.dialogf.com/wp-content/uploads/2024/03/local.jpg"></p>']));
        $report = $this->import(['download' => true, 'images_dir' => $dir]);

        $this->assertSame(1, Media::count());
        Http::assertNothingSent();
        $this->assertSame('', $report->rows[0]['notes']);

        File::deleteDirectory($dir);
    }

    public function test_local_images_dir_cannot_be_escaped_with_dot_dot(): void
    {
        $root = sys_get_temp_dir().'/wproot-'.bin2hex(random_bytes(3));
        File::ensureDirectoryExists($root.'/uploads');
        file_put_contents($root.'/secret.jpg', $this->jpeg);
        Http::fake();

        $this->writeXml($this->wpPost(['content' => '<p><img src="https://old.dialogf.com/wp-content/uploads/../../secret.jpg"></p>']));
        $this->import(['download' => false, 'images_dir' => $root.'/uploads']);

        $this->assertSame(0, Media::count());

        File::deleteDirectory($root);
    }

    public function test_downloads_never_target_the_internal_network(): void
    {
        Http::fake();
        $this->writeXml($this->wpPost(['content' => '<p><img src="http://127.0.0.1/admin.jpg"><img src="http://192.168.1.10/x.jpg"><img src="http://localhost/y.jpg"><img src="http://169.254.169.254/latest/meta-data"></p>']));

        $report = $this->import(['download' => true]);

        Http::assertNothingSent();
        $this->assertSame(0, Media::count());
        $this->assertSame(4, substr_count($report->rows[0]['notes'], 'imagen no importada'));
    }

    // ───────── enlaces ─────────

    public function test_internal_links_between_imported_posts_are_rewritten(): void
    {
        $this->writeXml(
            $this->wpPost(['id' => 1, 'name' => 'primero', 'link' => 'https://old.dialogf.com/2024/03/primero/', 'content' => '<p>Lee <a href="https://old.dialogf.com/2024/03/segundo">el segundo</a>, la <a href="https://old.dialogf.com/contacto/">página de contacto</a> y <a href="https://otra-web.com/x">otra web</a>.</p>']),
            $this->wpPost(['id' => 2, 'name' => 'segundo', 'title' => 'Segundo', 'link' => 'https://old.dialogf.com/2024/03/segundo/']),
        );

        $report = $this->import();

        $body = $this->articleJson(Article::where('source_ref', 'wp:1')->first())['body_html'];
        $this->assertStringContainsString('href="/blog/general/segundo"', $body);
        $this->assertStringContainsString('href="https://otra-web.com/x"', $body);
        $this->assertStringContainsString('1 enlace(s) internos', $report->rows[0]['notes']);
    }

    public function test_image_wrapped_in_a_link_to_its_own_file_is_unwrapped(): void
    {
        Http::fake(['old.dialogf.com/*' => Http::response($this->jpeg, 200)]);
        $this->writeXml($this->wpPost(['content' => '<p><a href="https://old.dialogf.com/wp-content/uploads/2024/03/grande.jpg"><img src="https://old.dialogf.com/wp-content/uploads/2024/03/grande-300x200.jpg" alt="x"></a></p>']));

        $this->import(['download' => true]);

        $body = $this->articleJson(Article::sole())['body_html'];
        $this->assertStringContainsString('<img', $body);
        $this->assertStringNotContainsString('<a ', $body);
    }

    // ───────── publicación en lote ─────────

    public function test_bulk_publish_uploads_published_posts_once_and_leaves_drafts(): void
    {
        Http::fake(['old.dialogf.com/*' => Http::response($this->jpeg, 200)]);
        $this->writeXml(
            $this->wpPost(['id' => 1, 'name' => 'uno', 'title' => 'Uno', 'date_gmt' => '2024-01-10 10:00:00', 'content' => '<p><img src="https://old.dialogf.com/wp-content/uploads/a.jpg"></p>']),
            $this->wpPost(['id' => 2, 'name' => 'dos', 'title' => 'Dos', 'date_gmt' => '2024-02-10 10:00:00']),
            $this->wpPost(['id' => 3, 'name' => 'borrador', 'title' => 'Borrador', 'status' => 'draft']),
        );
        $this->import(['download' => true]);

        $regenerator = app(BlogRegenerator::class);
        $deployment = $regenerator->startImport($this->blog);
        $this->assertSame(2, $deployment->total_items);

        $deployment = $regenerator->runAll($deployment);

        $this->assertSame(Deployment::STATUS_OK, $deployment->status);
        $this->assertSame(2, $deployment->done_items);

        $one = Article::where('source_ref', 'wp:1')->first();
        $this->assertSame(Article::STATUS_PUBLISHED, $one->status);
        $this->assertNotNull($one->first_published_at);
        $this->assertFileExists($this->remoteDir.'/'.$one->remote_path);
        $this->assertFileExists($this->remoteDir.'/'.Media::sole()->remote_path);
        $this->assertSame(Article::STATUS_DRAFT, Article::where('source_ref', 'wp:3')->first()->status);

        $htaccess = file_get_contents($this->remoteDir.'/.htaccess');
        $this->assertStringContainsString('RewriteRule ^(.+)$ $1.html [END]', $htaccess); // sin una regla por artículo
        $this->assertStringNotContainsString('borrador', $htaccess);

        $sitemap = file_get_contents($this->remoteDir.'/sitemap.xml');
        $this->assertStringContainsString('https://dialogf.com/blog/general/uno', $sitemap);
        $this->assertStringNotContainsString('borrador', $sitemap);
        $this->assertStringContainsString('Dos', file_get_contents($this->remoteDir.'/index.html'));

        // Y ya no queda nada pendiente: una segunda publicación no hace nada.
        $this->assertSame(0, $regenerator->startImport($this->blog)->total_items);
    }

    public function test_imported_article_that_cannot_be_published_stays_a_draft_and_is_reported(): void
    {
        $this->writeXml($this->wpPost(['id' => 1, 'name' => 'ok', 'title' => 'Bien']), $this->wpPost(['id' => 2, 'name' => 'vacio', 'title' => 'Vacío', 'content' => '']));
        $this->import();

        $regenerator = app(BlogRegenerator::class);
        $deployment = $regenerator->runAll($regenerator->startImport($this->blog));

        $this->assertSame(Deployment::STATUS_ERROR, $deployment->status);
        $this->assertSame(1, $deployment->failed_items);
        $this->assertSame(Article::STATUS_PUBLISHED, Article::where('source_ref', 'wp:1')->first()->status);
        $this->assertSame(Article::STATUS_DRAFT, Article::where('source_ref', 'wp:2')->first()->status);
        $this->assertStringContainsString('Falta', Deployment::where('action', 'regenerate')->where('status', 'error')->value('error_message'));
    }

    public function test_editing_an_imported_article_keeps_its_origin(): void
    {
        $this->writeXml($this->wpPost(['id' => 7]));
        $this->import();

        $article = Article::sole();
        app(ArticleService::class)->save($article, ['title' => 'Editado', 'body_html' => '<p>x</p>', 'published_at' => '2024-03-15T10:00', 'version' => $article->version]);

        $this->assertSame('7', $this->articleJson($article)['import']['wp_id']);
    }

    public function test_reindex_restores_the_source_reference(): void
    {
        $this->writeXml($this->wpPost(['id' => 7]));
        $this->import();

        Article::query()->forceDelete();
        app(ArticleIndexer::class)->reindex($this->blog);

        $this->assertSame('wp:7', Article::sole()->source_ref);
        // Y volver a importar no duplica.
        $this->assertSame(0, $this->import()->count(ImportReport::IMPORTED));
    }

    // ───────── informe y comando ─────────

    public function test_report_csv_maps_old_urls_to_new_ones(): void
    {
        $this->writeXml($this->wpPost(['id' => 1, 'name' => 'uno', 'title' => 'Con; punto y coma', 'link' => 'https://old.dialogf.com/uno/']));

        $report = $this->import();
        $path = $report->save($this->blog->id);

        $csv = Storage::disk('local')->get($path);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('id_wordpress;titulo;estado;id_publiblog;url_anterior;url_nueva;avisos', $csv);
        $this->assertStringContainsString('"Con; punto y coma"', $csv);
        $this->assertStringContainsString('https://old.dialogf.com/uno/;https://dialogf.com/blog/general/uno', $csv);
    }

    public function test_command_imports_publishes_and_writes_the_report(): void
    {
        $this->writeXml($this->wpPost(['id' => 1, 'name' => 'uno', 'title' => 'Uno']), $this->wpPost(['id' => 2, 'name' => 'dos', 'title' => 'Dos']));
        $this->fakeVerification();

        $this->artisan('blog:import-wordpress', ['blog' => $this->blog->id, 'file' => $this->xmlFile, '--publish' => true, '--no-download' => true])
            ->expectsOutputToContain('Informe:')
            ->expectsOutputToContain('Publicado.')
            ->assertSuccessful();

        $this->assertSame(2, Article::where('status', Article::STATUS_PUBLISHED)->count());
        $this->assertFileExists($this->remoteDir.'/index.html');
        $this->assertNotEmpty(Storage::disk('local')->files('imports'));
    }

    public function test_command_dry_run_and_error_handling(): void
    {
        $this->writeXml($this->wpPost(['id' => 1]));

        $this->artisan('blog:import-wordpress', ['blog' => $this->blog->id, 'file' => $this->xmlFile, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, Article::count());

        $this->artisan('blog:import-wordpress', ['blog' => 999, 'file' => $this->xmlFile])->assertFailed();
        $this->artisan('blog:import-wordpress', ['blog' => $this->blog->id, 'file' => '/no/existe.xml'])->assertFailed();

        file_put_contents($this->xmlFile, 'esto no es xml');
        $this->artisan('blog:import-wordpress', ['blog' => $this->blog->id, 'file' => $this->xmlFile])->assertFailed();
    }

    public function test_command_refuses_to_publish_when_the_blog_is_not_ready(): void
    {
        $this->writeXml($this->wpPost(['id' => 1]));
        $this->blog->update(['status' => Blog::STATUS_HOSTKEY_CHANGED]);

        $this->artisan('blog:import-wordpress', ['blog' => $this->blog->id, 'file' => $this->xmlFile, '--publish' => true])->assertFailed();
        $this->assertSame(0, Article::count());
    }

    public function test_xml_external_entities_are_not_resolved(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'secret');
        file_put_contents($secret, 'CONTENIDO-SECRETO');
        $this->writeXml($this->wpPost(['id' => 1]));
        $xml = file_get_contents($this->xmlFile);
        $xml = str_replace('<?xml version="1.0" encoding="UTF-8" ?>', '<?xml version="1.0" encoding="UTF-8" ?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "file://'.str_replace('\\', '/', $secret).'">]>', $xml);
        $xml = str_replace('<title>Dialog F</title>', '<title>&xxe;</title>', $xml);
        $xml = str_replace('Primer párrafo.', '&xxe;', $xml);
        file_put_contents($this->xmlFile, $xml);

        try {
            $this->import();
        } catch (\Throwable) {
            // un XML con entidades puede rechazarse del todo: también es válido
        }

        foreach (Storage::disk('blogs')->allFiles() as $file) {
            $this->assertStringNotContainsString('CONTENIDO-SECRETO', Storage::disk('blogs')->get($file));
        }

        $this->addToAssertionCount(1); // sin archivos en el almacén tampoco hay fuga
        @unlink($secret);
    }
}
