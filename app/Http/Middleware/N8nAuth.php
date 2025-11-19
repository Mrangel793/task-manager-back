<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class N8nAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $webhookSecret = config('n8n.webhook_secret');

        // If no webhook secret is configured, allow the request
        // (useful for development/testing)
        if (empty($webhookSecret)) {
            Log::warning('N8n webhook secret not configured. Request allowed without authentication.');
            return $next($request);
        }

        // Get the secret from request header
        $requestSecret = $request->header('X-Webhook-Secret');

        // Verify the secret matches
        if ($requestSecret !== $webhookSecret) {
            Log::warning('N8n webhook authentication failed', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Invalid webhook secret.',
                'data' => null,
            ], 401);
        }

        return $next($request);
    }
}
