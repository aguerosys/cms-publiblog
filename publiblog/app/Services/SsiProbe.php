<?php

namespace App\Services;

use App\Models\Blog;
use App\Services\Remote\RemoteFilesystemFactory;
use App\Services\Remote\RemoteWriter;
use Illuminate\Support\Facades\Http;
use League\Flysystem\FilesystemOperator;
use Throwable;

/**
 * Comprueba, en una carpeta de pruebas aislada, que el servidor del cliente ejecuta las inclusiones SSI
 * ANTES de escribir ninguna directiva en el .htaccess real. Un `Options` que el servidor no permite
 * provoca un 500 en todo /blog/; aquí solo lo sufre la carpeta de pruebas, que se borra al terminar.
 *
 * Cada paso falla con un motivo legible; nunca deja archivos de prueba en el servidor.
 */
class SsiProbe
{
    public const DIR = '_ssi_probe';

    public function __construct(private RemoteFilesystemFactory $remote)
    {
    }

    /** @return array{ok:bool, message:string} */
    public function check(Blog $blog): array
    {
        $prefix = '/'.trim((string) config('publiblog.public_prefix'), '/');
        $marker = 'PUBLIBLOG-SSI-'.bin2hex(random_bytes(6));
        $files = [
            self::DIR.'/.htaccess' => "Options +IncludesNoExec\nAddOutputFilter INCLUDES .html\n",
            self::DIR.'/index.html' => '<!doctype html><title>sonda</title><p><!--#include virtual="'.$prefix.'/'.self::DIR.'/parte.html" --></p>',
            self::DIR.'/parte.html' => $marker,
        ];

        $fs = null;

        try {
            $fs = $this->remote->make($blog);

            foreach ($files as $path => $contents) {
                RemoteWriter::put($fs, $path, $contents);
            }

            $response = Http::timeout((int) config('publiblog.publish.verify_timeout'))
                ->withUserAgent('Publiblog/1.0 (comprobación de SSI)')
                ->get($blog->blogUrl().'/'.self::DIR.'/?_pb='.time());

            return $this->judge($response->status(), $response->body(), $marker);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'No se pudo comprobar el servidor: '.mb_substr($e->getMessage(), 0, 200)];
        } finally {
            $this->cleanup($fs, array_keys($files));
        }
    }

    /** @return array{ok:bool, message:string} */
    private function judge(int $status, string $body, string $marker): array
    {
        if ($status >= 500) {
            return ['ok' => false, 'message' => "El servidor responde con error (HTTP {$status}) al activar las inclusiones: probablemente no permite «Options» en el .htaccess (AllowOverride Options). Pide a quien administra el hosting que lo habilite."];
        }

        if ($status !== 200) {
            return ['ok' => false, 'message' => "No se pudo leer la página de prueba (HTTP {$status}). Si hay Cloudflare u otra protección, permite la IP del CMS."];
        }

        if (str_contains($body, '<!--#include')) {
            return ['ok' => false, 'message' => 'El servidor no ejecuta las inclusiones SSI (falta mod_include o el filtro no se aplica). Pide que lo habiliten.'];
        }

        if (! str_contains($body, $marker)) {
            return ['ok' => false, 'message' => 'La inclusión no devolvió el contenido esperado.'];
        }

        return ['ok' => true, 'message' => 'El servidor ejecuta las inclusiones SSI.'];
    }

    /** @param list<string> $paths */
    private function cleanup(?FilesystemOperator $fs, array $paths): void
    {
        if (! $fs) {
            return;
        }

        foreach ($paths as $path) {
            try {
                RemoteWriter::remove($fs, $path);
            } catch (Throwable) {
                // se reintenta en la siguiente comprobación; no debe tapar el resultado
            }
        }
    }
}
