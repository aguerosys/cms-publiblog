<?php

namespace App\Services;

use App\Models\Blog;

/**
 * Huella del código de la plantilla de un blog (resources/views/blogs/{plantilla}/**).
 * Si cambia desde la última regeneración, el panel avisa de que los artículos ya publicados
 * llevan la versión anterior del diseño.
 */
class TemplateFingerprint
{
    public static function for(Blog $blog): string
    {
        $dir = resource_path('views/blogs/'.$blog->template);

        if (! is_dir($dir)) {
            return sha1('missing:'.$blog->template);
        }

        $parts = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $parts[substr($file->getPathname(), strlen($dir))] = sha1_file($file->getPathname());
            }
        }

        ksort($parts);

        return sha1(json_encode($parts));
    }

    /** ¿Cambió la plantilla desde la última vez que se generó el blog? (null = aún sin referencia) */
    public static function changed(Blog $blog): bool
    {
        return $blog->template_hash !== null && $blog->template_hash !== self::for($blog);
    }
}
