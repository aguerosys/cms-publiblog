<?php

namespace App\Services\Remote;

use RuntimeException;

/**
 * El certificado del servidor FTPS ya no es el confirmado (o ha dejado de ser válido). El mapa de errores lo traduce
 * al mismo estado que un cambio de huella SSH: el blog deja de publicar hasta que un administrador lo confirme.
 */
class FtpsCertificateMismatch extends RuntimeException
{
}
