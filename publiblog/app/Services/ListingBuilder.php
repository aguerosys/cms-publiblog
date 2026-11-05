<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Blog;
use App\Services\Remote\RemoteWriter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;

/**
 * Fase 1b: listado paginado de /blog/ y sitemap.xml, generados desde el índice y subidos
 * "por diferencias": solo los archivos cuyo hash cambió desde la última subida.
 *
 *   index.html                 página 1  → /blog/
 *   pagina/{n}/index.html      página n  → /blog/pagina/n/   (Apache sirve index.html sin reglas)
 *   sitemap.xml                urlset, o índice de sitemaps si pasa del límite (500 URLs)
 *   sitemap-{n}.xml            partes (solo cuando hay que partir)
 *
 * El manifiesto (build/manifest.json en el CMS) recuerda qué hash tiene cada archivo en el
 * servidor del cliente. Los archivos generados también se guardan en build/.
 */
class ListingBuilder
{
    public function __construct(
        private ArticleRenderer $renderer,
        private ArticleStorage $storage,
    ) {
    }

    /**
     * Genera todos los archivos del listado y el sitemap.
     *
     * @return array<string,string> ruta relativa a /blog/ => contenido
     */
    public function build(Blog $blog, ?Article $include = null, ?Article $exclude = null): array
    {
        $articles = Article::query()
            ->where('blog_id', $blog->id)
            ->whereNotNull('remote_path')
            ->when($exclude, fn ($q) => $q->where('id', '!=', $exclude->id))
            ->get();

        if ($include && ! $articles->contains('id', $include->id)) {
            $articles->push($include);
        }

        $articles = $articles
            ->filter(fn (Article $a) => $a->slug !== null && $a->published_at !== null)
            ->sortByDesc(fn (Article $a) => $a->published_at->format('YmdHis').$a->public_id)
            ->values();

        $files = [];
        $perPage = max(1, (int) $blog->posts_per_page);
        $pages = $articles->chunk($perPage)->values();
        $total = max(1, $pages->count());

        for ($page = 1; $page <= $total; $page++) {
            $items = [];

            foreach ($pages->get($page - 1, collect()) as $article) {
                $json = $this->storage->readJson($blog->id, $article->public_id);

                if ($json) {
                    $items[] = $this->renderer->summary($blog, $json);
                }
            }

            $path = $page === 1 ? 'index.html' : "pagina/{$page}/index.html";
            $files[$path] = $this->renderer->renderIndex($blog, $items, $page, $total);
        }

        return $files + $this->sitemaps($blog, $articles->all());
    }

    /**
     * Sube lo que cambió, borra lo que ya no existe (p. ej. páginas sobrantes) y actualiza el manifiesto.
     *
     * @return int archivos subidos o borrados en el servidor
     */
    public function sync(FilesystemOperator $fs, Blog $blog, ?Article $include = null, ?Article $exclude = null, ?callable $onChange = null): int
    {
        $files = $this->build($blog, $include, $exclude);
        $manifest = $this->manifest($blog);
        $changed = 0;

        foreach ($files as $path => $contents) {
            $hash = hash('sha256', $contents);
            $this->storage->writeAtomic("{$blog->id}/build/{$path}", $contents);

            // Se confía en el manifiesto, salvo para las dos puertas de entrada: si alguien las
            // borró en el servidor (p. ej. una restauración), se vuelven a subir.
            $unchanged = ($manifest[$path] ?? null) === $hash
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
            if ($fs->fileExists($path)) {
                $fs->delete($path);
                $changed++;
                $onChange && $onChange();
            }

            unset($manifest[$path]);
            Storage::disk('blogs')->delete("{$blog->id}/build/{$path}");
            $this->saveManifest($blog, $manifest);
        }

        return $changed;
    }

    /** @param list<Article> $articles ya ordenados */
    private function sitemaps(Blog $blog, array $articles): array
    {
        $limit = max(1, (int) config('publiblog.sitemap_limit'));
        $base = $blog->blogUrl();

        $urlset = function (array $chunk): string {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

            foreach ($chunk as $article) {
                $xml .= '  <url><loc>'.htmlspecialchars($article->url(), ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>'
                    .'<lastmod>'.($article->updated_at ?? $article->published_at)->utc()->format('Y-m-d\TH:i:s\Z').'</lastmod></url>'."\n";
            }

            return $xml.'</urlset>'."\n";
        };

        foreach ($articles as $article) {
            $article->setRelation('blog', $blog);
        }

        if (count($articles) <= $limit) {
            return ['sitemap.xml' => $urlset($articles)];
        }

        // Más URLs que el límite: sitemap.xml pasa a ser un índice de sitemaps. Se reparten de más
        // antiguo a más nuevo: un artículo nuevo solo altera el último archivo y los anteriores
        // conservan su hash (no se vuelven a subir).
        $articles = array_reverse($articles);
        $files = [];
        $index = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach (array_chunk($articles, $limit) as $i => $chunk) {
            $name = 'sitemap-'.($i + 1).'.xml';
            $files[$name] = $urlset($chunk);
            $index .= '  <sitemap><loc>'.htmlspecialchars("{$base}/{$name}", ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></sitemap>'."\n";
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
