<?php

namespace Tests\Support;

use App\Models\Blog;
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

            // Lee el HTML realmente subido, resolviendo la regla del .htaccess como haría Apache.
            $slug = trim(parse_url($request->url(), PHP_URL_PATH), '/');
            $slug = substr($slug, strrpos($slug, '/') + 1);
            $ht = @file_get_contents($this->remoteDir.'/.htaccess') ?: '';

            if (preg_match('#RewriteRule \^'.preg_quote($slug, '#').'/\?\$ (\S+) \[END\]#', $ht, $m)
                && is_file($this->remoteDir.'/'.$m[1])) {
                return Http::response(file_get_contents($this->remoteDir.'/'.$m[1]), 200);
            }

            return Http::response('not found', 404);
        });
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
