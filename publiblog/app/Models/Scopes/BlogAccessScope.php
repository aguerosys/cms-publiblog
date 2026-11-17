<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Aislamiento por cliente (F2-1): con un usuario "blog_user" autenticado, toda consulta de blogs,
 * artículos, medios y despliegues queda limitada a sus blogs. Sin usuario (colas, cron, comandos
 * artisan) o con un superadmin no se filtra nada.
 *
 * Es una segunda barrera: las políticas y el enlace de rutas ya impiden el acceso cruzado,
 * y esta evita que un olvido en un controlador filtre datos de otro cliente.
 */
class BlogAccessScope implements Scope
{
    public function __construct(private string $column = 'blog_id')
    {
    }

    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user instanceof \App\Models\User || $user->isSuperadmin()) {
            return;
        }

        $builder->whereIn($model->qualifyColumn($this->column), $user->accessibleBlogIds());
    }
}
