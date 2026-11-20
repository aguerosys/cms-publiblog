<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Blog;
use App\Models\Category;
use App\Services\Remote\RemoteWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;

/**
 * Listados paginados y sitemap del blog, generados desde el índice y subidos "por diferencias":
 * solo los archivos cuyo hash cambió desde la última subida.
 *
 *   index.html                          /blog/                      todos los artículos
 *   pagina/{n}/index.html               /blog/pagina/n/
 *   {categoria}/index.html              /blog/{categoria}/          artículos de esa categoría
 *   {categoria}/pagina/{n}/index.html   /blog/{categoria}/pagina/n/
 *   sitemap.xml                         urlset, o índice si pasa del límite (500 URLs)
 *   sitemap-{n}.xml                     partes de artículos (solo cuando hay que partir)
 *   sitemap-categorias[-{n}].xml        partes de categorías (solo cuando hay que partir)
 *
 * Los listados llevan contenido (títulos, extractos, imágenes), así que se regeneran siempre; lo
 * incremental es la subida. Se construyen solo desde la base de datos (`live_summary` y las categorías
 * publicadas): no hace falta abrir los JSON de los artículos, aunque sean miles.
 *
 * El manifiesto (build/manifest.json en el CMS) recuerda qué hash tiene cada archivo en el servidor del
 * cliente. Los archivos generados también se guardan en build/.
 */
class ListingBuilder
{
    public function __construct(
        private ArticleRenderer $renderer,
        private ArticleStorage $storage,
    ) {
    }

    /**
     * Genera todos los archivos de listados y el sitemap.
     *
     * @return array<string,string> ruta relativa a /blog/ => contenido
     */
    public function build(Blog $blog, ?Article $include = null, ?Article $exclude = null): array
    {
        $articles = Article::query()
            ->with('liveCategories')
            ->where('blog_id', $blog->id)
            ->whereNotNull('remote_path')
            ->when($exclude, fn ($q) => $q->where('id', '!=', $exclude->id))
            ->get();

        // El artículo que se acaba de publicar ya está en la base de datos con su estado nuevo; se usa esa
        // versión (con sus fechas ya convertidas), no la instancia en memoria.
        if ($include && ! $articles->contains('id', $include->id)) {
            $articles->push($include);
        }

        $articles = $articles
            ->filter(fn (Article $a) => $a->slug !== null && $a->published_at !== null)
            ->sortByDesc(fn (Article $a) => $a->published_at->format('YmdHis').$a->public_id)
            ->values();

        foreach ($articles as $article) {
            $article->setRelation('blog', $blog);
        }

        // Categorías conocidas (nombre actual, descripción) y reparto de artículos por categoría.
        $known = Category::withoutGlobalScopes()->where('blog_id', $blog->id)->get()->keyBy('slug');
        $objects = $known->map(fn (Category $c) => (object) [
            'name' => $c->name,
            'slug' => $c->slug,
            'description' => $c->description,
            'url' => $c->url($blog),
        ])->all();

        $buckets = [];

        foreach ($articles as $article) {
            foreach ($article->liveCategories as $category) {
                $buckets[$category->slug][] = $article;
            }
        }

        // Menú de categorías: solo las que tienen artículos, por nombre. Sin contadores: así un artículo
        // nuevo no cambia las páginas de las categorías a las que no pertenece.
        $menu = collect(array_keys($buckets))
            ->filter(fn ($slug) => isset($objects[$slug]))
            ->map(fn ($slug) => (object) ['name' => $objects[$slug]->name, 'slug' => $slug, 'url' => $objects[$slug]->url])
            ->sortBy(fn ($c) => mb_strtolower($c->name))
            ->values()
            ->all();

        $files = $this->pages($blog, $articles, null, $menu, $objects);

        foreach ($menu as $entry) {
            $files += $this->pages($blog, collect($buckets[$entry->slug]), $objects[$entry->slug], $menu, $objects);
        }

        // Cabecera y pie compartidos (solo en modo SSI): se suben con los demás archivos y desaparecen si se desactiva.
        $files += app(SitePartials::class)->files($blog, $this->renderer->blogData($blog));

        // Archivos de diseño de la plantilla (assets/): /blog/_assets/. Se suben solo si cambian y se retiran si desaparecen.
        $files += app(TemplateAssets::class)->files($blog);

        return $files + $this->sitemaps($blog, $articles->all(), $buckets, $objects);
    }

    /**
     * Páginas de un listado (el general o el de una categoría).
     *
     * @param  Collection<int,Article>  $articles ya ordenados de más nuevo a más antiguo
     * @param  list<object>  $menu
     * @param  array<string,object>  $objects
     * @return array<string,string>
     */
    private function pages(Blog $blog, Collection $articles, ?object $category, array $menu, array $objects): array
    {
        $files = [];
        $perPage = max(1, (int) $blog->posts_per_page);
        $pages = $articles->chunk($perPage)->values();
        $total = max(1, $pages->count());
        $dir = $category ? $category->slug.'/' : '';

        for ($page = 1; $page <= $total; $page++) {
            $items = [];

            foreach ($pages->get($page - 1, collect()) as $article) {
                $summary = $this->summary($blog, $article);

                if ($summary) {
                    $items[] = $this->renderer->summaryObject($blog, $article->url(), $summary, $objects);
                }
            }

            $path = $dir.($page === 1 ? 'index.html' : "pagina/{$page}/index.html");
            $files[$path] = $this->renderer->renderIndex($blog, $items, $page, $total, $category, $menu);
        }

        return $files;
    }

    /**
     * Resumen de lo PUBLICADO (nunca del borrador). Los artículos publicados antes de que existiera el
     * resumen guardado lo toman una vez de su copia publicada y lo dejan guardado.
     */
    private function summary(Blog $blog, Article $article): ?array
    {
        if ($article->live_summary) {
            return $article->live_summary;
        }

        $json = $this->storage->readLiveJson($blog->id, $article->public_id);

        if (! $json) {
            return null;
        }

        $summary = $this->renderer->summaryData($blog, $json);

        $article->timestamps = false;
        $article->live_summary = $summary;
        $article->saveQuietly();

        return $summary;
    }

    /**
     * Sube lo que cambió, borra lo que ya no existe (páginas sobrantes, categorías vacías) y actualiza el manifiesto.
     *
     * @return int archivos subidos o borrados en el servidor
     */
    public function sync(FilesystemOperator $fs, Blog $blog, ?Article $include = null, ?Article $exclude = null, ?callable $onChange = null, bool $force = false): int
    {
        $files = $this->build($blog, $include, $exclude);
        $manifest = $this->manifest($blog);
        $changed = 0;

        foreach ($files as $path => $contents) {
            $hash = hash('sha256', $contents);
            $this->storage->writeAtomic("{$blog->id}/build/{$path}", $contents);

            // Se confía en el manifiesto, salvo para las dos puertas de entrada: si alguien las
            // borró en el servidor (p. ej. una restauración), se vuelven a subir.
            $unchanged = ! $force
                && ($manifest[$path] ?? null) === $hash
                && (! in_array($path, ['index.html', 'sitemap.xml'], true) || $fs->fileExists($path));

            if ($unchanged) {
                continue;
            }

            RemoteWriter::put($fs, $path, $contents);
            $manifest[$path] = $hash;
            $this->saveManifest($blog, $manifest);
            $changed++;
            $onChange && $onChange();
        }

        foreach (array_diff_key($manifest, $files) as $path => $_) {
            // Borra el archivo y, si la carpeta queda vacía (categoría sin artículos, página sobrante), la carpeta.
            if (RemoteWriter::remove($fs, $path)) {
                $changed++;
                $onChange && $onChange();
            }

            unset($manifest[$path]);
            Storage::disk('blogs')->delete("{$blog->id}/build/{$path}");
            $this->saveManifest($blog, $manifest);
        }

        return $changed;
    }

    /**
     * @param  list<Article>  $articles ya ordenados
     * @param  array<string,list<Article>>  $buckets  categoría => artículos publicados en ella
     * @param  array<string,object>  $objects  categoría => datos
     * @return array<string,string>
     */
    private function sitemaps(Blog $blog, array $articles, array $buckets, array $objects): array
    {
        $limit = max(1, (int) config('publiblog.sitemap_limit'));
        $base = $blog->blogUrl();

        $urlset = function (array $entries): string {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

            foreach ($entries as [$loc, $lastmod]) {
                $xml .= '  <url><loc>'.htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>'
                    .'<lastmod>'.$lastmod->utc()->format('Y-m-d\TH:i:s\Z').'</lastmod></url>'."\n";
            }

            return $xml.'</urlset>'."\n";
        };

        $lastmod = fn (Article $a) => $a->updated_at ?? $a->published_at;

        $articleEntries = array_map(fn (Article $a) => [$a->url(), $lastmod($a)], $articles);

        // Una entrada por categoría con artículos; su fecha es la del artículo más reciente que contiene.
        $categoryEntries = [];

        foreach ($buckets as $slug => $list) {
            if (! isset($objects[$slug])) {
                continue;
            }

            $categoryEntries[$slug] = [
                $objects[$slug]->url,
                collect($list)->map($lastmod)->sortDesc()->first(),
            ];
        }

        ksort($categoryEntries);
        $categoryEntries = array_values($categoryEntries);

        if (count($articleEntries) + count($categoryEntries) <= $limit) {
            return ['sitemap.xml' => $urlset([...$articleEntries, ...$categoryEntries])];
        }

        // Más URLs que el límite: sitemap.xml pasa a ser un índice de sitemaps. Los artículos se reparten
        // de más antiguo a más nuevo: uno nuevo solo altera el último archivo y los anteriores conservan
        // su hash (no se vuelven a subir). Las categorías van aparte.
        $files = [];
        $index = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        $add = function (string $name, array $entries) use (&$files, &$index, $urlset, $base) {
            $files[$name] = $urlset($entries);
            $index .= '  <sitemap><loc>'.htmlspecialchars("{$base}/{$name}", ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></sitemap>'."\n";
        };

        foreach (array_chunk(array_reverse($articleEntries), $limit) as $i => $chunk) {
            $add('sitemap-'.($i + 1).'.xml', $chunk);
        }

        foreach (array_chunk($categoryEntries, $limit) as $i => $chunk) {
            $add($i === 0 ? 'sitemap-categorias.xml' : 'sitemap-categorias-'.($i + 1).'.xml', $chunk);
        }

        return ['sitemap.xml' => $index.'</sitemapindex>'."\n"] + $files;
    }

    /** @return array<string,string> */
    private function manifest(Blog $blog): array
    {
        $path = "{$blog->id}/build/manifest.json";

        return Storage::disk('blogs')->exists($path)
            ? (json_decode(Storage::disk('blogs')->get($path), true) ?: [])
            : [];
    }

    private function saveManifest(Blog $blog, array $manifest): void
    {
        $this->storage->writeAtomic("{$blog->id}/build/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
