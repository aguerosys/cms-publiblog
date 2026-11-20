<?php

namespace App\Services\Remote;

use RuntimeException;

/**
 * Certificado TLS de un servidor FTPS (TLS explícito: se abre la conexión, `AUTH TLS` y saludo TLS).
 *
 * El cliente FTP de PHP cifra la conexión pero **no verifica** el certificado del servidor, así que el CMS lo hace
 * aparte, justo antes de cada conexión: o bien la huella coincide con la confirmada (`pin`, como la huella SSH de
 * SFTP) o bien el certificado es válido para el nombre del servidor según las autoridades de confianza del sistema
 * (`ca`; sobrevive a las renovaciones de Let's Encrypt). Es una comprobación previa, no sobre la misma conexión.
 */
class FtpsServerCertificate
{
    /**
     * Huella SHA-256 del certificado, con el mismo formato que la huella SSH: `sha256:` + base64 sin relleno.
     *
     * @throws RuntimeException si no se llega al servidor o no ofrece TLS
     */
    public function fingerprint(string $host, int $port, int $timeout): string
    {
        return $this->handshake($host, $port, $timeout, verify: false);
    }

    /** ¿El certificado es válido (cadena y nombre del servidor) según las autoridades del sistema? */
    public function verifiesAgainstAuthorities(string $host, int $port, int $timeout): bool
    {
        try {
            $this->handshake($host, $port, $timeout, verify: true);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function handshake(string $host, int $port, int $timeout, bool $verify): string
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => false,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ]]);

        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (! $socket) {
            throw new RuntimeException("no se pudo abrir la conexión con {$host}:{$port} ([{$errno}] {$error})");
        }

        try {
            stream_set_timeout($socket, $timeout);

            $this->expect($socket, '220', 'el servidor no saludó como un servidor FTP');
            fwrite($socket, "AUTH TLS\r\n");
            $this->expect($socket, '234', 'el servidor no ofrece TLS (AUTH TLS)');

            if (! @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException($verify
                    ? 'el certificado del servidor no es válido para '.$host
                    : 'falló el saludo TLS con el servidor');
            }

            $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
            $digest = $certificate ? openssl_x509_fingerprint($certificate, 'sha256', true) : false;

            if (! is_string($digest) || $digest === '') {
                throw new RuntimeException('el servidor no presentó un certificado');
            }

            @fwrite($socket, "QUIT\r\n");

            return 'sha256:'.rtrim(base64_encode($digest), '=');
        } finally {
            @fclose($socket);
        }
    }

    /** Lee la respuesta (también las de varias líneas «220-…») y comprueba su código. @param resource $socket */
    private function expect($socket, string $code, string $failure): void
    {
        $line = '';

        for ($i = 0; $i < 50; $i++) {
            $line = fgets($socket, 2048);

            if ($line === false) {
                throw new RuntimeException($failure.' (sin respuesta)');
            }

            if (preg_match('/^(\d{3}) /', $line, $m)) {
                if ($m[1] !== $code) {
                    throw new RuntimeException($failure.' (respondió '.trim($line).')');
                }

                return;
            }
        }

        throw new RuntimeException($failure);
    }
}
