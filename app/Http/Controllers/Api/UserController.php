<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\WelcomeUserNotification;
use App\Services\N8nWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        // Middlewares are now applied in routes/api.php (Laravel 11)
    }

    /**
     * Display a listing of users.
     *
     * Admin can see all users.
     * Supervisor can see their team (all Operadores).
     * Operador can only see themselves.
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        try {
            $user = $request->user();

            // Select only needed columns for listing (faster query)
            $query = User::select([
                'id', 'name', 'email', 'phone', 'role',
                'is_active', 'whatsapp_phone', 'whatsapp_verified',
                'created_at', 'updated_at'
            ]);

            // Apply RBAC logic (don't load tasks for listing - only load when needed)
            if ($user->hasRole('Admin')) {
                // Admin can see all users
            } elseif ($user->hasRole('Supervisor')) {
                // Supervisor can see all Operadores
                $query->where('role', 'Operador');
            } else {
                // Operador can only see themselves
                $query->where('id', $user->id);
            }

            // Apply filters
            if ($request->has('role')) {
                $query->where('role', $request->role);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $users = $query->latest()->paginate($perPage);

            return UserResource::collection($users);
        } catch (\Exception $e) {
            Log::error('Error al listar usuarios: ' . $e->getMessage());

            return UserResource::collection([]);
        }
    }

    /**
     * Store a newly created user.
     *
     * Only Admin can create users (enforced by StoreUserRequest and middleware).
     *
     * @param StoreUserRequest $request
     * @return JsonResponse
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        try {
            // Generate password if not provided
            $plainPassword = $request->password ?? Str::random(10);

            // Use phone as whatsapp_phone if not provided separately
            $whatsappPhone = $request->whatsapp_phone ?? $request->phone;

            // Create user (organization_id auto-set by BelongsToOrganization trait)
            $user = User::create([
                'organization_id' => $request->user()->organization_id,
                'email' => $request->email,
                'phone' => $request->phone,
                'name' => $request->name,
                'password' => $plainPassword,
                'role' => $request->role,
                'is_active' => $request->get('is_active', true),
                'whatsapp_phone' => $whatsappPhone,
                'notification_preferences' => $request->notification_preferences
                    ? json_decode($request->notification_preferences, true)
                    : null,
            ]);

            // Assign role
            $user->assignRole($request->role);

            // Invalidate operador IDs cache when creating new users
            Cache::forget('operador_user_ids_' . $request->user()->organization_id);

            // Send welcome email with credentials (if email provided)
            if ($user->email) {
                $user->notify(new WelcomeUserNotification($plainPassword, $request->user()));
            }

            // Send activation link via WhatsApp (N8n pending notification)
            $this->sendWelcomeWhatsApp($user, $request->user());

            $channel = $user->email ? 'correo y WhatsApp' : 'WhatsApp';

            return response()->json([
                'success' => true,
                'message' => "Usuario creado exitosamente. Se han enviado las credenciales por {$channel}.",
                'data' => new UserResource($user),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error al crear usuario: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al crear el usuario.',
                'errors' => ['server' => ['Ocurrió un error inesperado. Por favor, inténtelo de nuevo.']],
            ], 500);
        }
    }

    /**
     * Display the specified user.
     *
     * Admin can see any user.
     * Supervisor can see Operadores.
     * Operador can only see themselves.
     *
     * @param User $user
     * @param Request $request
     * @return JsonResponse
     */
    public function show(User $user, Request $request): JsonResponse
    {
        try {
            $authUser = $request->user();

            // Check authorization
            if ($authUser->hasRole('Admin')) {
                // Admin can see any user
            } elseif ($authUser->hasRole('Supervisor')) {
                // Supervisor can only see Operadores
                if (!$user->hasRole('Operador')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No tienes permisos para ver este usuario.',
                    ], 403);
                }
            } else {
                // Operador can only see themselves
                if ($authUser->id !== $user->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No tienes permisos para ver este usuario.',
                    ], 403);
                }
            }

            // Load relationships
            $user->load(['tasksAssigned', 'tasksCreated']);

            return response()->json([
                'success' => true,
                'data' => new UserResource($user),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al mostrar usuario: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el usuario.',
                'errors' => ['server' => ['Ocurrió un error inesperado. Por favor, inténtelo de nuevo.']],
            ], 500);
        }
    }

    /**
     * Update the specified user.
     *
     * Admin can update any user.
     * Other users can only update themselves (enforced by UpdateUserRequest).
     *
     * @param UpdateUserRequest $request
     * @param User $user
     * @return JsonResponse
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        try {
            // Prepare update data
            $updateData = [];

            if ($request->has('email')) {
                $updateData['email'] = $request->email;
            }

            if ($request->has('name')) {
                $updateData['name'] = $request->name;
            }

            if ($request->has('phone')) {
                $updateData['phone'] = $request->phone;
            }

            if ($request->has('password')) {
                $updateData['password'] = $request->password;
            }

            if ($request->has('whatsapp_phone')) {
                $updateData['whatsapp_phone'] = $request->whatsapp_phone;
            }

            if ($request->has('whatsapp_verified')) {
                $updateData['whatsapp_verified'] = $request->whatsapp_verified;
            }

            if ($request->has('notification_preferences')) {
                $updateData['notification_preferences'] = $request->notification_preferences
                    ? json_decode($request->notification_preferences, true)
                    : null;
            }

            // Only admins can update these fields (validated in UpdateUserRequest)
            if ($request->has('role') && $request->user()->hasRole('Admin')) {
                $updateData['role'] = $request->role;
                // Update Spatie role as well
                $user->syncRoles([$request->role]);
                // Invalidate operador IDs cache when roles change
                Cache::forget('operador_user_ids_' . $request->user()->organization_id);
            }

            if ($request->has('is_active') && $request->user()->hasRole('Admin')) {
                $updateData['is_active'] = $request->is_active;
            }

            // Update user
            $user->update($updateData);

            // Reload relationships
            $user->load(['tasksAssigned', 'tasksCreated']);

            return response()->json([
                'success' => true,
                'message' => 'Usuario actualizado exitosamente.',
                'data' => new UserResource($user),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al actualizar usuario: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el usuario.',
                'errors' => ['server' => ['Ocurrió un error inesperado. Por favor, inténtelo de nuevo.']],
            ], 500);
        }
    }

    /**
     * Soft delete the specified user.
     *
     * Only Admin can delete users (enforced by middleware).
     *
     * @param User $user
     * @param Request $request
     * @return JsonResponse
     */
    public function destroy(User $user, Request $request): JsonResponse
    {
        try {
            // Prevent deleting yourself
            if ($request->user()->id === $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No puedes eliminar tu propia cuenta.',
                ], 422);
            }

            // Nullify unique fields before soft-deleting so they can be reused
            $user->forceFill([
                'email' => null,
                'phone' => null,
                'whatsapp_phone' => null,
            ])->saveQuietly();

            // Soft delete
            $user->delete();

            // Invalidate operador IDs cache
            Cache::forget('operador_user_ids_' . $request->user()->organization_id);

            return response()->json([
                'success' => true,
                'message' => 'Usuario eliminado exitosamente.',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al eliminar usuario: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el usuario.',
                'errors' => ['server' => ['Ocurrió un error inesperado. Por favor, inténtelo de nuevo.']],
            ], 500);
        }
    }

    /**
     * Crea la notificación de bienvenida por WhatsApp con un enlace de activación
     * de un solo uso. No se envían credenciales: Meta no aprueba plantillas que
     * contengan contraseñas.
     */
    private function sendWelcomeWhatsApp(User $user, User $createdBy): void
    {
        try {
            $phone = $user->whatsapp_phone ?? $user->phone;
            if (! $phone) {
                return;
            }

            $orgName = $createdBy->organization?->name ?? 'TaskManager';
            $loginUrl = config('app.frontend_url', 'https://task.nativoweb.com');

            $plainToken = Str::random(64);

            $user->forceFill([
                'activation_token' => hash('sha256', $plainToken),
                'activation_token_expires_at' => now()->addHours(48),
            ])->save();

            $activationUrl = rtrim($loginUrl, '/') . '/activar/' . $plainToken;

            $message = "Hola {$user->name}, tu cuenta en *{$orgName}* ha sido creada.\n\n"
                . "Para activarla y crear tu contraseña, ingresa aquí:\n"
                . "{$activationUrl}\n\n"
                . 'El enlace es de un solo uso y vence en 48 horas.';

            Notification::create([
                'organization_id' => $user->organization_id,
                'user_id' => $user->id,
                'task_id' => null,
                'channel' => 'whatsapp',
                'type' => 'user_welcome',
                'title' => 'Bienvenido a ' . $orgName,
                'message' => $message,
                'data' => [
                    'phone' => $phone,
                    'template' => 'user_welcome',
                    'template_params' => [
                        'user_name' => $user->name,
                        'org_name' => $orgName,
                        'activation_token' => $plainToken,
                    ],
                ],
                'status' => 'pending',
            ]);
        } catch (\Exception $e) {
            Log::error('Error al crear notificación WhatsApp de bienvenida: ' . $e->getMessage());
        }
    }
}
