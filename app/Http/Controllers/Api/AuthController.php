<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Register a new user account.
     *
     * Creates a new user with email-based authentication.
     * Account is active immediately, no verification required.
     *
     * @param RegisterRequest $request
     * @return JsonResponse
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            if ($request->invite_code) {
                // Join existing organization (no global scope on Organization model)
                $organization = Organization::where('invite_code', $request->invite_code)
                    ->where('is_active', true)
                    ->firstOrFail();

                $role = 'Operador';
                $message = 'Usuario registrado exitosamente.';
            } else {
                // Create new organization
                $organization = Organization::create([
                    'name' => $request->organization_name,
                    'is_active' => true,
                ]);

                $role = 'Admin';
                $message = 'Organización creada exitosamente.';
            }

            // Set organization context for global scope BEFORE creating user
            app()->instance('current_organization_id', $organization->id);

            // Create user
            $user = new User();
            $user->organization_id = $organization->id;
            $user->email = $request->email;
            $user->phone = $request->phone ?? null;
            $user->name = $request->name;
            $user->password = $request->password;
            $user->role = $role;
            $user->is_active = true;
            $user->save();

            // Assign role
            $user->assignRole($role);

            DB::commit();

            // Generate Sanctum token
            $token = $user->createToken('auth_token')->plainTextToken;

            $responseData = [
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'name' => $user->name,
                    'role' => $user->role,
                    'is_active' => $user->is_active,
                ],
                'organization' => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                ],
                'access_token' => $token,
                'token_type' => 'Bearer',
            ];

            // Include invite_code only for the org creator (Admin)
            if ($role === 'Admin') {
                $responseData['organization']['invite_code'] = $organization->invite_code;
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $responseData,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en registro de usuario: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al registrar el usuario.',
                'errors' => ['server' => [$e->getMessage()]],
                'debug' => config('app.debug') ? [
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                ] : null,
            ], 500);
        }
    }

    /**
     * Verify user's phone number with verification code.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string',
            'code' => 'required|string|size:6',
        ], [
            'phone.required' => 'El número de teléfono es obligatorio.',
            'code.required' => 'El código de verificación es obligatorio.',
            'code.size' => 'El código de verificación debe tener 6 dígitos.',
        ]);

        try {
            // Find user by phone (withoutGlobalScopes since user is not authenticated yet)
            $user = User::withoutGlobalScopes()->where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado.',
                    'errors' => ['phone' => ['No existe un usuario con este número de teléfono.']],
                ], 404);
            }

            // Get WhatsApp session
            $whatsappSession = WhatsAppSession::where('user_id', $user->id)->first();

            if (!$whatsappSession) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sesión de verificación no encontrada.',
                    'errors' => ['session' => ['No se encontró una sesión de verificación activa.']],
                ], 404);
            }

            // Verify code
            if ($whatsappSession->verify($request->code)) {
                // Activate user account
                $user->update(['is_active' => true]);

                // Generate Sanctum token
                $token = $user->createToken('auth_token')->plainTextToken;

                return response()->json([
                    'success' => true,
                    'message' => 'Teléfono verificado exitosamente.',
                    'data' => [
                        'user' => [
                            'id' => $user->id,
                            'phone' => $user->phone,
                            'name' => $user->name,
                            'role' => $user->role,
                            'is_active' => $user->is_active,
                            'whatsapp_verified' => $user->whatsapp_verified,
                        ],
                        'access_token' => $token,
                        'token_type' => 'Bearer',
                    ],
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Código de verificación inválido.',
                'errors' => ['code' => ['El código de verificación es incorrecto.']],
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error en verificación de teléfono: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al verificar el teléfono.',
                'errors' => ['server' => ['Ocurrió un error inesperado. Por favor, inténtelo de nuevo.']],
            ], 500);
        }
    }

    /**
     * Login user and generate authentication token.
     *
     * @param LoginRequest $request
     * @return JsonResponse
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            // Find user by email (withoutGlobalScopes since user is not authenticated yet)
            $user = User::withoutGlobalScopes()->where('email', $request->email)->first();

            // Validate credentials
            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales inválidas.',
                    'errors' => ['credentials' => ['El correo electrónico o la contraseña son incorrectos.']],
                ], 401);
            }

            // Check if user is active
            if (!$user->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cuenta inactiva.',
                    'errors' => ['account' => ['Tu cuenta está inactiva. Contacta al administrador.']],
                ], 403);
            }

            // Update last login timestamp
            $user->update(['last_login_at' => now()]);

            // Generate Sanctum token
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Inicio de sesión exitoso.',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'name' => $user->name,
                        'role' => $user->role,
                        'is_active' => $user->is_active,
                        'whatsapp_verified' => $user->whatsapp_verified,
                        'permissions' => $user->getAllPermissions()->pluck('name'),
                    ],
                    'organization' => [
                        'id' => $user->organization->id,
                        'name' => $user->organization->name,
                        'slug' => $user->organization->slug,
                    ],
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en inicio de sesión: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al iniciar sesión.',
                'errors' => ['server' => ['Ocurrió un error inesperado. Por favor, inténtelo de nuevo.']],
            ], 500);
        }
    }

    /**
     * Logout user and revoke current authentication token.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            // Revoke current token
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Sesión cerrada exitosamente.',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al cerrar sesión: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al cerrar sesión.',
                'errors' => ['server' => ['Ocurrió un error inesperado. Por favor, inténtelo de nuevo.']],
            ], 500);
        }
    }

    /**
     * Refresh authentication token.
     *
     * Revokes the current token and generates a new one.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function refreshToken(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Revoke current token
            $request->user()->currentAccessToken()->delete();

            // Generate new token
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Token actualizado exitosamente.',
                'data' => [
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al refrescar token: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al refrescar el token.',
                'errors' => ['server' => ['Ocurrió un error inesperado. Por favor, inténtelo de nuevo.']],
            ], 500);
        }
    }

    /**
     * Get authenticated user information.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $organization = $user->organization;

            $userData = [
                'id' => $user->id,
                'email' => $user->email,
                'phone' => $user->phone,
                'name' => $user->name,
                'role' => $user->role,
                'is_active' => $user->is_active,
                'whatsapp_verified' => $user->whatsapp_verified,
                'whatsapp_phone' => $user->whatsapp_phone,
                'notification_preferences' => $user->notification_preferences,
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'roles' => $user->getRoleNames(),
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ];

            $orgData = [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ];

            // Only Admin can see the invite_code
            if ($user->hasRole('Admin')) {
                $orgData['invite_code'] = $organization->invite_code;
            }

            $userData['organization'] = $orgData;

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $userData,
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al obtener información del usuario: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener información del usuario.',
                'errors' => ['server' => ['Ocurrió un error inesperado. Por favor, inténtelo de nuevo.']],
            ], 500);
        }
    }

    /**
     * Update authenticated user profile.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'phone' => 'sometimes|nullable|string|max:20',
            'whatsapp_phone' => 'sometimes|nullable|string|max:20',
            'notification_preferences' => 'sometimes|array',
        ], [
            'name.max' => 'El nombre no puede tener más de 255 caracteres.',
            'email.email' => 'El correo electrónico debe ser válido.',
            'email.unique' => 'Este correo electrónico ya está en uso.',
            'phone.max' => 'El teléfono no puede tener más de 20 caracteres.',
            'whatsapp_phone.max' => 'El teléfono de WhatsApp no puede tener más de 20 caracteres.',
        ]);

        try {
            $user->update($request->only([
                'name',
                'email',
                'phone',
                'whatsapp_phone',
                'notification_preferences',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Perfil actualizado exitosamente.',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'name' => $user->name,
                        'role' => $user->role,
                        'is_active' => $user->is_active,
                        'whatsapp_verified' => $user->whatsapp_verified,
                        'whatsapp_phone' => $user->whatsapp_phone,
                        'notification_preferences' => $user->notification_preferences,
                        'permissions' => $user->getAllPermissions()->pluck('name'),
                        'roles' => $user->getRoleNames(),
                    ],
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al actualizar perfil: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el perfil.',
                'errors' => ['server' => ['Ocurrió un error inesperado. Por favor, inténtelo de nuevo.']],
            ], 500);
        }
    }

    /**
     * Change user password.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ], [
            'current_password.required' => 'La contraseña actual es obligatoria.',
            'new_password.required' => 'La nueva contraseña es obligatoria.',
            'new_password.min' => 'La nueva contraseña debe tener al menos 8 caracteres.',
            'new_password.confirmed' => 'La confirmación de la nueva contraseña no coincide.',
        ]);

        try {
            $user = $request->user();

            // Verify current password
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'La contraseña actual es incorrecta.',
                    'errors' => ['current_password' => ['La contraseña actual no es correcta.']],
                ], 422);
            }

            // Check if new password is different from current
            if (Hash::check($request->new_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'La nueva contraseña debe ser diferente a la actual.',
                    'errors' => ['new_password' => ['La nueva contraseña no puede ser igual a la actual.']],
                ], 422);
            }

            // Update password
            $user->update([
                'password' => $request->new_password,
            ]);

            // Revoke all tokens except current one (optional security measure)
            // $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Contraseña actualizada exitosamente.',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al cambiar contraseña: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar la contraseña.',
                'errors' => ['server' => ['Ocurrió un error inesperado. Por favor, inténtelo de nuevo.']],
            ], 500);
        }
    }
}
