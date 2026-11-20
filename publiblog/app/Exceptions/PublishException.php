<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Fallo controlado de una publicación. El código se guarda en deployments.error_code
 * y el mensaje es el que ve el usuario (ver tabla de errores del plan).
 */
class PublishException extends RuntimeException
{
    public const LOCAL_IO = 'local_io';
    public const CONNECT = 'connect';
    public const TIMEOUT = 'timeout';
    public const AUTH = 'auth';
    public const HOSTKEY = 'hostkey';
    public const PERMISSION = 'permission';
    public const DISK_FULL = 'disk_full';
    public const INCOMPLETE = 'incomplete';
    public const VERIFY = 'verify';
    public const LOCK_TIMEOUT = 'lock_timeout';
    public const BLOCKED = 'blocked';
    public const VALIDATION = 'validation';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function make(string $code, ?string $detail = null, ?Throwable $previous = null): self
    {
        $message = match ($code) {
            self::LOCAL_IO => 'No se pudo escribir el contenido en el CMS.',
            self::CONNECT => 'No se pudo conectar con el servidor.',
            self::TIMEOUT => 'No se pudo conectar con el servidor (tiempo agotado).',
            self::AUTH => 'Credenciales rechazadas por el servidor.',
            self::HOSTKEY => 'La huella del servidor (o su certificado, en FTPS) cambió. Un administrador debe confirmarla.',
            self::PERMISSION => 'Sin permiso para escribir en el servidor',
            self::DISK_FULL => 'No hay espacio en el servidor',
            self::INCOMPLETE => 'Publicación incompleta.',
            self::VERIFY => 'Subido, pero la URL no responde.',
            self::LOCK_TIMEOUT => 'Hay otra publicación en curso.',
            self::BLOCKED => 'Este blog no puede publicar hasta resolver el problema de conexión.',
            self::VALIDATION => 'El artículo no está completo.',
            default => 'Error inesperado al publicar.',
        };

        if ($detail !== null && $detail !== '') {
            $message .= ' '.$detail;
        }

        return new self($code, trim($message), $previous);
    }
}
