<?php

namespace App\Services;

use App\Models\Blog;
use App\Models\Category;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Convierte el JSON del artículo (contrato v1) en el HTML estático final usando la
 * plantilla Blade del blog. Las plantillas solo ven los campos del contrato:
 * article, seo y blog.
 */
class ArticleRenderer
{
    public const COMMENT = '<!-- Generado por Publiblog. No editar a mano: los cambios se pierden en la siguiente publicación. -->';

    /** @var array<int,array<string,string>> blog_id => [slug => nombre] */
    private array $names = [];

    public function __construct(private HtmlSanitizer $sanitizer)
    {
    }

    public function render(Blog $blog, array $json): string
    {
        $view = $this->viewName($blog, 'article');

        $html = view($view, $this->data($blog, $json) + ['__pb' => $this->context($blog)])->render();

        return $this->withGeneratedComment($html);
    }

    /**
     * Lo que el listado necesita de un artículo publicado, en un formato que se guarda en
     * `articles.live_summary`: así construir el listado no obliga a abrir cada JSON.
     *
     * @return array{title:string,excerpt:string,published_at:string,featured_image:?array,categories:list<string>}
     */
    public function summaryData(Blog $blog, array $json): array
    {
        $a = $this->data($blog, $json)['article'];

        return [
            'title' => $a->title,
            'excerpt' => $a->excerpt,
            'published_at' => $a->published_at->toIso8601String(),
            'featured_image' => $a->featured_image ? (array) $a->featured_image : null,
            'categories' => array_map(fn ($c) => $c->slug, $a->categories),
        ];
    }

    /**
     * Elemento del listado (contrato de `index`): title, url, excerpt, published_at, featured_image,
     * category (la principal) y categories.
     *
     * @param  array<string,object>  $categoryMap  slug => objeto categoría (name, slug, url) de este blog
     */
    public function summaryObject(Blog $blog, string $url, array $summary, array $categoryMap): object
    {
        $tz = $blog->timezone ?: 'UTC';
        $locale = $blog->locale ?: 'es';

        $categories = [];

        foreach ($summary['categories'] ?? [] as $slug) {
            if (isset($categoryMap[$slug])) {
                $categories[] = $categoryMap[$slug];
            }
        }

        return (object) [
            'title' => (string) ($summary['title'] ?? ''),
            'url' => $url,
            'excerpt' => (string) ($summary['excerpt'] ?? ''),
            'published_at' => CarbonImmutable::parse($summary['published_at'])->setTimezone($tz)->locale($locale),
            'featured_image' => ! empty($summary['featured_image']) ? (object) $summary['featured_image'] : null,
            'category' => $categories[0] ?? null,
            'categories' => $categories,
        ];
    }

    /** slug => nombre actual de las categorías del blog (si el blog aún no existe en la base de datos, vacío). */
    public function categoryNames(Blog $blog): array
    {
        if (! $blog->exists) {
            return [];
        }

        return $this->names[$blog->id] ??= Category::withoutGlobalScopes()
            ->where('blog_id', $blog->id)->pluck('name', 'slug')->all();
    }

    public function forgetCategoryNames(): void
    {
        $this->names = [];
    }

    /**
     * Página del listado /blog/ o, con $category, de /blog/{categoria}/. $pagination: current, total,
     * prev_url, next_url. $categories es el menú de categorías con artículos (name, slug, url, count).
     *
     * @param  list<object>  $articles
     * @param  list<object>  $categories
     */
    public function renderIndex(Blog $blog, array $articles, int $page, int $total, ?object $category = null, array $categories = []): string
    {
        $view = $this->viewName($blog, 'index');
        $base = $category ? $blog->blogUrl().'/'.$category->slug : $blog->blogUrl();
        $locale = $blog->locale ?: 'es';

        $pageUrl = fn (int $n) => $n === 1 ? $base.'/' : $base.'/pagina/'.$n.'/';
        $title = ($category ? "{$category->name} · {$blog->name}" : $blog->name).($page > 1 ? " · Página {$page}" : '');

        $description = $category
            ? (trim((string) ($category->description ?? '')) ?: "Artículos de {$category->name} en {$blog->name}")
            : "Artículos de {$blog->name}";

        $html = view($view, [
            '__pb' => $this->context($blog),
            'articles' => $articles,
            'category' => $category,
            'categories' => $categories,
            'pagination' => (object) [
                'current' => $page,
                'total' => $total,
                'prev_url' => $page > 1 ? $pageUrl($page - 1) : null,
                'next_url' => $page < $total ? $pageUrl($page + 1) : null,
            ],
            'seo' => (object) [
                'meta_title' => $title,
                'meta_description' => Str::limit($description, 155, '…').($page > 1 ? " Página {$page}." : ''),
                'canonical' => $pageUrl($page),
            ],
            'blog' => $this->blogData($blog),
        ])->render();

        return $this->withGeneratedComment($html);
    }

    public function hash(string $html): string
    {
        return hash('sha256', $html);
    }

    public function viewName(Blog $blog, string $name): string
    {
        if (! preg_match('/^[a-z0-9_-]+$/', (string) $blog->template)) {
            throw new RuntimeException("Plantilla no válida: {$blog->template}");
        }

        $view = "blogs.{$blog->template}.{$name}";

        if (! view()->exists($view)) {
            throw new RuntimeException("No existe la plantilla {$view}");
        }

        return $view;
    }

    /** @return array{article: object, seo: object, blog: object} */
    public function data(Blog $blog, array $json): array
    {
        $tz = $blog->timezone ?: 'UTC';
        $locale = $blog->locale ?: 'es';

        $slug = (string) ($json['slug'] ?? '');

        // Categorías del artículo: la primera es la principal y da la URL. Un JSON anterior a las
        // categorías no tiene ninguna y conserva su URL /blog/slug.
        $names = $this->categoryNames($blog);
        $categories = [];

        foreach ($json['categories'] ?? [] as $c) {
            if (! empty($c['slug'])) {
                $categories[] = (object) [
                    'name' => (string) ($names[$c['slug']] ?? $c['name'] ?? $c['slug']),
                    'slug' => (string) $c['slug'],
                    'url' => $blog->blogUrl().'/'.$c['slug'].'/',
                ];
            }
        }

        $primary = $categories[0] ?? null;
        $url = $blog->blogUrl().'/'.($primary ? $primary->slug.'/' : '').$slug;
        $body = $this->sanitizer->sanitize($json['body_html'] ?? '', $blog);
        $plain = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        $excerpt = trim((string) ($json['excerpt'] ?? '')) !== '' ? trim($json['excerpt']) : Str::limit($plain, 160, '…');

        $published = CarbonImmutable::parse($json['published_at'] ?? now())->setTimezone($tz)->locale($locale);
        $updated = CarbonImmutable::parse($json['updated_at'] ?? $json['published_at'] ?? now())->setTimezone($tz)->locale($locale);

        $featured = null;
        if (! empty($json['featured_image']['file'])) {
            $f = $json['featured_image'];
            $featured = (object) [
                'url' => '/'.config('publiblog.public_prefix').'/'.ltrim($f['file'], '/'),
                'alt' => (string) ($f['alt'] ?? ''),
                'width' => $f['width'] ?? null,
                'height' => $f['height'] ?? null,
            ];
        }

        $author = trim((string) ($json['author']['name'] ?? '')) !== ''
            ? (object) ['name' => trim($json['author']['name'])]
            : null;

        $seo = $json['seo'] ?? [];
        $title = (string) ($json['title'] ?? '');

        return [
            'article' => (object) [
                'public_id' => (string) ($json['public_id'] ?? ''),
                'title' => $title,
                'slug' => $slug,
                'url' => $url,
                'excerpt' => $excerpt,
                'published_at' => $published,
                'updated_at' => $updated,
                'author' => $author,
                'featured_image' => $featured,
                'category' => $primary,
                'categories' => $categories,
                'body_html' => $body,
            ],
            'seo' => (object) [
                'meta_title' => trim((string) ($seo['meta_title'] ?? '')) ?: $title,
                'meta_description' => trim((string) ($seo['meta_description'] ?? '')) ?: Str::limit($excerpt, 155, '…'),
                'canonical' => trim((string) ($seo['canonical'] ?? '')) ?: $url,
            ],
            'blog' => $this->blogData($blog),
        ];
    }

    /** Los datos del blog que ve la plantilla (contrato: name, domain, base_url, locale). */
    public function blogData(Blog $blog): object
    {
        return (object) [
            'name' => $blog->name,
            'domain' => $blog->domain,
            'base_url' => rtrim($blog->base_url, '/'),
            'locale' => $blog->locale ?: 'es',
        ];
    }

    /** Para las directivas @siteHeader / @siteFooter: qué plantilla es y si el blog comparte cabecera y pie por SSI. */
    private function context(Blog $blog): object
    {
        return (object) ['template' => (string) $blog->template, 'ssi' => (bool) $blog->ssi_enabled];
    }

    private function withGeneratedComment(string $html): string
    {
        if (preg_match('/^\s*<!doctype[^>]*>/i', $html, $m)) {
            return $m[0]."\n".self::COMMENT.substr($html, strlen($m[0]));
        }

        return self::COMMENT."\n".$html;
    }
}
