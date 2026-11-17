<?php

namespace Tests\Support;

use App\Models\Blog;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** Utilidades de prueba: blog con "servidor remoto" en una carpeta temporal. */
trait InteractsWithBlogs
{
    protected string $remoteDir;

    protected function setUpBlogs(): void
    {
        Storage::fake('blogs');

        $this->remoteDir = storage_path('framework/testing/remote-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->remoteDir);
    }

    protected function tearDownBlogs(): void
    {
        File::deleteDirectory($this->remoteDir);
    }

    protected function makeBlog(array $overrides = []): Blog
    {
        return Blog::create($overrides + [
            'name' => 'Cliente A',
            'domain' => 'cliente-a.com',
            'base_url' => 'https://cliente-a.com',
            'remote_dir' => $this->remoteDir,
            'locale' => 'es',
            'timezone' => 'Europe/Madrid',
            'template' => 'demoblog',
            'sftp_auth' => 'local',
            'verify_http' => false,
            'status' => Blog::STATUS_ACTIVE,
        ]);
    }

    /**
     * Lo que manda el formulario cuando quien edita elige una categoría («general», que se crea si hace falta).
     * Un artículo nuevo NO trae categoría: hay que elegirla para poder publicarlo.
     *
     * @return array{category_ids:list<int>, primary_category_id:int}
     */
    protected function chooseCategory(Blog $blog, string $slug = 'general'): array
    {
        $category = Category::firstOrCreate(['blog_id' => $blog->id, 'slug' => $slug], ['name' => ucfirst($slug)]);

        return ['category_ids' => [$category->id], 'primary_category_id' => $category->id];
    }

    protected function admin(): User
    {
        return User::factory()->create(['role' => 'superadmin']);
    }

    /** La verificación HTTP responde con el ID del artículo (o con el estado indicado). */
    protected function fakeVerification(int $status = 200): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());

        Http::fake(function ($request) use ($status) {
            if ($status !== 200) {
                return Http::response('error', $status);
            }

            // Lee el HTML realmente subido, resolviendo las reglas del .htaccess como haría Apache.
            $path = trim(parse_url($request->url(), PHP_URL_PATH), '/');
            $isDir = $path === 'blog' || str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/');
            $path = preg_replace('#^blog/?#', '', $path);

            // La carpeta de la sonda de SSI puede responder con un error (como un hosting que no permite «Options»).
            if (str_starts_with($path, '_ssi_probe') && $this->probeStatus !== null) {
                return Http::response('error', $this->probeStatus);
            }

            // Directorio: sirve su index.html.
            if ($isDir) {
                $path = ($path === '' ? '' : $path.'/').'index';
            }

            // Regla genérica: /categoria/slug sirve /categoria/slug.html
            if ($path !== '' && is_file($this->remoteDir.'/'.$path.'.html')) {
                return Http::response($this->serve($path.'.html'), 200);
            }

            // Artículos del formato anterior: una regla por artículo.
            $ht = @file_get_contents($this->remoteDir.'/.htaccess') ?: '';

            if (preg_match('#RewriteRule \^'.preg_quote($path, '#').'/\?\$ (\S+\.html) \[END\]#', $ht, $m)
                && is_file($this->remoteDir.'/'.$m[1])) {
                return Http::response($this->serve($m[1]), 200);
            }

            return Http::response('not found', 404);
        });
    }

    /** false = el "servidor" no ejecuta las inclusiones SSI (la directiva llega tal cual al navegador). */
    protected bool $serverRunsSsi = true;

    /** Si no es null, la carpeta de la sonda de SSI responde con este estado HTTP (p. ej. 500). */
    protected ?int $probeStatus = null;

    /**
     * Contenido de un archivo tal como lo serviría Apache: si el .htaccess activa el filtro INCLUDES para
     * los .html y el servidor lo soporta, las directivas <!--#include virtual="/blog/..." --> se sustituyen.
     */
    protected function serve(string $relative): string
    {
        $html = file_get_contents($this->remoteDir.'/'.$relative);
        $read = fn (string $f) => @file_get_contents($this->remoteDir.'/'.$f) ?: '';
        $active = str_contains($read('.htaccess'), 'AddOutputFilter INCLUDES .html')
            || str_contains($read(dirname($relative).'/.htaccess'), 'AddOutputFilter INCLUDES .html');

        if (! $active || ! $this->serverRunsSsi) {
            return $html;
        }

        return preg_replace_callback('#<!--\#include virtual="/blog/([^"]+)" -->#', function ($m) {
            $file = $this->remoteDir.'/'.$m[1];

            return is_file($file) ? file_get_contents($file) : '[an error occurred while processing this directive]';
        }, $html);
    }

    /** JPEG de prueba, opcionalmente con un segmento EXIF falso, ruidoso para que pese. */
    protected function jpegUpload(int $w = 3000, int $h = 2000, bool $exif = false, string $name = 'foto.jpg'): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);

        for ($i = 0; $i < 250; $i++) {
            $c = imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255));
            imagefilledrectangle($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
        }

        ob_start();
        imagejpeg($img, null, 100);
        $data = ob_get_clean();

        if ($exif) {
            $payload = "Exif\0\0".str_repeat('SECRETGPS', 20);
            $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
            $data = substr($data, 0, 2).$segment.substr($data, 2);
        }

        $path = tempnam(sys_get_temp_dir(), 'pbimg');
        file_put_contents($path, $data);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }
}
