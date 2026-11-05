<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Fase 1: un único usuario con todos los permisos. Los roles llegan en la fase 2. */
class EnsureSuperadmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isSuperadmin(), 403);

        return $next($request);
    }
}
