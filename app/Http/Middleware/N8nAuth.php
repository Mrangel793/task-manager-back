<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class N8nAuth
{
    /**
     * Handle an incoming request.
     *
     * This middleware accepts two types of authentication:
     * 1. Bearer token (Sanctum) - for authenticated app users
     * 2. X-Webhook-Secret header - for n8n webhook calls
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Option 1: Check if user is authenticated via Sanctum (Bearer token)
        if ($request->bearerToken()) {
            // Try to authenticate with Sanctum
            $guard = Auth::guard('sanctum');
            if ($guard->check()) {
                // User is authenticated via Bearer token
                return $next($request);
            }
        }

        // Option 2: Check X-Webhook-Secret header (for n8n)
        $webhookSecret = config('n8n.webhook_secret');
        $requestSecret = $request->header('X-Webhook-Secret');

        // If webhook secret is configured and matches, allow the request
        if (!empty($webhookSecret) && $requestSecret === $webhookSecret) {
            return $next($request);
        }

        // If no webhook secret is configured at all, allow n8n requests without auth
        // (useful for development/testing)
        if (empty($webhookSecret) && !$request->bearerToken()) {
            Log::warning('N8n webhook secret not configured. Request allowed without authentication.', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);
            return $next($request);
        }

        // Neither authentication method was successful
        Log::warning('N8n/API authentication failed', [
            'ip' => $request->ip(),
            'path' => $request->path(),
            'has_bearer' => !empty($request->bearerToken()),
            'has_webhook_secret' => !empty($requestSecret),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized. Provide a valid Bearer token or X-Webhook-Secret.',
            'data' => null,
        ], 401);
    }
}
