<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea operaciones sensibles de la cuenta cuando la petición llega con un
 * token de agente (ability "agent", sin "*"): refrescar el token (lo volvería
 * permanente), cambiar la contraseña o generar códigos de vinculación.
 */
class RejectAgentToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken && ! $token->can('*') && $token->can('agent')) {
            return response()->json([
                'success' => false,
                'message' => 'Esta acción no está permitida con un token de agente.',
            ], 403);
        }

        return $next($request);
    }
}
