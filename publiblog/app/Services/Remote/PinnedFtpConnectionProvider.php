<?php

namespace App\Services\Remote;

use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\Ftp\FtpConnectionProvider;
use League\Flysystem\Ftp\UnableToConnectToFtpHost;
use Throwable;

/**
 * Antes de abrir cada conexión FTPS comprueba el certificado del servidor (el cliente FTP de PHP no lo hace):
 *
 *   pin → su huella tiene que ser la confirmada por un administrador;
 *   ca  → tiene que ser válido para el nombre del servidor según las autoridades de confianza.
 *
 * Si no coincide no se envía ni el usuario ni la contraseña. Si no se llega al servidor, falla como una conexión normal.
 */
class PinnedFtpConnectionProvider extends FtpConnectionProvider
{
    public function __construct(
        private FtpsServerCertificate $certificate,
        private ?string $fingerprint,
        private string $trust,
    ) {
    }

    public function createConnection(FtpConnectionOptions $options)
    {
        $this->assertTrusted($options->host(), $options->port(), $options->timeout());

        return parent::createConnection($options);
    }

    public function assertTrusted(string $host, int $port, int $timeout): void
    {
        // Primero se comprueba que el servidor responde: un servidor caído es un fallo de conexión, no un certificado cambiado.
        try {
            $actual = $this->certificate->fingerprint($host, $port, $timeout);
        } catch (Throwable $e) {
            throw UnableToConnectToFtpHost::forHost($host, $port, true, $e->getMessage());
        }

        if ($this->trust === 'ca') {
            if (! $this->certificate->verifiesAgainstAuthorities($host, $port, $timeout)) {
                throw new FtpsCertificateMismatch('El certificado del servidor ya no es válido para '.$host.'.');
            }

            return;
        }

        if ($this->fingerprint === null || ! hash_equals($this->fingerprint, $actual)) {
            throw new FtpsCertificateMismatch('El certificado del servidor no es el confirmado ('.$actual.').');
        }
    }
}
