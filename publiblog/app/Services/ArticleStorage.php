<?php

namespace App\Services;

use App\Exceptions\PublishException;
use App\Models\Blog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sistema de archivos del CMS (fuente de verdad):
 *
 *   storage/app/blogs/{blog_id}/articles/{shard}/{public_id}.json   editable
 *   storage/app/blogs/{blog_id}/articles/{shard}/{public_id}.html   último HTML generado
 *   storage/app/blogs/{blog_id}/media/{año}/{archivo}                imágenes reducidas
 *
 * {shard} son los tres primeros dígitos de public_id: ninguna carpeta pasa de 1.000 artículos.
 */
class ArticleStorage
{
    public function relativePath(int $blogId, string $publicId, string $ext): string
    {
        return $blogId.'/articles/'.substr($publicId, 0, 3).'/'.$publicId.'.'.$ext;
    }

    public function absolute(string $relative): string
    {
        return Storage::disk('blogs')->path($relative);
    }

    public function exists(int $blogId, string $publicId, string $ext = 'json'): bool
    {
        return Storage::disk('blogs')->exists($this->relativePath($blogId, $publicId, $ext));
    }

    public function writeJson(int $blogId, string $publicId, array $data): void
    {
        $this->writeAtomic(
            $this->relativePath($blogId, $publicId, 'json'),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
    }

    public function writeHtml(int $blogId, string $publicId, string $html): void
    {
        $this->writeAtomic($this->relativePath($blogId, $publicId, 'html'), $html);
    }

    public function readJson(int $blogId, string $publicId): ?array
    {
        $path = $this->relativePath($blogId, $publicId, 'json');

        if (! Storage::disk('blogs')->exists($path)) {
            return null;
        }

        return json_decode(Storage::disk('blogs')->get($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function readHtml(int $blogId, string $publicId): ?string
    {
        $path = $this->relativePath($blogId, $publicId, 'html');

        return Storage::disk('blogs')->exists($path) ? Storage::disk('blogs')->get($path) : null;
    }

    /** @return list<string> public_id de todos los JSON del blog, ordenados. */
    public function allPublicIds(Blog|int $blog): array
    {
        $id = $blog instanceof Blog ? $blog->id : $blog;
        $ids = [];

        foreach (Storage::disk('blogs')->files($id.'/articles', true) as $file) {
            if (preg_match('#/(\d{6})\.json$#', $file, $m)) {
                $ids[] = $m[1];
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * Escritura atómica: se escribe un archivo temporal y se renombra, para que un corte
     * no deje un JSON a medias.
     */
    public function writeAtomic(string $relative, string $contents): void
    {
        $target = $this->absolute($relative);
        $tmp = dirname($target).DIRECTORY_SEPARATOR.'.'.basename($target).'.'.bin2hex(random_bytes(4)).'.tmp';

        try {
            File::ensureDirectoryExists(dirname($target));

            if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
                throw new \RuntimeException("No se pudo escribir {$tmp}");
            }

            // En Windows, rename() sobre un archivo existente puede fallar un instante si
            // otro proceso (antivirus, indexador) lo tiene abierto: se reintenta brevemente.
            $renamed = false;
            for ($try = 0; $try < 8 && ! $renamed; $try++) {
                $renamed = @rename($tmp, $target);

                if (! $renamed) {
                    usleep(40_000);
                }
            }

            if (! $renamed) {
                throw new \RuntimeException("No se pudo renombrar a {$target}");
            }
        } catch (Throwable $e) {
            @unlink($tmp);

            throw PublishException::make(PublishException::LOCAL_IO, null, $e);
        }
    }
}
