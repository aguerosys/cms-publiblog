<?php

namespace App\Services;

use App\Models\Blog;
use RuntimeException;

/**
 * Cabecera y pie de la plantilla (`partials/header.blade.php` y `partials/footer.blade.php`).
 *
 * Las plantillas los colocan con las directivas @siteHeader y @siteFooter. Según el blog:
 *   - inline (por defecto): la cabecera/pie se copia dentro de cada página, como siempre;
 *   - SSI: la página lleva <!--#include virtual="/blog/_inc/header.html" --> y la cabecera vive en un
 *     único archivo del servidor. Cambiarla es subir un archivo, no regenerar todos los artículos.
 *
 * Los parciales son los mismos en los dos modos y solo reciben `$blog`: son iguales para todas las
 * páginas (no pueden usar el artículo ni el SEO).
 */
class SitePartials
{
    public const PARTS = ['header', 'footer'];

    /** Carpeta del servidor donde viven los fragmentos del modo SSI. */
    public const DIR = '_inc';

    /** Lo que ve la plantilla al usar @siteHeader / @siteFooter. */
    public function render(string $part, object $blog, ?object $context): string
    {
        if (! in_array($part, self::PARTS, true)) {
            throw new RuntimeException("Parte desconocida: {$part}");
        }

        if (! $context) {
            throw new RuntimeException('La plantilla solo se puede generar desde Publiblog.');
        }

        return $context->ssi
            ? '<!--#include virtual="'.self::virtualPath($part).'" -->'
            : $this->fragment($context->template, $part, $blog);
    }

    /** HTML del parcial, tal como queda en la página (modo inline) o en el archivo compartido (modo SSI). */
    public function fragment(string $template, string $part, object $blog): string
    {
        if (! preg_match('/^[a-z0-9_-]+$/', $template)) {
            throw new RuntimeException("Plantilla no válida: {$template}");
        }

        $view = "blogs.{$template}.partials.{$part}";

        if (! view()->exists($view)) {
            throw new RuntimeException("Falta el parcial {$view}");
        }

        return view($view, ['blog' => $blog])->render();
    }

    /** Ruta absoluta de la inclusión: /blog/_inc/header.html */
    public static function virtualPath(string $part): string
    {
        return '/'.trim((string) config('publiblog.public_prefix'), '/').'/'.self::DIR.'/'.$part.'.html';
    }

    /** Ruta del archivo dentro de /blog/ (relativa al directorio remoto): _inc/header.html */
    public static function file(string $part): string
    {
        return self::DIR.'/'.$part.'.html';
    }

    /**
     * ¿Usa la plantilla las directivas en sus dos vistas y tiene los dos parciales? Sin eso no se puede
     * compartir la cabecera: seguiría copiada dentro de cada página.
     */
    public static function templateSupports(string $template): bool
    {
        if (! preg_match('/^[a-z0-9_-]+$/', $template)) {
            return false;
        }

        $dir = resource_path("views/blogs/{$template}");

        foreach (['article', 'index'] as $view) {
            $path = "{$dir}/{$view}.blade.php";

            if (! is_file($path)) {
                return false;
            }

            $source = (string) file_get_contents($path);

            if (substr_count($source, '@siteHeader') !== 1 || substr_count($source, '@siteFooter') !== 1) {
                return false;
            }
        }

        foreach (self::PARTS as $part) {
            if (! is_file("{$dir}/partials/{$part}.blade.php")) {
                return false;
            }
        }

        return true;
    }

    /**
     * Archivos compartidos del blog (vacío si no usa SSI): ruta relativa a /blog/ => contenido.
     *
     * @return array<string,string>
     */
    public function files(Blog $blog, object $blogData): array
    {
        if (! $blog->ssi_enabled) {
            return [];
        }

        $files = [];

        foreach (self::PARTS as $part) {
            $files[self::file($part)] = $this->fragment($blog->template, $part, $blogData);
        }

        return $files;
    }
}
