<?php

namespace App\Services\Remote;

use App\Exceptions\PublishException;
use App\Models\Blog;
use Illuminate\Support\Facades\Log;
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
     * `trust` y `ca_valid` solo los rellena FTPS: cómo se comprobará el certificado en adelante (`pin` o `ca`) y si es
     * ahora mismo válido para las autoridades de confianza.
     *
     * @return array{ok:bool, fingerprint:?string, message:string, code:?string, trust?:?string, ca_valid?:?bool}
     */
    public function test(Blog $blog, ?string $expectedFingerprint = null): array
    {
        if ($blog->isLocal()) {
            return $this->testWrite($blog, null);
        }

        if ($blog->isFtps()) {
            return $this->testFtps($blog, $expectedFingerprint);
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
            $this->logUnreachable($blog, get_class($e).': '.$e->getMessage());

            return ['ok' => false, 'fingerprint' => null, 'code' => 'connect', 'message' => 'No se pudo conectar con el servidor.'];
        }

        if ($fingerprint === null) {
            // phpseclib no lanza nada cuando el servidor no responde al saludo SSH: devuelve false.
            $this->logUnreachable($blog, 'el servidor no completó el saludo SSH (puerto cerrado, otro servicio o conexión cortada)');

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

    /**
     * FTPS: el certificado hace de huella. Confirmación (primera vez, o cambio aceptado): se guarda como `pin` o, si ya es
     * válido para las autoridades de confianza, como `ca` (así las renovaciones no bloquean el blog). Después, cada
     * conexión lo vuelve a comprobar (PinnedFtpConnectionProvider).
     *
     * @return array{ok:bool, fingerprint:?string, message:string, code:?string, trust:?string, ca_valid:?bool}
     */
    private function testFtps(Blog $blog, ?string $expectedFingerprint): array
    {
        $host = (string) $blog->sftp_host;
        $port = (int) $blog->sftp_port ?: 21;
        $timeout = (int) config('publiblog.publish.sftp_timeout');
        $certificate = app(FtpsServerCertificate::class);

        try {
            $fingerprint = $certificate->fingerprint($host, $port, $timeout);
        } catch (Throwable $e) {
            $this->logUnreachable($blog, 'FTPS: '.$e->getMessage());

            return ['ok' => false, 'fingerprint' => null, 'code' => 'connect', 'message' => 'No se pudo conectar con el servidor.', 'trust' => null, 'ca_valid' => null];
        }

        $caValid = $certificate->verifiesAgainstAuthorities($host, $port, $timeout);
        $known = $expectedFingerprint ?? $blog->host_key_fingerprint;
        $confirming = $expectedFingerprint !== null;

        // Con certificado de autoridad ya confirmado, una renovación (huella nueva) no es un cambio: se sigue mientras sea válido.
        $trust = $confirming || ! $known
            ? ($caValid ? 'ca' : 'pin')
            : ($blog->ftps_trust ?: 'pin');

        $acceptable = $trust === 'ca' ? $caValid : ($known !== null && hash_equals($known, $fingerprint));

        if ($known && ! $acceptable) {
            return ['ok' => false, 'fingerprint' => $fingerprint, 'code' => 'hostkey', 'message' => 'El certificado del servidor cambió.', 'trust' => null, 'ca_valid' => $caValid];
        }

        // Sin certificado confirmado todavía: solo se comprueba la conexión (y el panel pide confirmar la huella).
        $result = $this->testFtpsWrite($blog, $known ?: $fingerprint, $trust);
        $result['fingerprint'] = $fingerprint;
        $result['trust'] = $trust;
        $result['ca_valid'] = $caValid;

        return $result;
    }

    /** @return array{ok:bool, fingerprint:?string, message:string, code:?string} */
    private function testFtpsWrite(Blog $blog, string $fingerprint, string $trust): array
    {
        try {
            $fs = $this->factory->ftpsFilesystem($blog, $fingerprint, $trust);
            $this->probe($fs);
        } catch (Throwable $e) {
            $err = RemoteErrorMapper::map($e);

            // En FTP el servidor puede aceptar el login y fallar al abrir el canal de datos (puertos pasivos cerrados,
            // TLS en el canal de datos, carpeta sin permiso…): el panel es genérico, el detalle queda en el log.
            if (! in_array($err->errorCode, [PublishException::AUTH, PublishException::HOSTKEY], true)) {
                $reasons = [];

                for ($t = $e; $t; $t = $t->getPrevious()) {
                    $reasons[] = get_class($t).': '.$t->getMessage();
                }

                Log::warning('Publiblog: la prueba de escritura FTPS falló', [
                    'blog_id' => $blog->id,
                    'host' => $blog->sftp_host,
                    'port' => (int) $blog->sftp_port ?: 21,
                    'code' => $err->errorCode,
                    'reason' => implode(' | ', $reasons),
                ]);
            }

            return ['ok' => false, 'fingerprint' => null, 'code' => $err->errorCode, 'message' => $err->getMessage()];
        }

        return ['ok' => true, 'fingerprint' => null, 'code' => null, 'message' => 'Conexión correcta: se puede escribir en la carpeta remota.'];
    }

    /** Escribe y borra un archivo de prueba en la carpeta remota. */
    private function probe(\League\Flysystem\FilesystemOperator $fs): void
    {
        $probe = '.publiblog-test-'.bin2hex(random_bytes(3));
        $fs->write($probe, 'ok');
        $fs->delete($probe);
    }

    /**
     * El panel solo dice «No se pudo conectar»; el motivo real (rechazada, sin respuesta, tiempo agotado, DNS…) queda
     * en el log. Solo host y puerto: nunca usuario ni credenciales (aún no se han usado en este paso).
     */
    private function logUnreachable(Blog $blog, string $reason): void
    {
        Log::warning('Publiblog: la prueba de conexión no llegó al servidor '.($blog->isFtps() ? 'FTPS' : 'SSH'), [
            'blog_id' => $blog->id,
            'host' => $blog->sftp_host,
            'port' => (int) $blog->sftp_port ?: ($blog->isFtps() ? 21 : 22),
            'reason' => $reason,
        ]);
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

            $this->probe($fs);
        } catch (Throwable $e) {
            $err = RemoteErrorMapper::map($e);

            return ['ok' => false, 'fingerprint' => null, 'code' => $err->errorCode, 'message' => $err->getMessage()];
        }

        return ['ok' => true, 'fingerprint' => null, 'code' => null, 'message' => 'Conexión correcta: se puede escribir en la carpeta remota.'];
    }
}
