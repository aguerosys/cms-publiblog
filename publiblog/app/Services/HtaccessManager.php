<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Blog;
use App\Services\Remote\RemoteWriter;
use League\Flysystem\FilesystemOperator;

/**
 * Gestiona el bloque delimitado de /blog/.htaccess. Solo escribe entre las dos marcas y deja intacto
 * el resto del archivo.
 *
 * El bloque tiene un tamaño casi fijo, no crece con el número de artículos (Apache relee el .htaccess
 * en cada petición): cuatro reglas genéricas y, solo para los artículos que cambiaron de URL, una
 * redirección 301 por URL antigua.
 *
 *   # BEGIN publiblog
 *   RewriteEngine On
 *   RewriteBase /blog/
 *   # 1. lo que existe (imágenes, listados, sitemap) se sirve tal cual
 *   RewriteCond %{REQUEST_FILENAME} -f [OR]
 *   RewriteCond %{REQUEST_FILENAME} -d
 *   RewriteRule ^ - [END]
 *   # 2. las URL de artículo no llevan barra final
 *   RewriteRule ^(.+)/$ https://dominio.com/blog/$1 [R=301,L]
 *   # 3. /categoria/slug -> /categoria/slug.html
 *   RewriteCond %{REQUEST_FILENAME}.html -f
 *   RewriteRule ^(.+)$ $1.html [END]
 *   # 4. artículos anteriores a las categorías (000123-slug.html) y URL antiguas
 *   RewriteRule ^slug/?$ 000123-slug.html [END]
 *   RewriteRule ^vieja/url/?$ https://dominio.com/blog/categoria/slug [R=301,L]
 *   # END publiblog
 */
class HtaccessManager
{
    public const FILE = '.htaccess';
    public const BACKUP = '.htaccess.bak';
    public const BEGIN = '# BEGIN publiblog';
    public const END = '# END publiblog';

    /**
     * Genera el bloque desde la tabla `articles`: los artículos que están en el servidor
     * (remote_path no nulo), más $include y menos $exclude.
     */
    public function buildBlock(Blog $blog, ?Article $include = null, ?Article $exclude = null): string
    {
        $articles = Article::query()
            ->where('blog_id', $blog->id)
            ->whereNotNull('remote_path')
            ->when($exclude, fn ($q) => $q->where('id', '!=', $exclude->id))
            ->orderBy('public_id')
            ->get(['id', 'blog_id', 'public_id', 'slug', 'remote_path', 'redirects']);

        if ($include) {
            $articles = $articles->reject(fn (Article $a) => $a->id === $include->id)->push($include);
        }

        $lines = $this->baseLines($blog);
        $taken = [];

        foreach ($articles->sortBy('public_id') as $article) {
            foreach ($this->articleLines($blog, $article) as $source => $line) {
                // Dos artículos no pueden reclamar la misma URL: gana el primero (por ID).
                if (! isset($taken[$source])) {
                    $taken[$source] = true;
                    $lines[] = $line;
                }
            }
        }

        $lines[] = self::END;

        return implode("\n", $lines);
    }

    /**
     * Reglas fijas del bloque (con las marcas de inicio, sin la de fin).
     *
     * @return list<string>
     */
    public function baseLines(Blog $blog): array
    {
        $prefix = trim((string) config('publiblog.public_prefix'), '/');
        $target = $this->origin($blog).'/'.$prefix.'/';

        $lines = [self::BEGIN];

        // Cabecera y pie compartidos: el servidor ejecuta <!--#include --> en los .html (sin ejecutar programas).
        if ($blog->ssi_enabled) {
            array_push($lines, '# Cabecera y pie compartidos (SSI).', 'Options +IncludesNoExec', 'AddOutputFilter INCLUDES .html');
        }

        return [
            ...$lines,
            'RewriteEngine On',
            "RewriteBase /{$prefix}/",
            '# Lo que existe (imagenes, listados, sitemap) se sirve tal cual.',
            'RewriteCond %{REQUEST_FILENAME} -f [OR]',
            'RewriteCond %{REQUEST_FILENAME} -d',
            'RewriteRule ^ - [END]',
            '# Las URL de articulo no llevan barra final.',
            'RewriteRule ^(.+)/$ '.$target.'$1 [R=301,L]',
            '# /categoria/slug sirve /categoria/slug.html',
            'RewriteCond %{REQUEST_FILENAME}.html -f',
            'RewriteRule ^(.+)$ $1.html [END]',
            '# Articulos del formato anterior y URL antiguas con redireccion 301.',
        ];
    }

    /**
     * Reglas propias de un artículo, indexadas por la URL de origen: la regla del formato anterior
     * (archivo en la raíz) y una redirección por cada URL antigua distinta de la actual.
     *
     * @return array<string,string> ruta de origen => línea del .htaccess
     */
    public function articleLines(Blog $blog, Article $article): array
    {
        if (! $article->remote_path || ! $article->slug) {
            return [];
        }

        $lines = [];
        $current = ArticleLocator::publicPath($article->remote_path);
        $prefix = trim((string) config('publiblog.public_prefix'), '/');
        $origin = $this->origin($blog);

        // Solo rutas ya validadas: nunca texto libre dentro del .htaccess.
        if (ArticleLocator::isLegacy($article->remote_path)
            && ArticleLocator::isValidPath($current)
            && preg_match('/^\d{6}-[a-z0-9-]+\.html$/', $article->remote_path)) {
            $lines[$current] = sprintf('RewriteRule ^%s/?$ %s [END]', $current, $article->remote_path);
        }

        if (! ArticleLocator::isValidPath($current)) {
            return $lines;
        }

        foreach ($article->redirects ?? [] as $old) {
            if (is_string($old) && $old !== $current && ArticleLocator::isValidPath($old)) {
                $lines[$old] = sprintf('RewriteRule ^%s/?$ %s/%s/%s [R=301,L]', $old, $origin, $prefix, $current);
            }
        }

        return $lines;
    }

    /** Líneas que debe contener el bloque del servidor para que este artículo funcione. */
    public function requiredLines(Blog $blog, Article $article): array
    {
        return array_values($this->articleLines($blog, $article));
    }

    /**
     * Líneas del bloque gestionado que hay en un .htaccess, o null si no tiene el bloque.
     *
     * @return list<string>|null
     */
    public function parseBlock(?string $text): ?array
    {
        if ($text === null || ! preg_match('/'.preg_quote(self::BEGIN, '/').'(.*?)'.preg_quote(self::END, '/').'/s', $text, $m)) {
            return null;
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", self::BEGIN.$m[1])),
            fn ($l) => $l !== ''
        ));
    }

    /** Sustituye el bloque gestionado (o lo añade al final) sin tocar el resto. */
    public function merge(?string $existing, string $block): string
    {
        $existing = (string) $existing;
        $pattern = '/'.preg_quote(self::BEGIN, '/').'.*?'.preg_quote(self::END, '/').'/s';

        if (preg_match($pattern, $existing)) {
            return preg_replace_callback($pattern, fn () => $block, $existing, 1);
        }

        $existing = rtrim($existing);

        return ($existing === '' ? '' : $existing."\n\n").$block."\n";
    }

    /**
     * Copia previa, subida del bloque nuevo y devuelve si hubo cambios.
     *
     * @return array{changed:bool, had_original:bool}
     */
    public function apply(FilesystemOperator $fs, string $block): array
    {
        $existing = RemoteWriter::readOrNull($fs, self::FILE);
        $merged = $this->merge($existing, $block);

        if ($existing !== null && trim($existing) === trim($merged)) {
            return ['changed' => false, 'had_original' => true];
        }

        if ($existing !== null) {
            $fs->write(self::BACKUP, $existing);
        }

        RemoteWriter::put($fs, self::FILE, $merged);

        return ['changed' => true, 'had_original' => $existing !== null];
    }

    /** Deshace la última subida: restaura la copia previa o, si no había archivo, lo borra. */
    public function restore(FilesystemOperator $fs, bool $hadOriginal): void
    {
        if ($hadOriginal && $fs->fileExists(self::BACKUP)) {
            RemoteWriter::put($fs, self::FILE, $fs->read(self::BACKUP));
        } elseif (! $hadOriginal && $fs->fileExists(self::FILE)) {
            $fs->delete(self::FILE);
        }
    }

    /** https://dominio.com (sin barra final), o vacío si la URL base no es un origen simple y seguro. */
    private function origin(Blog $blog): string
    {
        $origin = rtrim((string) $blog->base_url, '/');

        return preg_match('~^https?://[A-Za-z0-9.-]+(?::\d{1,5})?$~', $origin) === 1 ? $origin : '';
    }
}
