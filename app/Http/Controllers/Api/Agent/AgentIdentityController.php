<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Api\WhatsAppLinkController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\AgentLinkRequest;
use App\Http\Requests\Agent\AgentPhoneRequest;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Models\WhatsAppLinkCode;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Identidad del agente autónomo de WhatsApp (rutas protegidas por agent.hmac).
 * No hay contexto de organización: el usuario se resuelve por teléfono quitando
 * sólo el OrganizationScope (el SoftDeletingScope se mantiene).
 */
class AgentIdentityController extends Controller
{
    public function link(AgentLinkRequest $request): JsonResponse
    {
        $phone = $request->input('phone');
        $code = (string) $request->input('code');
        $limiterKey = 'agent-link-fail:'.hash('sha256', $phone);
        $maxFailures = (int) config('agent.link_max_failed_attempts', 5);

        if (RateLimiter::tooManyAttempts($limiterKey, $maxFailures + 1)) {
            return response()->json([
                'success' => false,
                'message' => 'Demasiados intentos fallidos. Genera un código nuevo e inténtalo más tarde.',
            ], 429);
        }

        try {
            $result = DB::transaction(function () use ($phone, $code) {
                $linkCode = WhatsAppLinkCode::where('code_hash', WhatsAppLinkCode::hashCode($code))
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                if (! $linkCode) {
                    return 'invalid';
                }

                if (! $linkCode->isUsable()) {
                    $linkCode->increment('attempts');

                    return 'invalid';
                }

                $user = $this->usersWithoutOrgScope()
                    ->with('organization')
                    ->find($linkCode->user_id);

                if (! $user || ! $user->is_active || ! $user->organization?->is_active) {
                    return 'invalid';
                }

                $phoneTaken = $this->usersWithoutOrgScope()
                    ->withTrashed()
                    ->where('whatsapp_phone', $phone)
                    ->where('id', '!=', $user->id)
                    ->exists();

                if ($phoneTaken) {
                    return 'conflict';
                }

                // Update directo: el hook "updating" de User pondría whatsapp_verified=false
                // si el usuario ya estaba verificado con otro número (el flag no queda dirty).
                $this->usersWithoutOrgScope()->whereKey($user->id)->update([
                    'whatsapp_phone' => $phone,
                    'whatsapp_verified' => true,
                    'updated_at' => now(),
                ]);

                $linkCode->forceFill(['used_at' => now()])->save();

                return $user;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                $result = 'conflict';
            } else {
                Log::error('Error al vincular WhatsApp desde el agente', ['exception' => $e]);

                return $this->serverError();
            }
        }

        if ($result === 'conflict') {
            return response()->json([
                'success' => false,
                'message' => 'Este número de WhatsApp ya está vinculado a otro usuario.',
            ], 409);
        }

        if ($result === 'invalid') {
            RateLimiter::hit($limiterKey, (int) config('agent.link_code_ttl_minutes', 10) * 60);

            return response()->json([
                'success' => false,
                'message' => 'Código inválido o vencido.',
            ], 422);
        }

        RateLimiter::clear($limiterKey);

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $result->id,
                'name' => $result->name,
                'organization' => [
                    'id' => $result->organization->id,
                    'name' => $result->organization->name,
                ],
            ],
        ], 200);
    }

    public function whoami(AgentPhoneRequest $request): JsonResponse
    {
        $user = $this->findLinkedUser($request->input('phone'));

        if (! $user) {
            return $this->notFound();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'organization' => [
                    'id' => $user->organization->id,
                    'name' => $user->organization->name,
                ],
                'roles' => $user->getRoleNames()->values(),
                'permissions' => $user->getAllPermissions()->pluck('name')->values(),
                'role' => $user->role,
            ],
        ], 200);
    }

    public function token(AgentPhoneRequest $request): JsonResponse
    {
        $user = $this->findLinkedUser($request->input('phone'));

        if (! $user) {
            return $this->notFound();
        }

        try {
            $user->tokens()
                ->where('name', WhatsAppLinkController::AGENT_TOKEN_NAME)
                ->where('expires_at', '<', now())
                ->delete();

            $expiresAt = now()->addMinutes((int) config('agent.token_ttl_minutes', 15));
            $token = $user->createToken(WhatsAppLinkController::AGENT_TOKEN_NAME, ['agent'], $expiresAt);

            return response()->json([
                'success' => true,
                'data' => [
                    'token' => $token->plainTextToken,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Error al emitir token del agente', ['exception' => $e]);

            return $this->serverError();
        }
    }

    /**
     * Usuario con ese WhatsApp verificado, activo y de una organización activa.
     */
    private function findLinkedUser(string $phone): ?User
    {
        return $this->usersWithoutOrgScope()
            ->with('organization')
            ->where('whatsapp_phone', $phone)
            ->where('whatsapp_verified', true)
            ->where('is_active', true)
            ->whereHas('organization', fn ($q) => $q->where('is_active', true))
            ->first();
    }

    private function usersWithoutOrgScope()
    {
        return User::withoutGlobalScope(OrganizationScope::class);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'No encontrado.',
        ], 404);
    }

    private function serverError(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Ocurrió un error inesperado.',
        ], 500);
    }
}
