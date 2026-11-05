<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Blog;
use App\Services\Remote\RemoteWriter;
use League\Flysystem\FilesystemOperator;

/**
 * Gestiona el bloque delimitado de /blog/.htaccess con una regla por artículo (opción A).
 * Solo escribe entre las dos marcas y deja intacto el resto del archivo.
 *
 *   # BEGIN publiblog
 *   RewriteEngine On
 *   RewriteBase /blog/
 *   RewriteRule ^mi-slug/?$ 000123-mi-slug.html [END]
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
            ->get();

        if ($include && ! $articles->contains('id', $include->id)) {
            $articles->push($include);
        }

        $lines = [
            self::BEGIN,
            'RewriteEngine On',
            'RewriteBase /'.config('publiblog.public_prefix').'/',
        ];

        foreach ($articles->sortBy('public_id') as $article) {
            // Solo slugs ya validados: nunca texto libre dentro del .htaccess.
            if (! app(SlugService::class)->isValid((string) $article->slug) || ! preg_match('/^\d{6}$/', $article->public_id)) {
                continue;
            }

            $lines[] = sprintf('RewriteRule ^%s/?$ %s [END]', $article->slug, $article->fileName());
        }

        $lines[] = self::END;

        return implode("\n", $lines);
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
}
