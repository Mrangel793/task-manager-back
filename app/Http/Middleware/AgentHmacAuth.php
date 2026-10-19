<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica al servicio del agente de WhatsApp con una firma HMAC-SHA256.
 *
 * Mensaje firmado: timestamp \n nonce \n METHOD \n path?query (tal cual llegó) \n raw body.
 * Rechaza (401) si no hay secreto configurado, si el timestamp se sale de la
 * ventana permitida, si el nonce ya se usó o si la firma no coincide.
 */
class AgentHmacAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Las rutas del agente siempre responden JSON (incluidos los 422 de validación).
        $request->headers->set('Accept', 'application/json');

        $secret = config('agent.hmac_secret');

        if (! is_string($secret) || $secret === '') {
            Log::error('AGENT_HMAC_SECRET no configurado. Peticion del agente rechazada.', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->unauthorized();
        }

        $timestamp = $request->header('X-Agent-Timestamp');
        $nonce = $request->header('X-Agent-Nonce');
        $signature = $request->header('X-Agent-Signature');

        if (! is_string($timestamp) || ! preg_match('/^\d{1,12}$/', $timestamp)
            || ! is_string($nonce) || ! preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $nonce)
            || ! is_string($signature) || ! preg_match('/^[0-9a-fA-F]{64}$/', $signature)) {
            return $this->reject($request, 'cabeceras ausentes o con formato invalido');
        }

        if (abs(time() - (int) $timestamp) > (int) config('agent.hmac_max_skew_seconds', 300)) {
            return $this->reject($request, 'timestamp fuera de ventana');
        }

        $expected = hash_hmac('sha256', implode("\n", [
            $timestamp,
            $nonce,
            strtoupper($request->getMethod()),
            self::signedPath($request),
            $request->getContent(),
        ]), $secret);

        if (! hash_equals($expected, strtolower($signature))) {
            return $this->reject($request, 'firma invalida');
        }

        // Cache::add es atómico: sólo el primer uso del nonce lo registra.
        if (! Cache::add('agent_hmac_nonce:'.hash('sha256', $nonce), 1, (int) config('agent.nonce_ttl_seconds', 600))) {
            return $this->reject($request, 'nonce repetido');
        }

        return $next($request);
    }

    /**
     * Ruta relativa a la raíz de la app más la query string cruda,
     * p. ej. /api/agent/v1/whoami?phone=%2B573001234567.
     */
    public static function signedPath(Request $request): string
    {
        $query = (string) $request->server('QUERY_STRING', '');

        return $request->getPathInfo().($query !== '' ? '?'.$query : '');
    }

    private function reject(Request $request, string $reason): JsonResponse
    {
        Log::warning('Autenticacion HMAC del agente fallida', [
            'reason' => $reason,
            'ip' => $request->ip(),
            'path' => $request->path(),
        ]);

        return $this->unauthorized();
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized.',
        ], 401);
    }
}
