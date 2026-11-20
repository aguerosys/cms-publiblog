<?php

namespace App\Services;

use App\Models\Blog;
use RuntimeException;

/**
 * Archivos estáticos propios de una plantilla (su `blog.css`, tipografías, imágenes del diseño…):
 * `resources/views/blogs/{plantilla}/assets/**`.
 *
 * Viajan con el código de la plantilla y el CMS los sube a `/blog/_assets/` junto con los listados, solo cuando
 * cambian (mismo manifiesto por hash que los listados y el sitemap). Así cambiar el CSS de un blog no depende de
 * que el equipo de la web del cliente lo suba aparte.
 *
 * La plantilla los enlaza con `@templateAsset('blog.css')`, que añade `?v={huella}`: al cambiar el archivo cambia
 * el enlace y los navegadores y Cloudflare no sirven la versión anterior. Es código del repositorio (de confianza),
 * pero solo se suben tipos de archivo de diseño: nunca PHP, HTML, `.htaccess` ni archivos ocultos.
 */
class TemplateAssets
{
    /** Carpeta del servidor (dentro de /blog/) donde se publican. */
    public const DIR = '_assets';

    public const EXTENSIONS = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2'];

    public const MAX_BYTES = 5 * 1024 * 1024;

    /** @var array<string,string> ruta|mtime|tamaño => huella corta (una regeneración lee el CSS una sola vez) */
    private static array $versions = [];

    public static function dir(string $template): ?string
    {
        if (! preg_match('/^[a-z0-9_-]+$/', $template)) {
            return null;
        }

        $dir = resource_path("views/blogs/{$template}/assets");

        return is_dir($dir) ? $dir : null;
    }

    /** ¿Es un nombre de archivo publicable? Segmentos simples, sin ocultos y con extensión de diseño permitida. */
    public static function isSafeName(string $name): bool
    {
        if ($name === '' || strlen($name) > 120 || ! preg_match('#^[A-Za-z0-9_-][A-Za-z0-9._-]*(?:/[A-Za-z0-9_-][A-Za-z0-9._-]*)*$#', $name)) {
            return false;
        }

        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * Archivos publicables de la plantilla: ruta dentro de assets/ => ruta en disco.
     *
     * @return array<string,string>
     */
    public function list(string $template): array
    {
        $found = [];

        foreach ($this->scan($template) as $name => $path) {
            if (self::isSafeName($name) && filesize($path) <= self::MAX_BYTES) {
                $found[$name] = $path;
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Archivos de assets/ que NO se publicarían, con el motivo (lo muestra el validador de plantillas).
     *
     * @return list<string>
     */
    public function problems(string $template): array
    {
        $problems = [];

        foreach ($this->scan($template) as $name => $path) {
            if (! self::isSafeName($name)) {
                $problems[] = "assets/{$name}: nombre o tipo no permitido (solo ".implode(', ', self::EXTENSIONS).'; sin archivos ocultos ni caracteres raros)';
            } elseif (filesize($path) > self::MAX_BYTES) {
                $problems[] = "assets/{$name}: pesa más de ".(self::MAX_BYTES / 1024 / 1024).' MB';
            }
        }

        return $problems;
    }

    /**
     * Contenido que sube el CMS: ruta relativa a /blog/ (`_assets/blog.css`) => contenido.
     *
     * @return array<string,string>
     */
    public function files(Blog $blog): array
    {
        $files = [];

        foreach ($this->list((string) $blog->template) as $name => $path) {
            $files[self::DIR.'/'.$name] = (string) file_get_contents($path);
        }

        return $files;
    }

    /** Dirección pública del archivo, con su huella: /blog/_assets/blog.css?v=1a2b3c4d */
    public function url(?object $context, string $file): string
    {
        if (! $context) {
            throw new RuntimeException('La plantilla solo se puede generar desde Publiblog.');
        }

        $template = (string) $context->template;
        $path = $this->list($template)[$file] ?? null;

        if ($path === null) {
            throw new RuntimeException("La plantilla «{$template}» no tiene el archivo assets/{$file} (o no es un tipo permitido).");
        }

        $key = $path.'|'.filemtime($path).'|'.filesize($path);
        $version = self::$versions[$key] ??= substr((string) sha1_file($path), 0, 8);

        return '/'.trim((string) config('publiblog.public_prefix'), '/').'/'.self::DIR.'/'.$file.'?v='.$version;
    }

    /** @return array<string,string> ruta relativa => ruta en disco, de TODOS los archivos de assets/ (incluidos los no permitidos) */
    private function scan(string $template): array
    {
        $dir = self::dir($template);

        if ($dir === null) {
            return [];
        }

        $found = [];
        $base = rtrim(str_replace('\\', '/', (string) realpath($dir)), '/');

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $path = str_replace('\\', '/', (string) $file->getRealPath());
                $found[ltrim(substr($path, strlen($base)), '/')] = $file->getRealPath();
            }
        }

        return $found;
    }
}
