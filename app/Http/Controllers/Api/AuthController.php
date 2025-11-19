<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Models\WhatsAppSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            // Create user
            $user = User::create([
                'email' => $request->email,
                'phone' => $request->phone ?? null,
                'name' => $request->name,
                'password' => $request->password,
                'role' => 'Operador',
                'is_active' => true, // Email-based auth doesn't require verification
            ]);

            // Assign default role
            $user->assignRole('Operador');

            // Generate Sanctum token
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Usuario registrado exitosamente.',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'email' => $user->email,
                        'name' => $user->name,
                        'role' => $user->role,
                        'is_active' => $user->is_active,
                    ],
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error en registro de usuario: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al registrar el usuario.',
                'errors' => ['server' => ['Ocurrió un error inesperado. Por favor, inténtelo de nuevo.']],
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
            // Find user by phone
            $user = User::where('phone', $request->phone)->first();

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
            // Find user by email
            $user = User::where('email', $request->email)->first();

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

            return response()->json([
                'success' => true,
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
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at,
                    ],
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
}
