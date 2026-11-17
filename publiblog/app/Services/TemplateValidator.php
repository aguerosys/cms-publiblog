<?php

namespace App\Services;

use App\Models\Blog;
use DOMDocument;
use Throwable;

/**
 * Valida que una plantilla Blade respeta el contrato de salida: renderiza artículos de
 * ejemplo y comprueba etiquetas obligatorias, variables sin resolver, PHP suelto,
 * escape de títulos con <script> y HTML interpretable.
 */
class TemplateValidator
{
    private const SCRIPT_TITLE = 'Título con <script>alert("xss")</script> dentro';

    public function __construct(private ArticleRenderer $renderer)
    {
    }

    /** @return list<string> errores (vacío si la plantilla es válida) */
    public function validate(string $template, int $templateVersion = 1): array
    {
        $blog = new Blog([
            'name' => 'Blog de prueba',
            'domain' => 'ejemplo.com',
            'base_url' => 'https://ejemplo.com',
            'locale' => 'es',
            'timezone' => 'Europe/Madrid',
            'template' => $template,
            'template_version' => $templateVersion,
        ]);

        $errors = $this->checkSource($template);

        foreach ($this->samples() as $name => $json) {
            try {
                $html = $this->renderer->render($blog, $json);
            } catch (Throwable $e) {
                $errors[] = "[{$name}] no se pudo renderizar: ".$e->getMessage();

                continue;
            }

            foreach ($this->check($html, $json) as $error) {
                $errors[] = "[{$name}] {$error}";
            }
        }

        foreach ($this->checkIndex($blog) as $error) {
            $errors[] = $error;
        }

        foreach ($this->checkSharedParts($blog) as $error) {
            $errors[] = $error;
        }

        return $errors;
    }

    /**
     * Cabecera y pie compartidos (SSI): si la plantilla usa @siteHeader/@siteFooter, los parciales tienen que
     * poder generarse solos (iguales en todas las páginas, sin artículo ni SEO) y, en modo SSI, cada vista
     * debe sustituirlos por exactamente una inclusión.
     *
     * @return list<string>
     */
    private function checkSharedParts(Blog $blog): array
    {
        $template = (string) $blog->template;
        $dir = resource_path("views/blogs/{$template}");
        $errors = [];

        // Una plantilla a medio convertir (una vista con la directiva y otra sin ella) se avisa; sin ninguna, solo no admite SSI.
        $uses = [];

        foreach (['article', 'index'] as $view) {
            $source = is_file("{$dir}/{$view}.blade.php") ? (string) file_get_contents("{$dir}/{$view}.blade.php") : '';
            $uses[$view] = str_contains($source, '@siteHeader') || str_contains($source, '@siteFooter');
        }

        if (! in_array(true, $uses, true)) {
            return [];
        }

        if (! SitePartials::templateSupports($template)) {
            return ['[cabecera y pie] article e index deben usar @siteHeader y @siteFooter una vez cada uno, y existir partials/header.blade.php y partials/footer.blade.php'];
        }

        $partials = app(SitePartials::class);
        $data = $this->renderer->blogData($blog);

        foreach (SitePartials::PARTS as $part) {
            try {
                $html = $partials->fragment($template, $part, $data);
            } catch (Throwable $e) {
                $errors[] = "[parcial {$part}] no se pudo generar solo con el blog (es el mismo en todas las páginas: no puede usar el artículo ni el SEO): ".$e->getMessage();

                continue;
            }

            if (str_contains($html, '{{') || str_contains($html, '}}')) {
                $errors[] = "[parcial {$part}] queda sin resolver una variable o un {{";
            }

            if (preg_match('/<(html|head|body)[\s>]/i', $html)) {
                $errors[] = "[parcial {$part}] es un fragmento: no debe llevar <html>, <head> ni <body>";
            }

            if (str_contains($html, '<!--#')) {
                $errors[] = "[parcial {$part}] no puede contener directivas SSI propias";
            }
        }

        // En modo SSI cada vista lleva una inclusión por parte y nada de la cabecera copiada.
        $ssiBlog = (clone $blog)->forceFill(['ssi_enabled' => true]);

        foreach ($this->samples() as $name => $json) {
            $html = $this->renderer->render($ssiBlog, $json);

            foreach (SitePartials::PARTS as $part) {
                $directive = '<!--#include virtual="'.SitePartials::virtualPath($part).'" -->';

                if (substr_count($html, $directive) !== 1) {
                    $errors[] = "[$name, modo SSI] debe haber exactamente una inclusión de $part";
                }
            }

            break; // con un artículo basta: la estructura es la misma
        }

        return $errors;
    }

    /**
     * Plantilla de listado: se renderiza con 0, 1 y varias páginas, y con un título con <script>.
     *
     * @return list<string>
     */
    private function checkIndex(Blog $blog): array
    {
        $errors = [];
        $itemCategory = (object) ['name' => 'Cocina', 'slug' => 'cocina', 'url' => 'https://ejemplo.com/blog/cocina/'];
        $mk = fn (string $title, ?object $img = null) => (object) [
            'title' => $title,
            'url' => 'https://ejemplo.com/blog/articulo',
            'excerpt' => 'Extracto de ejemplo',
            'published_at' => \Carbon\CarbonImmutable::parse('2026-11-10T09:30:00+01:00')->locale('es'),
            'featured_image' => $img,
            'category' => $itemCategory,
            'categories' => [$itemCategory],
        ];
        $img = (object) ['url' => '/blog/img/2026/a.jpg', 'alt' => 'x', 'width' => 1600, 'height' => 900];

        $category = (object) ['name' => 'Cocina', 'slug' => 'cocina', 'description' => 'Ideas para la cocina.', 'url' => 'https://ejemplo.com/blog/cocina/'];
        $scriptCategory = (object) ['name' => self::SCRIPT_TITLE, 'slug' => 'reformas', 'description' => self::SCRIPT_TITLE, 'url' => 'https://ejemplo.com/blog/reformas/'];
        $menu = [$category, $scriptCategory];

        $samples = [
            'listado vacío' => [[], 1, 1],
            'listado con script en el título' => [[$mk(self::SCRIPT_TITLE, $img), $mk('Segundo')], 1, 3],
            'listado página intermedia' => [[$mk('Tercero')], 2, 3],
            'listado de una categoría' => [[$mk('Primero', $img)], 1, 1, $category],
            'categoría con script en el nombre' => [[$mk('Segundo')], 2, 3, $scriptCategory],
        ];

        foreach ($samples as $name => $sample) {
            [$items, $page, $total] = $sample;
            $cat = $sample[3] ?? null;

            try {
                $html = $this->renderer->renderIndex($blog, $items, $page, $total, $cat, $menu);
            } catch (Throwable $e) {
                $errors[] = "[{$name}] no se pudo renderizar: ".$e->getMessage();

                continue;
            }

            foreach ($this->check($html, ['public_id' => null]) as $error) {
                $errors[] = "[{$name}] {$error}";
            }

            if ($total > 1 && stripos($html, 'rel="next"') === false && $page < $total) {
                $errors[] = "[{$name}] la paginación no enlaza a la página siguiente";
            }

            if ($items && stripos($html, 'https://ejemplo.com/blog/articulo') === false) {
                $errors[] = "[{$name}] el listado no enlaza a los artículos";
            }
        }

        return $errors;
    }

    /**
     * Blade ejecuta PHP: la plantilla es código de confianza, pero se rechaza el PHP suelto
     * y cualquier salida sin escapar que no sea el cuerpo ya saneado.
     *
     * @return list<string>
     */
    private function checkSource(string $template): array
    {
        if (! preg_match('/^[a-z0-9_-]+$/', $template) || ! is_dir($dir = resource_path("views/blogs/{$template}"))) {
            return [];
        }

        $errors = [];

        foreach (\Illuminate\Support\Facades\File::allFiles($dir) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $name = $file->getRelativePathname();
            $source = (string) file_get_contents($file->getPathname());
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);

            if (preg_match('/<\?(php|=)|@php\b/i', $source)) {
                $errors[] = "[{$name}] contiene PHP suelto (<?php o @php)";
            }

            if (preg_match_all('/\{!!\s*(.*?)\s*!!\}/s', $source, $m)) {
                foreach ($m[1] as $expr) {
                    if ($expr !== '$article->body_html') {
                        $errors[] = "[{$name}] salida sin escapar no permitida: {!! {$expr} !!} (solo body_html)";
                    }
                }
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function check(string $html, array $json): array
    {
        $errors = [];

        $required = [
            '<html lang' => 'falta <html lang>',
            '<title>' => 'falta <title>',
            '<meta name="description"' => 'falta meta description',
            '<link rel="canonical"' => 'falta canonical',
            '<meta name="viewport"' => 'falta meta viewport',
        ];

        if ($json['public_id'] !== null) {
            $required['<meta name="blog-article-id" content="'.$json['public_id'].'">'] = 'falta la meta blog-article-id con el ID del artículo';
        }

        foreach ($required as $needle => $message) {
            if (stripos($html, $needle) === false) {
                $errors[] = $message;
            }
        }

        if (preg_match_all('/<h1[\s>]/i', $html) !== 1) {
            $errors[] = 'debe haber exactamente un <h1>';
        }

        if (str_contains($html, '{{') || str_contains($html, '}}')) {
            $errors[] = 'queda sin resolver una variable o un {{';
        }

        if (preg_match('/@include|@extends|@yield|@section/', $html)) {
            $errors[] = 'quedan directivas Blade sin resolver';
        }

        if (preg_match('/<\?(php|=)/i', $html)) {
            $errors[] = 'la salida contiene <?php';
        }

        if (str_contains($html, '<script>alert("xss")</script>')) {
            $errors[] = 'un título con <script> sale sin escapar';
        }

        if (! $this->parses($html)) {
            $errors[] = 'el HTML no se interpreta sin errores graves';
        }

        return $errors;
    }

    private function parses(string $html): bool
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $ok = $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // libxml no conoce las etiquetas HTML5 (código 801): no cuentan como graves.
        $serious = array_filter($errors, fn ($e) => $e->level >= LIBXML_ERR_ERROR && $e->code !== 801);

        return $ok && $serious === [];
    }

    /** @return array<string, array<string,mixed>> */
    private function samples(): array
    {
        $base = fn (array $o) => array_replace_recursive([
            'schema_version' => 1,
            'public_id' => '000001',
            'slug' => 'articulo-de-ejemplo',
            'title' => 'Artículo de ejemplo',
            'excerpt' => 'Un extracto corto.',
            'seo' => ['meta_title' => 'Artículo de ejemplo', 'meta_description' => 'Descripción de ejemplo.', 'canonical' => 'https://ejemplo.com/blog/articulo-de-ejemplo'],
            'published_at' => '2026-11-10T09:30:00+01:00',
            'updated_at' => '2026-11-12T11:02:00+01:00',
            'author' => ['name' => 'Autor Ejemplo'],
            'categories' => [['slug' => 'cocina', 'name' => 'Cocina'], ['slug' => 'reformas', 'name' => 'Reformas']],
            'featured_image' => ['file' => 'img/2026/000001-1-abcd1234.jpg', 'alt' => 'Imagen de ejemplo', 'width' => 1600, 'height' => 900],
            'body_html' => '<h2>Subtítulo</h2><p>Texto de <strong>ejemplo</strong> con un <a href="https://ejemplo.com/">enlace</a>.</p>',
        ], $o);

        $long = '';
        for ($i = 1; $i <= 40; $i++) {
            $long .= "<h2>Sección {$i}</h2><p>".str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 12).'</p><ul><li>Uno</li><li>Dos</li></ul>';
        }

        return [
            'corto' => $base([]),
            'largo' => $base(['body_html' => $long]),
            'sin imagen' => $base(['featured_image' => null, 'author' => ['name' => ''], 'excerpt' => '']),
            'sin categorías (formato anterior)' => $base(['categories' => null]),
            'categoría con script en el nombre' => $base(['categories' => [['slug' => 'cocina', 'name' => self::SCRIPT_TITLE]]]),
            'título con comillas' => $base(['title' => 'Él dijo "hola" & \'adiós\'', 'seo' => ['meta_title' => 'Él dijo "hola" & \'adiós\'']]),
            'título con script' => $base(['title' => self::SCRIPT_TITLE, 'seo' => ['meta_title' => self::SCRIPT_TITLE, 'meta_description' => self::SCRIPT_TITLE]]),
        ];
    }
}
