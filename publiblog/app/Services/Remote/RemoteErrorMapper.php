<?php

namespace App\Services\Remote;

use App\Exceptions\PublishException;
use League\Flysystem\Ftp\UnableToAuthenticate as FtpUnableToAuthenticate;
use League\Flysystem\Ftp\UnableToConnectToFtpHost;
use League\Flysystem\Ftp\UnableToResolveConnectionRoot;
use League\Flysystem\PhpseclibV3\UnableToAuthenticate;
use League\Flysystem\PhpseclibV3\UnableToConnectToSftpHost;
use League\Flysystem\PhpseclibV3\UnableToEstablishAuthenticityOfHost;
use Throwable;

/** Traduce los fallos de Flysystem/phpseclib a los códigos de error del plan. */
class RemoteErrorMapper
{
    public static function map(Throwable $e): PublishException
    {
        if ($e instanceof PublishException) {
            return $e;
        }

        // Flysystem envuelve el fallo real (p. ej. UnableToWriteFile → UnableToAuthenticate):
        // la causa de conexión se busca en toda la cadena, no solo en la excepción exterior.
        $cause = self::find($e, [
            UnableToEstablishAuthenticityOfHost::class, FtpsCertificateMismatch::class,
            UnableToAuthenticate::class, FtpUnableToAuthenticate::class,
            UnableToConnectToSftpHost::class, UnableToConnectToFtpHost::class,
            UnableToResolveConnectionRoot::class,
        ]);

        if ($cause instanceof UnableToEstablishAuthenticityOfHost || $cause instanceof FtpsCertificateMismatch) {
            return PublishException::make(PublishException::HOSTKEY, null, $e);
        }

        if ($cause instanceof UnableToAuthenticate || $cause instanceof FtpUnableToAuthenticate) {
            return PublishException::make(PublishException::AUTH, null, $e);
        }

        $text = strtolower(self::chain($e));
        $path = method_exists($e, 'location') ? (string) $e->location() : null;

        if ($cause instanceof UnableToResolveConnectionRoot) {
            return PublishException::make(PublishException::PERMISSION, '(la carpeta remota no existe o no se puede abrir)', $e);
        }

        if ($cause instanceof UnableToConnectToSftpHost || $cause instanceof UnableToConnectToFtpHost) {
            return PublishException::make(
                str_contains($text, 'timed out') || str_contains($text, 'timeout')
                    ? PublishException::TIMEOUT
                    : PublishException::CONNECT,
                null,
                $e
            );
        }

        if (preg_match('/disk full|no space|quota|space left/', $text)) {
            return PublishException::make(PublishException::DISK_FULL, $path ? "({$path})" : null, $e);
        }

        if (preg_match('/permission|denied|read-only|not permitted|failure/', $text)) {
            return PublishException::make(PublishException::PERMISSION, $path ? "({$path})" : null, $e);
        }

        if (preg_match('/timed out|timeout/', $text)) {
            return PublishException::make(PublishException::TIMEOUT, null, $e);
        }

        if (preg_match('/connect|connection|socket|broken pipe|eof/', $text)) {
            return PublishException::make(PublishException::CONNECT, null, $e);
        }

        return PublishException::make(PublishException::INCOMPLETE, $path ? "({$path})" : null, $e);
    }

    /** @param list<class-string> $classes en orden de prioridad */
    private static function find(Throwable $e, array $classes): ?Throwable
    {
        foreach ($classes as $class) {
            for ($t = $e; $t; $t = $t->getPrevious()) {
                if ($t instanceof $class) {
                    return $t;
                }
            }
        }

        return null;
    }

    private static function chain(Throwable $e): string
    {
        $parts = [];

        for ($t = $e; $t; $t = $t->getPrevious()) {
            $parts[] = $t->getMessage();
        }

        return implode(' | ', $parts);
    }
}
