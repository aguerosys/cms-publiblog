<?php

namespace App\Services\Import;

use App\Models\Article;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Media;
use App\Models\User;
use App\Services\ArticleService;
use App\Services\ArticleStorage;
use App\Services\CategoryService;
use App\Services\MediaService;
use App\Services\SlugService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;
use XMLReader;

/**
 * Importador del XML de exportación de WordPress (WXR) — F2-5.
 *
 *  - Conserva fecha de publicación, slug (si es válido y libre), título, extracto, autor, SEO de Yoast
 *    e imágenes (las descarga, o las toma de una carpeta local, y pasan por el mismo recodificado que
 *    cualquier subida). Reescribe los enlaces internos entre entradas importadas.
 *  - Los artículos nacen como borradores con el ID nuevo del CMS; publicarlos es un paso aparte
 *    (BlogRegenerator en modo «import»), en lote y subiendo el listado una sola vez.
 *  - Es idempotente: cada entrada recuerda su origen («wp:123») y una segunda pasada la omite.
 *  - Lee el archivo en flujo (XMLReader): aguanta exportaciones de decenas de miles de entradas.
 *  - Todo lo que no se puede conservar queda en el informe, no se pierde en silencio.
 */
class WordPressImporter
{
    private const MAX_IMAGE_BYTES = 20 * 1024 * 1024;

    /** @var array<string,array{url:string,alt:string}> */
    private array $attachments = [];

    /** @var array<string,string> enlace antiguo normalizado => slug nuevo */
    private array $linkMap = [];

    private string $sourceHost = '';

    /** @var array<string,array{id:int,name:string}> categorías de WordPress: nicename => id y nombre */
    private array $terms = [];

    /** @var array<string,string> nicename => slug que tendrá la categoría en el blog */
    private array $categorySlugs = [];

    /** @var array<string,bool> categorías cuyo cambio de slug ya se avisó en el informe */
    private array $noted = [];

    public function __construct(
        private ArticleService $articles,
        private SlugService $slugs,
        private MediaService $media,
        private ArticleStorage $storage,
        private CategoryService $categories,
    ) {
    }

    /**
     * @param  array{images_dir?:?string,download?:bool,dry_run?:bool,user?:?User,limit?:?int,source_host?:?string}  $options
     */
    public function import(Blog $blog, string $file, array $options = []): ImportReport
    {
        if (! is_file($file) || ! is_readable($file)) {
            throw new InvalidArgumentException("No se puede leer el archivo: {$file}");
        }

        $options += ['images_dir' => null, 'download' => true, 'dry_run' => false, 'user' => null, 'limit' => null, 'source_host' => null];

        $this->scan($blog, $file);

        if ($options['source_host']) {
            $this->sourceHost = strtolower((string) $options['source_host']);
        }

        $report = new ImportReport();
        $done = 0;

        $this->eachItem($file, function (\SimpleXMLElement $item) use ($blog, $options, $report, &$done) {
            $wp = $item->children('wp', true);

            if ((string) $wp->post_type !== 'post') {
                return true;
            }

            if ($options['limit'] && $done >= $options['limit']) {
                return false;
            }

            $done++;
            $this->importItem($blog, $item, $options, $report);

            return true;
        });

        return $report;
    }

    // ───────── lectura del archivo ─────────

    /** Primera pasada: adjuntos (para imágenes destacadas) y enlaces antiguos (para reescribir enlaces internos). */
    private function scan(Blog $blog, string $file): void
    {
        $this->attachments = [];
        $this->linkMap = [];
        $this->terms = [];
        $this->categorySlugs = [];
        $this->noted = [];
        $this->sourceHost = '';

        $this->walk($file, function (\SimpleXMLElement $item) use ($blog) {
            $wp = $item->children('wp', true);
            $type = (string) $wp->post_type;

            if ($type === 'attachment') {
                $this->attachments[(string) $wp->post_id] = [
                    'url' => trim((string) $wp->attachment_url),
                    'alt' => $this->meta($item, '_wp_attachment_image_alt') ?? '',
                ];
            } elseif ($type === 'post') {
                $slug = $this->slugs->normalize(rawurldecode((string) $wp->post_name));

                if ($slug !== '') {
                    // La URL nueva lleva la categoría principal: /blog/{categoria}/{slug}.
                    $first = array_key_first($this->orderedCategories($item));
                    $category = $first !== null
                        ? $this->categorySlug($blog, $first, $this->orderedCategories($item)[$first])
                        : ($this->categories->defaultFor($blog)?->slug ?? 'general');

                    $this->linkMap[$this->normalizeUrl((string) $item->link)] = $category.'/'.$slug;
                }
            }

            return true;
        }, onChannelLink: function (string $link) {
            $this->sourceHost = strtolower((string) parse_url(trim($link), PHP_URL_HOST));
        }, onCategory: function (\SimpleXMLElement $term) {
            $wp = $term->children('wp', true);
            $nice = (string) $wp->category_nicename;

            if ($nice !== '') {
                $this->terms[$nice] = ['id' => (int) $wp->term_id, 'name' => trim((string) $wp->cat_name)];
            }
        });
    }

    private function eachItem(string $file, callable $callback): void
    {
        $this->walk($file, $callback);
    }

    /**
     * Recorre los <item> uno a uno sin cargar el archivo entero.
     *
     * @param  callable(\SimpleXMLElement): bool  $onItem  devolver false detiene el recorrido
     * @param  (callable(string): void)|null  $onChannelLink  recibe el <link> del canal (la web de origen)
     * @param  (callable(\SimpleXMLElement): void)|null  $onCategory  recibe cada <wp:category> del canal (nombre e ID de la categoría)
     */
    private function walk(string $file, callable $onItem, ?callable $onChannelLink = null, ?callable $onCategory = null): void
    {
        $reader = new XMLReader();

        if (! $reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new InvalidArgumentException('El archivo no es un XML válido.');
        }

        $more = $reader->read();
        $channelLinkSeen = false;

        while ($more) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'item') {
                if ($onItem($this->element($reader)) === false) {
                    break;
                }

                $more = $reader->next(); // salta el subárbol: el cursor ya queda en el siguiente hermano

                continue;
            }

            if ($onCategory && $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'wp:category' && $reader->depth === 2) {
                $onCategory($this->element($reader));
                $more = $reader->next();

                continue;
            }

            if ($onChannelLink && ! $channelLinkSeen && $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'link' && $reader->depth === 2) {
                $channelLinkSeen = true;
                $onChannelLink($reader->readString());
            }

            $more = $reader->read();
        }

        $reader->close();
    }
    private function element(XMLReader $reader): \SimpleXMLElement
    {
        $doc = new \DOMDocument();
        $node = $reader->expand($doc);

        return simplexml_import_dom($doc->importNode($node, true));
    }

    private function meta(\SimpleXMLElement $item, string $key): ?string
    {
        foreach ($item->children('wp', true)->postmeta as $meta) {
            if ((string) $meta->meta_key === $key) {
                return (string) $meta->meta_value;
            }
        }

        return null;
    }

    // ───────── una entrada ─────────

    private function importItem(Blog $blog, \SimpleXMLElement $item, array $options, ImportReport $report): void
    {
        $wp = $item->children('wp', true);
        $wpId = (string) $wp->post_id;
        $title = trim(html_entity_decode((string) $item->title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $oldUrl = trim((string) $item->link);
        $status = (string) $wp->status;
        $ref = 'wp:'.$wpId;

        if (! in_array($status, ['publish', 'draft', 'pending', 'future'], true)) {
            $report->add($wpId, $title, ImportReport::SKIPPED, '', $oldUrl, '', ["estado de WordPress «{$status}»: no se importa"]);

            return;
        }

        $existing = Article::withTrashed()->where('blog_id', $blog->id)->where('source_ref', $ref)->first();

        if ($existing) {
            $report->add($wpId, $title, ImportReport::SKIPPED, $existing->public_id, $oldUrl, $this->newUrl($blog, $existing), ['ya importado']);

            return;
        }

        $notes = [];

        if ($title === '') {
            $report->add($wpId, '(sin título)', ImportReport::ERROR, '', $oldUrl, '', ['la entrada no tiene título']);

            return;
        }

        $slug = $this->resolveSlug($blog, (string) $wp->post_name, $title, $notes);
        [$categoryIds, $primaryId, $primarySlug] = $this->resolveCategories($blog, $item, $notes, (bool) $options['dry_run']);
        $publishedAt = $this->date($blog, $wp);
        $raw = (string) $item->children('content', true)->encoded;
        $notes = array_merge($notes, $this->unsupportedIn($raw));

        if ($options['dry_run']) {
            $report->add($wpId, $title, ImportReport::DRY_RUN, '', $oldUrl, $blog->blogUrl().'/'.$primarySlug.'/'.$slug, $notes);

            return;
        }

        $article = null;

        try {
            $article = $this->articles->createDraft($blog, $options['user']);
            $article->forceFill(['source_ref' => $ref, 'import_publish' => $status === 'publish']);

            $body = $this->convertBody($raw, $blog, $notes);
            $body = $this->rewriteImages($body, $blog, $article, $options, $notes);

            $featuredId = null;
            $featuredAlt = '';
            $thumb = $this->meta($item, '_thumbnail_id');

            if ($thumb && isset($this->attachments[$thumb])) {
                $featured = $this->resolveImage($this->attachments[$thumb]['url'], $blog, $article, $options, $notes);
                $featuredId = $featured?->id;
                $featuredAlt = $this->attachments[$thumb]['alt'];
            } elseif ($thumb) {
                $notes[] = "imagen destacada {$thumb} no está en el archivo";
            }

            $this->articles->save($article, [
                'title' => $title,
                'slug' => $slug,
                'excerpt' => $this->plain((string) $item->children('excerpt', true)->encoded),
                'meta_title' => mb_substr($this->plain($this->meta($item, '_yoast_wpseo_title') ?? ''), 0, 70),
                'meta_description' => mb_substr($this->plain($this->meta($item, '_yoast_wpseo_metadesc') ?? ''), 0, 200),
                'body_html' => $body,
                'featured_media_id' => $featuredId,
                'featured_alt' => $featuredAlt,
                'author_name' => trim((string) $item->children('dc', true)->creator),
                'published_at' => $publishedAt,
                'category_ids' => $categoryIds,
                'primary_category_id' => $primaryId,
            ], $options['user'], force: true);

            $this->stampOrigin($article, $wpId, $oldUrl);

            $report->add($wpId, $title, ImportReport::IMPORTED, $article->public_id, $oldUrl, $this->newUrl($blog, $article), $notes);
        } catch (Throwable $e) {
            report($e);

            if ($article) {
                $article->forceDelete();
            }

            $report->add($wpId, $title, ImportReport::ERROR, '', $oldUrl, '', [mb_substr($e->getMessage(), 0, 300)]);
        }
    }

    /** URL que tiene (o tendrá) el artículo importado: con su categoría principal. */
    private function newUrl(Blog $blog, Article $article): string
    {
        if ($article->remote_path) {
            return $blog->blogUrl().'/'.\App\Services\ArticleLocator::publicPath($article->remote_path);
        }

        $json = $this->storage->readJson($article->blog_id, $article->public_id) ?? [];
        $file = \App\Services\ArticleLocator::fileFromJson($json);

        return $file ? $blog->blogUrl().'/'.\App\Services\ArticleLocator::publicPath($file) : ($article->slug ? $blog->blogUrl().'/'.$article->slug : '');
    }

    /**
     * Categorías de WordPress de una entrada, en orden: la principal primero. La principal es la que marca
     * Yoast o, si no, la de menor ID: así WordPress arma su permalink /%category%/%postname%/.
     * «Sin categoría» de WordPress no cuenta: la entrada recibe la categoría por defecto del blog.
     *
     * @return array<string,string> nicename => nombre
     */
    private function orderedCategories(\SimpleXMLElement $item): array
    {
        $found = [];

        foreach ($item->category as $c) {
            if ((string) $c['domain'] !== 'category') {
                continue;
            }

            $nice = trim((string) $c['nicename']);

            if ($nice === '' || in_array($nice, ['uncategorized', 'sin-categoria'], true)) {
                continue;
            }

            $name = trim(html_entity_decode((string) $c, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $found[$nice] = $name !== '' ? $name : $nice;
        }

        uksort($found, fn ($a, $b) => ($this->terms[$a]['id'] ?? PHP_INT_MAX) <=> ($this->terms[$b]['id'] ?? PHP_INT_MAX));
        $yoast = (int) ($this->meta($item, '_yoast_wpseo_primary_category') ?? 0);

        if ($yoast) {
            foreach (array_keys($found) as $nice) {
                if (($this->terms[$nice]['id'] ?? null) === $yoast) {
                    $found = [$nice => $found[$nice]] + $found;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Slug que tendrá en el blog una categoría de WordPress. Se decide una sola vez por categoría (y es el
     * mismo en la pasada de enlaces que al importar), y dos categorías nunca comparten slug.
     */
    private function categorySlug(Blog $blog, string $nice, string $name): string
    {
        if (isset($this->categorySlugs[$nice])) {
            return $this->categorySlugs[$nice];
        }

        $slug = $this->slugs->normalize(rawurldecode($nice));

        if ($slug === '' || ! $this->slugs->isValid($slug) || $this->slugs->isReserved($slug) || $this->categories->collidesWithLegacyArticle($blog, $slug)) {
            $slug = $this->categories->uniqueSlug($blog, $name);
        }

        // Dos categorías de WordPress que acaban en el mismo slug: la segunda recibe un sufijo.
        $base = $slug;

        for ($n = 2; in_array($slug, $this->categorySlugs, true); $n++) {
            $slug = $base.'-'.$n;
        }

        return $this->categorySlugs[$nice] = $slug;
    }

    /**
     * Categoría de las entradas de WordPress sin categoría: la de reserva del blog si la tiene; si no, «General»
     * (se crea solo aquí, solo si hay entradas sin categoría, y queda dicho en el informe).
     */
    private function defaultCategorySlug(Blog $blog, bool $create, array &$notes): string
    {
        if ($reserve = $this->categories->defaultFor($blog)) {
            return $reserve->slug;
        }

        if (! isset($this->noted['__reserve'])) {
            $this->noted['__reserve'] = true;
            $notes[] = 'hay entradas sin categoría: van a «General»'.($create ? ' (categoría creada ahora, marcada como de reserva)' : ' (se creará al importar)');
        }

        return $create ? $this->categories->defaultOrCreate($blog)->slug : 'general';
    }

    /**
     * Categorías de la entrada en el blog (se crean las que falten, salvo en simulación).
     *
     * @return array{0:list<int>,1:?int,2:string} IDs, ID de la principal y slug de la principal
     */
    private function resolveCategories(Blog $blog, \SimpleXMLElement $item, array &$notes, bool $dryRun): array
    {
        $found = $this->orderedCategories($item);

        if ($found === []) {
            $slug = $this->defaultCategorySlug($blog, ! $dryRun, $notes);
            $id = $dryRun ? null : Category::where('blog_id', $blog->id)->where('slug', $slug)->value('id');

            return [$id ? [$id] : [], $id, $slug];
        }

        $ids = [];
        $primarySlug = null;

        foreach ($found as $nice => $name) {
            $slug = $this->categorySlug($blog, $nice, $name);

            if ($this->slugs->normalize(rawurldecode($nice)) !== $slug && ! isset($this->noted[$nice])) {
                $this->noted[$nice] = true;
                $notes[] = "la categoría «{$name}» no podía usar su slug de WordPress: se usó «{$slug}»";
            }

            $category = Category::where('blog_id', $blog->id)->where('slug', $slug)->first();

            if (! $category && ! $dryRun) {
                $category = $this->categories->create($blog, ['name' => $name, 'slug' => $slug]);
            }

            $ids[] = $category?->id;
            $primarySlug ??= $slug;
        }

        $ids = array_values(array_filter($ids));

        return [$ids, $ids[0] ?? null, $primarySlug];
    }

    /** Anota en el JSON de dónde viene el artículo (sirve para redirecciones 301 futuras). */
    private function stampOrigin(Article $article, string $wpId, string $oldUrl): void
    {
        $json = $this->storage->readJson($article->blog_id, $article->public_id) ?? [];
        $json['import'] = ['source' => 'wordpress', 'wp_id' => $wpId, 'url' => $oldUrl];
        $this->storage->writeJson($article->blog_id, $article->public_id, $json);
    }

    private function resolveSlug(Blog $blog, string $postName, string $title, array &$notes): string
    {
        $slug = $this->slugs->normalize(rawurldecode($postName));

        if ($slug !== '' && $this->slugs->isValid($slug) && ! $this->slugs->isReserved($slug) && ! $this->slugs->isTaken($blog, $slug)) {
            return $slug;
        }

        $new = $this->slugs->unique($blog, $slug !== '' ? $slug : $title);
        $notes[] = $slug === ''
            ? "sin slug en WordPress: se generó «{$new}»"
            : "el slug «{$slug}» no estaba disponible: se usó «{$new}» (los enlaces antiguos a esta entrada necesitarán redirección)";

        return $new;
    }

    /** Fecha en la zona horaria del blog; usa la GMT de WordPress, que no depende de su configuración. */
    private function date(Blog $blog, \SimpleXMLElement $wp): string
    {
        $gmt = trim((string) $wp->post_date_gmt);

        if ($gmt !== '' && ! str_starts_with($gmt, '0000')) {
            return CarbonImmutable::parse($gmt, 'UTC')->setTimezone($blog->timezone)->format('Y-m-d\TH:i:s');
        }

        $local = trim((string) $wp->post_date);

        return $local !== '' && ! str_starts_with($local, '0000')
            ? CarbonImmutable::parse($local, $blog->timezone)->format('Y-m-d\TH:i:s')
            : now($blog->timezone)->format('Y-m-d\TH:i:s');
    }

    // ───────── contenido ─────────

    /** Contenido de WordPress → HTML limpio y con párrafos (el saneado final lo hace ArticleService::save). */
    public function convertBody(string $html, Blog $blog, array &$notes = []): string
    {
        // Comentarios de bloques de Gutenberg.
        $html = preg_replace('/<!--\s*\/?wp:.*?-->/s', '', $html) ?? $html;

        // [caption] → <figure>.
        $html = preg_replace_callback('/\[caption[^\]]*\](.*?)\[\/caption\]/is', function ($m) {
            if (preg_match('/^\s*((?:<a[^>]*>\s*)?<img[^>]*>(?:\s*<\/a>)?)\s*(.*)$/is', $m[1], $p)) {
                $caption = trim(strip_tags($p[2]));

                return '<figure>'.$p[1].($caption !== '' ? '<figcaption>'.e($caption).'</figcaption>' : '').'</figure>';
            }

            return $m[1];
        }, $html) ?? $html;

        // Otros shortcodes ([gallery], [embed]...): no se pueden conservar.
        $removed = [];
        $html = preg_replace_callback('/\[\/?([a-z_][\w-]*)(?:\s[^\]]*)?\]/i', function ($m) use (&$removed) {
            $removed[strtolower($m[1])] = true;

            return '';
        }, $html) ?? $html;

        if ($removed) {
            $notes[] = 'shortcodes eliminados: ['.implode('], [', array_keys($removed)).']';
        }

        $html = $this->autop($html);

        // Encabezados: el CMS admite h2–h4.
        $html = preg_replace(['/<h1(\b[^>]*)>/i', '/<\/h1>/i', '/<h[56](\b[^>]*)>/i', '/<\/h[56]>/i'], ['<h2$1>', '</h2>', '<h4$1>', '</h4>'], $html) ?? $html;

        // Una imagen envuelta en un enlace a su propio archivo: el archivo antiguo desaparecerá.
        $html = preg_replace('/<a\b[^>]*href=["\'][^"\']+\.(?:jpe?g|png|webp|gif)(?:\?[^"\']*)?["\'][^>]*>\s*(<img\b[^>]*>)\s*<\/a>/i', '$1', $html) ?? $html;

        return $this->rewriteLinks($html, $blog, $notes);
    }

    /** Equivalente simplificado de wpautop: bloques separados por línea en blanco → <p>. */
    private function autop(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if ($text === '' || preg_match('/<p[\s>]/i', $text)) {
            return $text; // ya trae párrafos (editor de bloques o clásico guardado con <p>)
        }

        $out = [];

        foreach (preg_split('/\n{2,}/', $text) ?: [] as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            $out[] = preg_match('/^<(?:h[1-6]|ul|ol|table|blockquote|figure|div|pre|hr|section)\b/i', $block)
                ? $block
                : '<p>'.nl2br($block, false).'</p>';
        }

        return implode("\n", $out);
    }

    private function rewriteLinks(string $html, Blog $blog, array &$notes): string
    {
        $unresolved = 0;

        $html = preg_replace_callback('/(<a\b[^>]*\bhref=)(["\'])(.*?)\2/i', function ($m) use ($blog, &$unresolved) {
            $href = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $key = $this->normalizeUrl($href);

            if (isset($this->linkMap[$key])) {
                return $m[1].$m[2].'/'.config('publiblog.public_prefix').'/'.$this->linkMap[$key].$m[2];
            }

            $host = strtolower((string) parse_url($href, PHP_URL_HOST));

            if ($host !== '' && $this->sourceHost !== '' && $host === $this->sourceHost && ! preg_match('/\.(?:jpe?g|png|webp|gif|pdf)(?:\?|$)/i', $href)) {
                $unresolved++;
            }

            return $m[0];
        }, $html) ?? $html;

        if ($unresolved) {
            $notes[] = "{$unresolved} enlace(s) internos a páginas que no son entradas importadas (siguen apuntando al sitio antiguo)";
        }

        return $html;
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        if (! $parts || empty($parts['host'])) {
            return '';
        }

        return strtolower($parts['host']).'/'.trim(rawurldecode($parts['path'] ?? ''), '/');
    }

    private function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    /** @return list<string> */
    private function unsupportedIn(string $raw): array
    {
        $notes = [];

        foreach (['iframe' => 'vídeos/mapas incrustados', 'script' => 'scripts', 'style' => 'estilos', 'form' => 'formularios', 'video' => 'vídeo', 'audio' => 'audio', 'object' => 'objetos', 'embed' => 'objetos'] as $tag => $label) {
            $n = preg_match_all('/<'.$tag.'\b/i', $raw);

            if ($n) {
                $notes[] = "{$n} elemento(s) <{$tag}> eliminados ({$label} no soportado)";
            }
        }

        return $notes;
    }

    // ───────── imágenes ─────────

    private function rewriteImages(string $html, Blog $blog, Article $article, array $options, array &$notes): string
    {
        $map = [];

        preg_match_all('/<img\b[^>]*?\bsrc=(["\'])(.*?)\1/i', $html, $found);

        foreach (array_unique($found[2]) as $src) {
            $url = html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $media = $this->resolveImage($url, $blog, $article, $options, $notes);

            if ($media) {
                $map[$src] = '/'.config('publiblog.public_prefix').'/'.$media->remote_path;
            }
        }

        foreach ($map as $old => $new) {
            $html = str_replace(['src="'.$old.'"', "src='".$old."'"], ['src="'.$new.'"', 'src="'.$new.'"'], $html);
        }

        // WordPress añade srcset/sizes/clases: el saneador las quita; aquí solo se limpian las que apuntarían al sitio antiguo.
        return preg_replace('/\s(?:srcset|sizes)=(["\']).*?\1/is', '', $html) ?? $html;
    }

    /** Descarga (o localiza) una imagen y la guarda como cualquier otra del CMS. */
    private function resolveImage(string $url, Blog $blog, Article $article, array $options, array &$notes): ?Media
    {
        if (! preg_match('#^https?://#i', $url) && ! str_starts_with($url, '/')) {
            return null;
        }

        // Miniaturas de WordPress (foto-300x200.jpg): se intenta primero el original.
        $original = preg_replace('/-\d{2,4}x\d{2,4}(\.[a-z]{3,4})(\?.*)?$/i', '$1', $url) ?? $url;
        $candidates = array_unique([$original, $url]);

        $tmp = null;

        try {
            foreach ($candidates as $candidate) {
                $tmp = $this->fetch($candidate, $options);

                if ($tmp) {
                    break;
                }
            }

            if (! $tmp) {
                $notes[] = 'imagen no importada: '.$url;

                return null;
            }

            return $this->media->store($blog, $article, new UploadedFile($tmp, basename(parse_url($url, PHP_URL_PATH) ?: 'imagen.jpg'), null, null, true));
        } catch (InvalidArgumentException $e) {
            $notes[] = 'imagen no importada ('.$e->getMessage().'): '.$url;

            return null;
        } finally {
            if ($tmp) {
                @unlink($tmp);
            }
        }
    }

    /** @return string|null ruta de un archivo temporal con la imagen */
    private function fetch(string $url, array $options): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        // 1. Copia local de wp-content/uploads.
        if ($options['images_dir'] && ($pos = strpos($path, '/wp-content/uploads/')) !== false) {
            $relative = rawurldecode(substr($path, $pos + strlen('/wp-content/uploads/')));
            $base = realpath($options['images_dir']);
            $local = $base ? realpath($base.DIRECTORY_SEPARATOR.$relative) : false;

            if ($local && str_starts_with($local, $base.DIRECTORY_SEPARATOR) && is_file($local)) {
                $tmp = tempnam(sys_get_temp_dir(), 'wpimg');
                copy($local, $tmp);

                return $tmp;
            }
        }

        // 2. Descarga.
        if (! $options['download'] || ! preg_match('#^https?://#i', $url) || ! $this->isPublicHost($url)) {
            return null;
        }

        try {
            $response = Http::timeout(20)
                ->withUserAgent('Publiblog/1.0 (importador)')
                ->withOptions(['allow_redirects' => ['max' => 3, 'strict' => true, 'protocols' => ['http', 'https']]])
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || strlen($response->body()) > self::MAX_IMAGE_BYTES || $response->body() === '') {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'wpimg');
        File::put($tmp, $response->body());

        return $tmp;
    }

    /** El archivo XML no es de confianza: no se sigue una URL hacia la red interna (SSRF). */
    private function isPublicHost(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host === '' || strtolower($host) === 'localhost') {
            return false;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        // Si no resuelve, la descarga fallará sola.
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
