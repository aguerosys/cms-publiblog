<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Blog;
use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Imágenes: subida, validación por contenido, recodificación con GD (elimina EXIF y
 * cualquier carga escondida), reducción de tamaño y conversión entre las rutas de
 * vista previa del CMS y las rutas públicas definitivas.
 */
class MediaService
{
    private const MAX_PIXELS = 60_000_000;

    public function store(Blog $blog, Article $article, UploadedFile $file): Media
    {
        $cfg = config('publiblog.images');

        if (! $file->isValid()) {
            throw new InvalidArgumentException('La imagen no se pudo subir.');
        }

        if ($file->getSize() > self::maxUploadBytes()) {
            throw new InvalidArgumentException('La imagen supera el tamaño máximo permitido ('.self::humanBytes(self::maxUploadBytes()).').');
        }

        $bytes = (string) @file_get_contents($file->getRealPath());
        $mime = $this->detectMime($bytes);

        if (! in_array($mime, $cfg['mimes'], true)) {
            throw new InvalidArgumentException('Solo se admiten imágenes JPEG, PNG y WebP.');
        }

        $info = @getimagesizefromstring($bytes);

        if (! $info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new InvalidArgumentException('La imagen no es válida o es demasiado grande.');
        }

        if ($mime === 'image/webp' && ! function_exists('imagewebp')) {
            throw new InvalidArgumentException('Este servidor no soporta WebP.');
        }

        [$binary, $ext, $width, $height] = $this->recode($bytes, $mime, $cfg);

        $sha = hash('sha256', $binary);
        $n = Media::where('article_id', $article->id)->count() + 1;
        $name = sprintf('%s-%d-%s.%s', $article->public_id, $n, substr($sha, 0, 8), $ext);
        $year = now()->format('Y');

        $local = "{$blog->id}/media/{$year}/{$name}";
        Storage::disk('blogs')->put($local, $binary);

        return Media::create([
            'blog_id' => $blog->id,
            'article_id' => $article->id,
            'local_path' => $local,
            'remote_path' => "img/{$year}/{$name}",
            'mime' => $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg'),
            'sha256' => $sha,
            'width' => $width,
            'height' => $height,
            'bytes' => strlen($binary),
        ]);
    }

    /** Tipo real por el contenido, nunca por la extensión ni por el tipo que declara el navegador. */
    private function detectMime(string $bytes): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string) finfo_buffer($finfo, $bytes);
        finfo_close($finfo);

        return $mime;
    }

    /** @return array{0:string,1:string,2:int,3:int} binario, extensión, ancho, alto */
    private function recode(string $bytes, string $mime, array $cfg): array
    {
        $this->raiseMemory();

        $image = @imagecreatefromstring($bytes);

        if (! $image) {
            throw new InvalidArgumentException('La imagen no se pudo procesar.');
        }

        if ($mime === 'image/jpeg') {
            $image = $this->applyExifOrientation($image, $bytes);
        }

        $w = imagesx($image);
        $h = imagesy($image);
        $max = $cfg['max_side'];

        if (max($w, $h) > $max) {
            $ratio = $max / max($w, $h);
            $scaled = imagescale($image, max(1, (int) round($w * $ratio)), max(1, (int) round($h * $ratio)), IMG_BICUBIC);

            if ($scaled) {
                $image = $scaled;
                $w = imagesx($image);
                $h = imagesy($image);
            }
        }

        ob_start();

        if ($mime === 'image/png') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagepng($image, null, 9);
            $ext = 'png';
        } elseif ($mime === 'image/webp') {
            imagepalettetotruecolor($image);
            imagewebp($image, null, $cfg['quality']);
            $ext = 'webp';
        } else {
            imageinterlace($image, true);
            imagejpeg($image, null, $cfg['quality']);
            $ext = 'jpg';
        }

        $binary = (string) ob_get_clean();

        return [$binary, $ext, $w, $h];
    }

    private function applyExifOrientation(\GdImage $image, string $bytes): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);
        $angle = match ($exif['Orientation'] ?? 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle !== 0 && ($rotated = imagerotate($image, $angle, 0))) {
            return $rotated;
        }

        return $image;
    }

    private function raiseMemory(): void
    {
        $limit = ini_get('memory_limit');

        if ($limit !== '-1' && $this->bytes($limit) < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }
    }

    /** Tamaño máximo de subida efectivo: el menor entre la configuración y los límites de PHP del hosting. */
    public static function maxUploadBytes(): int
    {
        $limits = [(int) config('publiblog.images.max_upload_kb') * 1024];

        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $bytes = self::iniBytes((string) ini_get($key));

            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return min($limits);
    }

    public static function iniBytes(string $value): int
    {
        $n = (int) $value;

        return match (strtolower(substr(trim($value), -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    public static function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024).' KB';
    }

    private function bytes(string $value): int
    {
        $n = (int) $value;

        return match (strtolower(substr(trim($value), -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /**
     * Vista previa → ruta pública: sustituye src="/blogs/1/media/5" por /blog/img/2026/…
     * y añade width y height para evitar saltos de maquetación.
     */
    public function toPublic(string $html, Blog $blog): string
    {
        return preg_replace_callback('#<img\b[^>]*>#i', function (array $m) use ($blog) {
            $tag = $m[0];

            if (! preg_match('#\ssrc=(["\'])(.*?)\1#i', $tag, $src)
                || ! preg_match('#^/blogs/(\d+)/media/(\d+)$#', $src[2], $ids)
                || (int) $ids[1] !== $blog->id) {
                return $tag;
            }

            $media = Media::where('blog_id', $blog->id)->find((int) $ids[2]);

            if (! $media) {
                return '';
            }

            $tag = preg_replace('#\s(?:src|width|height)=(["\']).*?\1#i', '', $tag);

            return preg_replace(
                '#\s*/?>$#',
                sprintf(' src="%s" width="%d" height="%d">', e($media->publicPath()), $media->width, $media->height),
                $tag
            );
        }, $html) ?? $html;
    }

    /** Ruta pública → vista previa protegida, para cargar el cuerpo en el editor. */
    public function toEditor(string $html, Blog $blog): string
    {
        $paths = $this->publicPathsIn($html);

        if ($paths === []) {
            return $html;
        }

        $prefix = '/'.config('publiblog.public_prefix').'/';
        $media = Media::where('blog_id', $blog->id)
            ->whereIn('remote_path', array_map(fn ($p) => substr($p, strlen($prefix)), $paths))
            ->get()
            ->keyBy(fn (Media $m) => $m->publicPath());

        return preg_replace_callback(
            $this->publicPathRegex(),
            fn (array $m) => isset($media[$m[0]]) ? $media[$m[0]]->previewUrl() : $m[0],
            $html
        ) ?? $html;
    }

    /** Imágenes del blog que el artículo usa (cuerpo + destacada). */
    public function usedMedia(Blog $blog, array $article): Collection
    {
        $prefix = '/'.config('publiblog.public_prefix').'/';
        $remote = array_map(fn ($p) => substr($p, strlen($prefix)), $this->publicPathsIn($article['body_html'] ?? ''));

        if (! empty($article['featured_image']['file'])) {
            $remote[] = $article['featured_image']['file'];
        }

        if ($remote === []) {
            return collect();
        }

        return Media::where('blog_id', $blog->id)->whereIn('remote_path', array_unique($remote))->get();
    }

    /** @return list<string> */
    private function publicPathsIn(string $html): array
    {
        preg_match_all($this->publicPathRegex(), $html, $m);

        return array_values(array_unique($m[0]));
    }

    private function publicPathRegex(): string
    {
        return '#/'.preg_quote(config('publiblog.public_prefix'), '#').'/img/\d{4}/[A-Za-z0-9._-]+#';
    }
}
