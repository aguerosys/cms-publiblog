<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Services\ArticleRenderer;
use App\Services\HtmlSanitizer;
use App\Services\TemplateValidator;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SanitizerAndTemplateTest extends TestCase
{
    public function test_dangerous_payloads_never_reach_the_html(): void
    {
        $dirty = '<p onclick="x()">Hola</p><script>alert(1)</script><img src="/blog/img/2026/a.jpg" onerror="alert(1)">'
            .'<a href="javascript:alert(1)">mal</a><iframe src="https://evil.test"></iframe><style>p{}</style>'
            .'<form action="/x"><input name="a"></form><object data="x"></object><div>div</div><span style="color:red">s</span>';

        $clean = app(HtmlSanitizer::class)->sanitize($dirty);

        foreach (['<script', 'onerror', 'onclick', 'javascript:', '<iframe', '<style', '<form', '<input', '<object', '<div', 'style='] as $needle) {
            $this->assertStringNotContainsString($needle, strtolower($clean), "No debería quedar: {$needle}");
        }

        $this->assertStringContainsString('Hola', $clean);
        $this->assertStringContainsString('<img', $clean);
    }

    public function test_allowed_tags_survive(): void
    {
        $html = '<h2>Título</h2><p><strong>negrita</strong> y <em>cursiva</em></p><ul><li>uno</li></ul><ol><li>dos</li></ol>'
            .'<blockquote><p>cita</p></blockquote><table><thead><tr><th>A</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>'
            .'<hr><figure><img src="/blog/img/2026/a.jpg" alt="x" width="10" height="10"><figcaption>pie</figcaption></figure>';

        $clean = app(HtmlSanitizer::class)->sanitize($html);

        foreach (['<h2>', '<strong>', '<em>', '<ul>', '<ol>', '<blockquote>', '<table>', '<th>', '<td>', '<hr>', '<figure>', '<figcaption>', 'alt="x"', 'width="10"'] as $needle) {
            $this->assertStringContainsString($needle, $clean, "Debería conservarse: {$needle}");
        }
    }

    public function test_unicode_survives_and_external_links_get_noopener(): void
    {
        $blog = new Blog(['domain' => 'cliente-a.com']);
        $clean = app(HtmlSanitizer::class)->sanitize(
            '<p>Reforma de cocina: ñandú, acción, 日本</p><a href="https://otro.com/x">fuera</a><a href="https://cliente-a.com/y">dentro</a><a href="mailto:a@b.com">mail</a>',
            $blog
        );

        $this->assertStringContainsString('ñandú, acción, 日本', $clean);
        $this->assertMatchesRegularExpression('#<a href="https://otro.com/x"[^>]*rel="noopener"#', $clean);
        $this->assertDoesNotMatchRegularExpression('#cliente-a.com/y"[^>]*rel=#', $clean);
        $this->assertStringContainsString('mailto:a@b.com', $clean);
    }

    public function test_foreign_images_are_dropped(): void
    {
        $clean = app(HtmlSanitizer::class)->sanitize('<p>x</p><img src="https://evil.test/x.jpg"><img src="/blog/img/2026/ok.jpg">');

        $this->assertStringNotContainsString('evil.test', $clean);
        $this->assertStringContainsString('/blog/img/2026/ok.jpg', $clean);
    }

    public function test_demoblog_template_passes_the_validator(): void
    {
        $this->assertSame([], app(TemplateValidator::class)->validate('demoblog'));
        $this->artisan('blog:validate-template', ['template' => 'demoblog'])->assertSuccessful();
    }

    public function test_script_in_title_is_escaped_in_output(): void
    {
        $blog = new Blog(['name' => 'B', 'domain' => 'a.com', 'base_url' => 'https://a.com', 'template' => 'demoblog', 'locale' => 'es', 'timezone' => 'UTC']);

        $html = app(ArticleRenderer::class)->render($blog, [
            'public_id' => '000001', 'slug' => 'x', 'title' => '<script>alert(1)</script>',
            'published_at' => '2026-11-10T09:30:00+01:00', 'body_html' => '<p>ok</p>',
        ]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('<meta name="blog-article-id" content="000001">', $html);
        $this->assertStringContainsString('Generado por Publiblog', $html);
    }

    public function test_validator_rejects_broken_templates(): void
    {
        $dir = resource_path('views/blogs/zz_broken_test');
        File::ensureDirectoryExists($dir);

        try {
            // Dos h1, sin canonical ni blog-article-id, PHP suelto, {{ sin resolver y título sin escapar.
            File::put($dir.'/article.blade.php', implode("\n", [
                '<html><head><title>{{ $seo->meta_title }}</title></head><body>',
                '<h1>a</h1><h1>b</h1>',
                '<?php echo "x"; ?>',
                '@verbatim {{ sin_resolver }} @endverbatim',
                '<b>{!! $article->title !!}</b>',
                '</body></html>',
            ]));

            $text = implode("\n", app(TemplateValidator::class)->validate('zz_broken_test'));

            $this->assertStringContainsString('canonical', $text);
            $this->assertStringContainsString('h1', $text);
            $this->assertStringContainsString('PHP suelto', $text);
            $this->assertStringContainsString('{{', $text);
            $this->assertStringContainsString('sin escapar', $text);
            $this->assertStringContainsString('blog-article-id', $text);
        } finally {
            File::deleteDirectory($dir);
        }
    }
    public function test_missing_template_is_reported(): void
    {
        $errors = app(TemplateValidator::class)->validate('no-existe');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('no se pudo renderizar', $errors[0]);
    }
}
