<?php

namespace App\Services;

use App\Models\Blog;
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

    public function __construct(private HtmlSanitizer $sanitizer)
    {
    }

    public function render(Blog $blog, array $json): string
    {
        $view = $this->viewName($blog, 'article');

        $html = view($view, $this->data($blog, $json))->render();

        return $this->withGeneratedComment($html);
    }

    /**
     * Resumen de un artículo para el listado: solo los campos del contrato de `index`.
     */
    public function summary(Blog $blog, array $json): object
    {
        $a = $this->data($blog, $json)['article'];

        return (object) [
            'title' => $a->title,
            'url' => $a->url,
            'excerpt' => $a->excerpt,
            'published_at' => $a->published_at,
            'featured_image' => $a->featured_image,
        ];
    }

    /**
     * Página del listado /blog/. $pagination: current, total, prev_url, next_url.
     *
     * @param  list<object>  $articles
     */
    public function renderIndex(Blog $blog, array $articles, int $page, int $total): string
    {
        $view = $this->viewName($blog, 'index');
        $base = $blog->blogUrl();
        $locale = $blog->locale ?: 'es';

        $pageUrl = fn (int $n) => $n === 1 ? $base.'/' : $base.'/pagina/'.$n.'/';
        $title = $blog->name.($page > 1 ? " · Página {$page}" : '');

        $html = view($view, [
            'articles' => $articles,
            'pagination' => (object) [
                'current' => $page,
                'total' => $total,
                'prev_url' => $page > 1 ? $pageUrl($page - 1) : null,
                'next_url' => $page < $total ? $pageUrl($page + 1) : null,
            ],
            'seo' => (object) [
                'meta_title' => $title,
                'meta_description' => "Artículos de {$blog->name}".($page > 1 ? ", página {$page}" : ''),
                'canonical' => $pageUrl($page),
            ],
            'blog' => (object) [
                'name' => $blog->name,
                'domain' => $blog->domain,
                'base_url' => rtrim($blog->base_url, '/'),
                'locale' => $locale,
            ],
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
        $url = $blog->blogUrl().'/'.$slug;
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
                'body_html' => $body,
            ],
            'seo' => (object) [
                'meta_title' => trim((string) ($seo['meta_title'] ?? '')) ?: $title,
                'meta_description' => trim((string) ($seo['meta_description'] ?? '')) ?: Str::limit($excerpt, 155, '…'),
                'canonical' => trim((string) ($seo['canonical'] ?? '')) ?: $url,
            ],
            'blog' => (object) [
                'name' => $blog->name,
                'domain' => $blog->domain,
                'base_url' => rtrim($blog->base_url, '/'),
                'locale' => $locale,
            ],
        ];
    }

    private function withGeneratedComment(string $html): string
    {
        if (preg_match('/^\s*<!doctype[^>]*>/i', $html, $m)) {
            return $m[0]."\n".self::COMMENT.substr($html, strlen($m[0]));
        }

        return self::COMMENT."\n".$html;
    }
}
