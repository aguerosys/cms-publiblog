<?php

namespace App\Services;

use App\Models\Blog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ArticleIdService
{
    public const MAX = 999999;

    /**
     * Siguiente ID público del blog: seis dígitos con ceros a la izquierda,
     * secuencial por blog. El contador se incrementa bajo bloqueo de fila, así
     * dos peticiones simultáneas nunca reciben el mismo número.
     */
    public function next(Blog $blog): string
    {
        return DB::transaction(function () use ($blog) {
            $fresh = Blog::query()->whereKey($blog->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->last_public_id >= self::MAX) {
                throw new RuntimeException('El blog ha agotado los IDs numéricos (999.999).');
            }

            $fresh->last_public_id++;
            $fresh->save();

            $blog->last_public_id = $fresh->last_public_id;

            return str_pad((string) $fresh->last_public_id, 6, '0', STR_PAD_LEFT);
        });
    }

    /** Para el importador/reindex: asegura que el contador no quede por debajo de un ID existente. */
    public function ensureAtLeast(Blog $blog, int $id): void
    {
        DB::transaction(function () use ($blog, $id) {
            $fresh = Blog::query()->whereKey($blog->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->last_public_id < $id) {
                $fresh->last_public_id = $id;
                $fresh->save();
                $blog->last_public_id = $id;
            }
        });
    }
}
