<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class SetOrganizationContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->organization_id) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario sin organización asignada.',
            ], 403);
        }

        // Load organization if not loaded
        $organization = $user->organization;

        // Un token de agente deja de servir si el usuario ya no está activo, perdió la
        // verificación de WhatsApp o su organización se desactivó: se borra y se responde 401.
        if ($user->isUsingAgentToken()
            && (!$user->is_active || !$user->whatsapp_verified || !$organization || !$organization->is_active)) {
            $user->currentAccessToken()->delete();

            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Cuenta desactivada: 401 para que el frontend vuelva al login, y se borra el
        // token usado (los demás tokens del usuario quedan, pero también recibirán 401).
        if (!$user->is_active) {
            $token = $user->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            return response()->json([
                'success' => false,
                'message' => 'Tu cuenta está inactiva. Contacta al administrador.',
            ], 401);
        }

        if (!$organization || !$organization->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'La organización está inactiva. Contacta al administrador.',
            ], 403);
        }

        // Set organization context in container
        app()->instance('current_organization_id', $user->organization_id);

        return $next($request);
    }
}
