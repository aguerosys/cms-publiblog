<?php

namespace App\Services\Remote;

use League\Flysystem\FilesystemOperator;
use Throwable;

/**
 * Subidas al servidor del cliente. El HTML se sube con nombre temporal y se renombra si
 * el servidor permite renombrar sobre un archivo existente; si no, se sobrescribe directamente.
 */
class RemoteWriter
{
    public static function put(FilesystemOperator $fs, string $path, string $contents): void
    {
        $tmp = dirname($path) === '.' ? '.'.basename($path).'.tmp' : dirname($path).'/.'.basename($path).'.tmp';

        $fs->write($tmp, $contents);

        try {
            $fs->move($tmp, $path);

            return;
        } catch (Throwable) {
            // El servidor no renombra sobre un archivo existente: sobrescribir directamente.
        }

        try {
            $fs->write($path, $contents);
        } finally {
            try {
                if ($fs->fileExists($tmp)) {
                    $fs->delete($tmp);
                }
            } catch (Throwable) {
                // el temporal huérfano no impide publicar
            }
        }
    }

    /** @param resource $stream */
    public static function putStream(FilesystemOperator $fs, string $path, $stream): void
    {
        $fs->writeStream($path, $stream);
    }

    public static function readOrNull(FilesystemOperator $fs, string $path): ?string
    {
        return $fs->fileExists($path) ? $fs->read($path) : null;
    }
}
