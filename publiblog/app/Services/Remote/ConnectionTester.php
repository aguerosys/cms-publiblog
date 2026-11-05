<?php

namespace App\Services\Remote;

use App\Models\Blog;
use phpseclib3\Net\SFTP;
use Throwable;

/**
 * "Probar conexión": conecta, obtiene la huella del servidor, se autentica y comprueba
 * que puede escribir y borrar dentro de la carpeta remota.
 */
class ConnectionTester
{
    public function __construct(private RemoteFilesystemFactory $factory)
    {
    }

    /**
     * @return array{ok:bool, fingerprint:?string, message:string, code:?string}
     */
    public function test(Blog $blog, ?string $expectedFingerprint = null): array
    {
        if ($blog->isLocal()) {
            return $this->testWrite($blog, null);
        }

        $fingerprint = null;

        try {
            $sftp = new SFTP((string) $blog->sftp_host, (int) $blog->sftp_port ?: 22, (int) config('publiblog.publish.sftp_timeout'));
            $key = $sftp->getServerPublicHostKey();

            if ($key !== false) {
                $parts = explode(' ', $key, 3);
                $fingerprint = 'sha256:'.rtrim(base64_encode(hash('sha256', (string) base64_decode($parts[1] ?? ''), true)), '=');
            }

            $sftp->disconnect();
        } catch (Throwable $e) {
            return ['ok' => false, 'fingerprint' => null, 'code' => 'connect', 'message' => 'No se pudo conectar con el servidor.'];
        }

        if ($fingerprint === null) {
            return ['ok' => false, 'fingerprint' => null, 'code' => 'connect', 'message' => 'No se pudo conectar con el servidor.'];
        }

        $known = $expectedFingerprint ?? $blog->host_key_fingerprint;

        if ($known && $known !== $fingerprint) {
            return ['ok' => false, 'fingerprint' => $fingerprint, 'code' => 'hostkey', 'message' => 'La huella del servidor cambió.'];
        }

        // Sin huella conocida todavía: solo se comprueba la conexión tras confirmarla con el usuario.
        $result = $this->testWrite($blog, $known ?: null, $known ? null : $fingerprint);
        $result['fingerprint'] = $fingerprint;

        return $result;
    }

    /** @return array{ok:bool, fingerprint:?string, message:string, code:?string} */
    private function testWrite(Blog $blog, ?string $fingerprint, ?string $trustOnce = null): array
    {
        try {
            if ($blog->isLocal()) {
                $fs = $this->factory->make($blog);
            } else {
                $fs = new \League\Flysystem\Filesystem(new \League\Flysystem\PhpseclibV3\SftpAdapter(
                    $this->factory->provider($blog, $fingerprint ?? $trustOnce),
                    '/'.trim($blog->remote_dir ?: '/', '/')
                ));
            }

            $probe = '.publiblog-test-'.bin2hex(random_bytes(3));
            $fs->write($probe, 'ok');
            $fs->delete($probe);
        } catch (Throwable $e) {
            $err = RemoteErrorMapper::map($e);

            return ['ok' => false, 'fingerprint' => null, 'code' => $err->errorCode, 'message' => $err->getMessage()];
        }

        return ['ok' => true, 'fingerprint' => null, 'code' => null, 'message' => 'Conexión correcta: se puede escribir en la carpeta remota.'];
    }
}
