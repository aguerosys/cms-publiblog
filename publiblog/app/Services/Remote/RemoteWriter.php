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

    /**
     * Borra un archivo y las carpetas que queden vacías por encima (nunca la raíz ni una carpeta con algo dentro).
     * Una carpeta vacía serviría un 403 o un listado en vez de un 404.
     *
     * @return bool si el archivo existía y se borró
     */
    public static function remove(FilesystemOperator $fs, string $path): bool
    {
        if (! $fs->fileExists($path)) {
            return false;
        }

        $fs->delete($path);

        $dir = dirname($path);

        while ($dir !== '.' && $dir !== '' && $dir !== '/') {
            try {
                if (iterator_count($fs->listContents($dir, false)) > 0) {
                    break;
                }

                $fs->deleteDirectory($dir);
            } catch (Throwable) {
                break; // limpiar carpetas es un extra: nunca debe hacer fallar la publicación
            }

            $dir = dirname($dir);
        }

        return true;
    }
}
