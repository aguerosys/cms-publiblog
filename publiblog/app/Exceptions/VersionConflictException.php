<?php

namespace App\Exceptions;

use RuntimeException;

/** Otra persona guardó el artículo después de que se abriera el formulario. */
class VersionConflictException extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('El artículo se modificó mientras lo editabas.');
    }
}
