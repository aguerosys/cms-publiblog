<?php

namespace App\Services;

use App\Models\Blog;

/**
 * Datos estructurados (schema.org, JSON-LD) de cada página: los genera el CMS y la plantilla solo coloca
 * `@structuredData` en su <head>. Así todos los blogs los tienen igual y un título con comillas o con `</script>`
 * no puede romper la página (se escapa `<`, `>`, `&`, `'` y `"` dentro del JSON).
 *
 *   artículo  → BlogPosting + BreadcrumbList
 *   listado   → Blog (general) o CollectionPage (categoría) + BreadcrumbList
 */
class StructuredData
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;

    /** @param  object  $article  el de `$article` de la plantilla (con published_at/updated_at ya en Carbon) */
    public function article(Blog $blog, object $article, object $seo): string
    {
        $base = rtrim((string) $blog->base_url, '/');
        $publisher = $this->publisher($blog);

        $post = [
            '@type' => 'BlogPosting',
            '@id' => $seo->canonical.'#articulo',
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $seo->canonical],
            'headline' => $article->title,
            'description' => $seo->meta_description,
            'url' => $seo->canonical,
            'datePublished' => $article->published_at->toIso8601String(),
            'dateModified' => $article->updated_at->toIso8601String(),
            'inLanguage' => $blog->locale ?: 'es',
            'author' => $article->author ? ['@type' => 'Person', 'name' => $article->author->name] : $publisher,
            'publisher' => $publisher,
        ];

        if ($article->featured_image) {
            $image = ['@type' => 'ImageObject', 'url' => $base.$article->featured_image->url];

            if ($article->featured_image->width && $article->featured_image->height) {
                $image['width'] = (int) $article->featured_image->width;
                $image['height'] = (int) $article->featured_image->height;
            }

            $post['image'] = $image;
        }

        if (($article->reading_minutes ?? 0) > 0) {
            $post['timeRequired'] = 'PT'.(int) $article->reading_minutes.'M';
        }

        if ($article->category) {
            $post['articleSection'] = $article->category->name;
        }

        if (($article->word_count ?? 0) > 0) {
            $post['wordCount'] = $article->word_count;
        }

        $crumbs = $this->crumbs($blog, $article->category ? [[$article->category->name, $article->category->url]] : [], [[$article->title, $seo->canonical]]);

        return $this->script(['@context' => 'https://schema.org', '@graph' => [$post, $crumbs]]);
    }

    /**
     * @param  list<object>  $items  los de `$articles` de la plantilla
     */
    public function index(Blog $blog, array $items, ?object $category, object $seo): string
    {
        $base = rtrim((string) $blog->base_url, '/');
        $publisher = $this->publisher($blog);

        $entries = array_map(function (object $item) use ($base) {
            $entry = ['@type' => 'BlogPosting', 'headline' => $item->title, 'url' => $item->url];

            if ($item->published_at ?? null) {
                $entry['datePublished'] = $item->published_at->toIso8601String();
            }

            if ($item->featured_image ?? null) {
                $entry['image'] = [$base.$item->featured_image->url];
            }

            return $entry;
        }, array_values($items));

        $page = $category
            ? [
                '@type' => 'CollectionPage',
                'name' => $category->name,
                'url' => $seo->canonical,
                'description' => $seo->meta_description,
                'inLanguage' => $blog->locale ?: 'es',
                'isPartOf' => ['@type' => 'Blog', 'url' => $blog->blogUrl().'/'],
                'publisher' => $publisher,
                'mainEntity' => [
                    '@type' => 'ItemList',
                    'itemListElement' => array_map(
                        fn (array $entry, int $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'url' => $entry['url'], 'name' => $entry['headline']],
                        $entries,
                        array_keys($entries)
                    ),
                ],
            ]
            : [
                '@type' => 'Blog',
                'name' => $seo->meta_title,
                'url' => $seo->canonical,
                'description' => $seo->meta_description,
                'inLanguage' => $blog->locale ?: 'es',
                'publisher' => $publisher,
                'blogPost' => $entries,
            ];

        $crumbs = $this->crumbs($blog, $category ? [[$category->name, $category->url]] : [], []);

        return $this->script(['@context' => 'https://schema.org', '@graph' => [$page, $crumbs]]);
    }

    /**
     * Migas: sitio › Blog › [categoría] › [página actual]. La última es la propia página.
     *
     * @param  list<array{0:string,1:string}>  $middle  [nombre, URL]
     * @param  list<array{0:string,1:string}>  $last  [nombre, URL]
     */
    private function crumbs(Blog $blog, array $middle, array $last): array
    {
        $trail = array_merge(
            [[(string) $blog->name, rtrim((string) $blog->base_url, '/').'/'], ['Blog', $blog->blogUrl().'/']],
            $middle,
            $last
        );

        $items = [];

        foreach ($trail as $i => [$name, $url]) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => (string) $name, 'item' => $url];
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    private function publisher(Blog $blog): array
    {
        return ['@type' => 'Organization', 'name' => $blog->name, 'url' => rtrim((string) $blog->base_url, '/').'/'];
    }

    private function script(array $data): string
    {
        return '<script type="application/ld+json">'.json_encode($data, self::FLAGS).'</script>';
    }
}
