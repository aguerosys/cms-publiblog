<?php

namespace App\Services\Remote;

use App\Exceptions\PublishException;
use App\Models\Blog;
use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use RuntimeException;

/**
 * Abre el sistema de archivos remoto de un blog: SFTP o FTPS (producción) o una carpeta local
 * (solo desarrollo y pruebas, `sftp_auth = local`). El CMS solo escribe dentro de
 * `remote_dir`, la carpeta /blog/ del cliente.
 */
class RemoteFilesystemFactory
{
    public function make(Blog $blog): FilesystemOperator
    {
        if ($blog->isLocal()) {
            if (app()->environment('production')) {
                throw new RuntimeException('El transporte local no está permitido en producción.');
            }

            return new Filesystem(new LocalFilesystemAdapter(rtrim($blog->remote_dir, '/\\')));
        }

        if ($blog->isFtps()) {
            return $this->ftpsFilesystem($blog);
        }

        return new Filesystem(new SftpAdapter(
            $this->provider($blog),
            '/'.trim($blog->remote_dir ?: '/', '/')
        ));
    }

    /** Sistema de archivos FTPS del blog (punto único para construirlo: las pruebas lo sustituyen por una carpeta local). */
    public function ftpsFilesystem(Blog $blog, ?string $fingerprint = null, ?string $trust = null): FilesystemOperator
    {
        return new Filesystem($this->ftpsAdapter($blog, $fingerprint, $trust));
    }

    /**
     * Adaptador FTPS (FTP con TLS explícito; nunca FTP sin cifrar). El certificado se comprueba antes de cada conexión
     * (ver PinnedFtpConnectionProvider): `$fingerprint` y `$trust` solo se pasan al confirmar un certificado nuevo.
     */
    public function ftpsAdapter(Blog $blog, ?string $fingerprint = null, ?string $trust = null): FtpAdapter
    {
        if (! function_exists('ftp_ssl_connect')) {
            throw PublishException::make(PublishException::BLOCKED, 'Falta la extensión PHP «ftp» en el servidor del CMS (necesaria para FTPS).');
        }

        $options = FtpConnectionOptions::fromArray([
            'host' => (string) $blog->sftp_host,
            'root' => '/'.trim($blog->remote_dir ?: '/', '/'),
            'username' => (string) $blog->sftp_user,
            'password' => (string) $blog->sftp_secret,
            'port' => (int) $blog->sftp_port ?: 21,
            'ssl' => true,
            'timeout' => (int) config('publiblog.publish.sftp_timeout'),
            'passive' => true,
            // Muchos hostings detrás de NAT anuncian en PASV una IP interna: se usa la del servidor al que ya nos conectamos.
            'ignorePassiveAddress' => true,
        ]);

        return new FtpAdapter($options, new PinnedFtpConnectionProvider(
            app(FtpsServerCertificate::class),
            $fingerprint ?? $blog->host_key_fingerprint,
            $trust ?? ($blog->ftps_trust ?: 'pin'),
        ));
    }

    public function provider(Blog $blog, ?string $fingerprint = null): SftpConnectionProvider
    {
        $isKey = $blog->sftp_auth === 'key';

        return new SftpConnectionProvider(
            host: (string) $blog->sftp_host,
            username: (string) $blog->sftp_user,
            password: $isKey ? null : $blog->sftp_secret,
            privateKey: $isKey ? $blog->sftp_secret : null,
            passphrase: $isKey ? $blog->sftp_passphrase : null,
            port: (int) $blog->sftp_port ?: 22,
            useAgent: false,
            timeout: (int) config('publiblog.publish.sftp_timeout'),
            maxTries: 0, // sin reintentos en bucle ante credenciales o red caídas
            hostFingerprint: $fingerprint ?? $blog->host_key_fingerprint,
        );
    }
}
