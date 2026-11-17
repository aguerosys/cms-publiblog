<?php

namespace App\Services;

use App\Models\Blog;
use App\Models\Media;
use App\Services\Remote\RemoteErrorMapper;
use App\Services\Remote\RemoteFilesystemFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Limpieza de imágenes huérfanas (F2-6): las que se subieron al editor y ya no usa ningún artículo
 * (ni su borrador ni lo publicado), y los archivos del almacén sin registro en la base de datos.
 *
 * Seguridad: una imagen solo se borra si NINGÚN JSON del blog la menciona y pasó el periodo de
 * gracia (por defecto 14 días), para no tocar lo que alguien está subiendo mientras escribe.
 */
class MediaCleaner
{
    public function __construct(
        private ArticleStorage $storage,
        private MediaService $media,
        private RemoteFilesystemFactory $remote,
        private ArticlePublisher $publisher,
    ) {
    }

    /**
     * @return array{rows: list<Media>, files: list<string>, bytes: int}
     */
    public function find(Blog $blog, int $graceDays = 14): array
    {
        $referenced = $this->referenced($blog);
        $cutoff = now()->subDays($graceDays);

        $rows = Media::where('blog_id', $blog->id)
            ->where('created_at', '<', $cutoff)
            ->get()
            ->filter(fn (Media $m) => ! isset($referenced[$m->remote_path]))
            ->values()
            ->all();

        $known = Media::where('blog_id', $blog->id)->pluck('local_path')->flip();
        $disk = Storage::disk('blogs');
        $files = [];

        foreach ($disk->files($blog->id.'/media', true) as $file) {
            if (! isset($known[$file]) && $disk->lastModified($file) < $cutoff->getTimestamp()) {
                $files[] = $file;
            }
        }

        $bytes = array_sum(array_map(fn (Media $m) => $m->bytes, $rows))
            + array_sum(array_map(fn ($f) => $disk->size($f), $files));

        return ['rows' => $rows, 'files' => $files, 'bytes' => $bytes];
    }

    /**
     * @return array{rows:int, files:int, remote:int, bytes:int, failed:int, error:?string}
     */
    public function clean(Blog $blog, int $graceDays = 14, bool $dryRun = false, bool $includeRemote = true): array
    {
        $found = $this->find($blog, $graceDays);
        $result = ['rows' => 0, 'files' => 0, 'remote' => 0, 'bytes' => 0, 'failed' => 0, 'error' => null];

        if ($dryRun) {
            return ['rows' => count($found['rows']), 'files' => count($found['files']), 'remote' => 0, 'bytes' => $found['bytes'], 'failed' => 0, 'error' => null];
        }

        $disk = Storage::disk('blogs');
        $fs = null;
        $lock = null;

        $needsRemote = $includeRemote && collect($found['rows'])->contains(fn (Media $m) => $m->uploaded_at !== null);

        if ($needsRemote) {
            try {
                $this->publisher->guardBlog($blog);
                $lock = Cache::lock("blog:{$blog->id}", (int) config('publiblog.publish.lock_ttl'));
                $lock->block((int) config('publiblog.publish.lock_wait'));
                $fs = $this->remote->make($blog->fresh());
            } catch (LockTimeoutException) {
                $result['error'] = 'El blog está ocupado con otra operación; se limpiará la próxima vez.';
                $lock = null;
                $found['rows'] = array_filter($found['rows'], fn (Media $m) => $m->uploaded_at === null);
            } catch (Throwable $e) {
                $result['error'] = RemoteErrorMapper::map($e)->getMessage();
                $found['rows'] = array_filter($found['rows'], fn (Media $m) => $m->uploaded_at === null);
            }
        } elseif (! $includeRemote) {
            // Sin tocar el servidor: solo las que nunca se subieron.
            $found['rows'] = array_filter($found['rows'], fn (Media $m) => $m->uploaded_at === null);
        }

        try {
            foreach ($found['rows'] as $media) {
                try {
                    if ($media->uploaded_at !== null && $fs) {
                        if ($fs->fileExists($media->remote_path)) {
                            $fs->delete($media->remote_path);
                        }

                        $result['remote']++;
                    }

                    $disk->delete($media->local_path);
                    $result['bytes'] += $media->bytes;
                    $media->delete();
                    $result['rows']++;
                } catch (Throwable $e) {
                    // Si el servidor falla, la fila se queda para la siguiente pasada.
                    $result['failed']++;
                    $result['error'] ??= RemoteErrorMapper::map($e)->getMessage();
                }
            }
        } finally {
            $lock?->release();
        }

        foreach ($found['files'] as $file) {
            $size = $disk->size($file);
            $disk->delete($file);
            $result['files']++;
            $result['bytes'] += $size;
        }

        if ($result['rows'] + $result['files'] > 0) {
            ActivityLogger::record('blog.media_cleaned', $blog, details: [
                'images' => $result['rows'], 'orphan_files' => $result['files'], 'remote' => $result['remote'], 'bytes' => $result['bytes'],
            ], userId: null);
        }

        return $result;
    }

    /** @return array<string,true> rutas remotas mencionadas por algún JSON del blog (borrador o publicado) */
    private function referenced(Blog $blog): array
    {
        $paths = [];

        foreach ($this->storage->allPublicIds($blog) as $publicId) {
            foreach ([$this->storage->readJson($blog->id, $publicId), $this->readPublished($blog, $publicId)] as $json) {
                foreach ($json ? $this->media->referencedPaths($json) : [] as $path) {
                    $paths[$path] = true;
                }
            }
        }

        return $paths;
    }

    private function readPublished(Blog $blog, string $publicId): ?array
    {
        $path = $this->storage->relativePath($blog->id, $publicId, 'published.json');

        return Storage::disk('blogs')->exists($path)
            ? json_decode(Storage::disk('blogs')->get($path), true)
            : null;
    }
}
