<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppLinkCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Vinculación del WhatsApp del usuario autenticado con el agente autónomo.
 */
class WhatsAppLinkController extends Controller
{
    public const AGENT_TOKEN_NAME = 'whatsapp-agent';

    /**
     * Genera un código de 6 dígitos (válido 10 min) e invalida los anteriores.
     * El código en claro sólo se devuelve en esta respuesta.
     */
    public function createCode(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $expiresAt = now()->addMinutes((int) config('agent.link_code_ttl_minutes', 10));

            $code = DB::transaction(function () use ($user, $expiresAt) {
                WhatsAppLinkCode::where('user_id', $user->id)->whereNull('used_at')->delete();

                // Evita que dos códigos vigentes de distintos usuarios coincidan.
                do {
                    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $hash = WhatsAppLinkCode::hashCode($code);
                } while (WhatsAppLinkCode::where('code_hash', $hash)
                    ->whereNull('used_at')
                    ->where('expires_at', '>', now())
                    ->exists());

                WhatsAppLinkCode::create([
                    'user_id' => $user->id,
                    'code_hash' => $hash,
                    'expires_at' => $expiresAt,
                ]);

                return $code;
            });

            return response()->json([
                'success' => true,
                'message' => 'Envía este código al agente por WhatsApp para vincular tu número.',
                'data' => [
                    'code' => $code,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Error al generar código de vinculación de WhatsApp', ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo generar el código de vinculación.',
            ], 500);
        }
    }

    /**
     * Desvincula el WhatsApp del usuario: borra el número, la verificación,
     * los códigos pendientes y revoca los tokens de agente vigentes.
     */
    public function unlink(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            DB::transaction(function () use ($user) {
                $user->forceFill([
                    'whatsapp_phone' => null,
                    'whatsapp_verified' => false,
                ])->save();

                WhatsAppLinkCode::where('user_id', $user->id)->whereNull('used_at')->delete();
                $user->tokens()->where('name', self::AGENT_TOKEN_NAME)->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'WhatsApp desvinculado.',
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Error al desvincular WhatsApp', ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo desvincular el WhatsApp.',
            ], 500);
        }
    }
}
