<?php

namespace App\Services;

use App\Models\Blog;

/**
 * Dónde vive un artículo en el servidor del cliente y qué URL tiene.
 *
 *   archivo   {categoria-principal}/{slug}.html     (se guarda en articles.remote_path)
 *   URL       /blog/{categoria-principal}/{slug}    (sin extensión: la sirve una única regla del .htaccess)
 *
 * Los artículos anteriores a las categorías están en la raíz como `000123-slug.html` y su URL era
 * `/blog/slug`; siguen funcionando hasta que se republican (ver HtaccessManager).
 */
class ArticleLocator
{
    /** Ruta pública válida: segmentos a-z0-9 con guiones, separados por «/». Nunca texto libre en el .htaccess. */
    public const PATH_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*$/';

    /** Máximo de URLs antiguas que se recuerdan por artículo. */
    public const MAX_REDIRECTS = 50;

    public static function file(string $categorySlug, string $slug): string
    {
        return "{$categorySlug}/{$slug}.html";
    }

    /** Archivo que le corresponde según su JSON (categoría principal = la primera), o null si el JSON no tiene categorías. */
    public static function fileFromJson(array $json): ?string
    {
        $category = $json['categories'][0]['slug'] ?? null;
        $slug = $json['slug'] ?? null;

        if (! is_string($category) || ! is_string($slug) || $category === '' || $slug === '') {
            return null;
        }

        return self::file($category, $slug);
    }

    /** ¿Es un archivo del formato anterior (en la raíz, `000123-slug.html`)? */
    public static function isLegacy(string $file): bool
    {
        return ! str_contains($file, '/');
    }

    /** Ruta pública (relativa a /blog/) de un archivo ya publicado. */
    public static function publicPath(string $file): string
    {
        $name = preg_replace('/\.html$/', '', $file);

        if (self::isLegacy($file)) {
            return preg_replace('/^\d{6}-/', '', $name);
        }

        return $name;
    }

    public static function isValidPath(string $path): bool
    {
        return preg_match(self::PATH_PATTERN, $path) === 1;
    }

    /**
     * Ruta antigua (relativa a /blog/) de una URL de la web anterior, si se puede redirigir desde este
     * /blog/.htaccess: mismo dominio y dentro del prefijo del blog. Si no, null.
     */
    public static function redirectSource(Blog $blog, string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (! $parts || empty($parts['host']) || empty($parts['path'])) {
            return null;
        }

        $strip = fn (string $host) => preg_replace('/^www\./', '', strtolower($host));
        $own = parse_url((string) $blog->base_url, PHP_URL_HOST);

        if (! $own || $strip($parts['host']) !== $strip($own)) {
            return null;
        }

        $prefix = trim((string) config('publiblog.public_prefix'), '/');
        $path = trim(rawurldecode($parts['path']), '/');

        if (! str_starts_with($path, $prefix.'/')) {
            return null;
        }

        $rest = substr($path, strlen($prefix) + 1);

        return self::isValidPath($rest) ? $rest : null;
    }
}
