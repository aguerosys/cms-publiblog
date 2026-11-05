<?php

namespace App\Services\Remote;

use App\Models\Blog;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use RuntimeException;

/**
 * Abre el sistema de archivos remoto de un blog: SFTP (producción) o una carpeta local
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

        return new Filesystem(new SftpAdapter(
            $this->provider($blog),
            '/'.trim($blog->remote_dir ?: '/', '/')
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
