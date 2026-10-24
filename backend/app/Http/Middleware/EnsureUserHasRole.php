<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Control de acceso granular por rol (CU-A01). Uso: ->middleware('role:ADMIN,TECHNICIAN')
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'No autenticado.');
        }

        $allowed = array_map(fn (string $r) => Role::from(strtoupper($r)), $roles);

        if (! $user->hasRole(...$allowed)) {
            abort(403, 'No tiene permisos para acceder a este recurso.');
        }

        return $next($request);
    }
}
