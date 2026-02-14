<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
