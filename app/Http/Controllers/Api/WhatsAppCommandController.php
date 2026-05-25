<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Models\Notification;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class WhatsAppCommandController extends Controller
{
    /**
     * The task service instance.
     *
     * @var TaskService
     */
    protected TaskService $taskService;

    /**
     * Create a new controller instance.
     *
     * @param TaskService $taskService
     */
    public function __construct(TaskService $taskService)
    {
        $this->taskService = $taskService;
    }

    /**
     * Resolve user from request - supports both Bearer token and whatsapp_phone.
     *
     * @param Request $request
     * @return User|null
     */
    protected function resolveUser(Request $request): ?User
    {
        $user = null;

        // Priority 1: If Bearer token is provided, authenticate with Sanctum
        if ($request->bearerToken()) {
            $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken());
            if ($token && $token->tokenable) {
                $user = $token->tokenable;
            }
        }

        // Priority 2: If whatsapp_phone is provided, find user by that
        if (!$user && $request->has('whatsapp_phone')) {
            $user = User::withoutGlobalScopes()
                ->where('whatsapp_phone', $request->whatsapp_phone)
                ->where('whatsapp_verified', true)
                ->first();
        }

        // Set organization context if user found
        if ($user && $user->organization_id) {
            app()->instance('current_organization_id', $user->organization_id);
        }

        return $user;
    }

    /**
     * Get help information about available commands.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function help(Request $request): JsonResponse
    {
        $helpText = "🤖 *Comandos Disponibles:*\n\n"
            . "📋 *Ver mis tareas:*\n"
            . "`/tareas` - Lista tus tareas pendientes\n\n"
            . "🔍 *Ver detalle de tarea:*\n"
            . "`/tarea [ID]` - Muestra el detalle de una tarea\n\n"
            . "▶️ *Iniciar tarea:*\n"
            . "`/iniciar [ID]` - Cambia el estado a 'En Progreso'\n\n"
            . "✅ *Completar tarea:*\n"
            . "`/completar [ID]` - Marca la tarea como 'Completada'\n\n"
            . "➕ *Crear tarea:* (Solo Supervisores/Admins)\n"
            . "`/crear [título] | [fecha] | [hora] | [asignado]`\n"
            . "Ejemplo: `/crear Revisar inventario | 2025-11-10 | 14:30 | Juan Pérez`\n"
            . "_Admin: puede crear con solo el título_\n\n"
            . "❓ *Ayuda:*\n"
            . "`/ayuda` - Muestra este mensaje";

        return response()->json([
            'success' => true,
            'message' => $helpText,
            'data' => [
                'commands' => [
                    ['command' => '/tareas', 'description' => 'Lista tus tareas pendientes y en progreso'],
                    ['command' => '/tarea [ID]', 'description' => 'Muestra el detalle de una tarea específica'],
                    ['command' => '/iniciar [ID]', 'description' => 'Inicia una tarea pendiente'],
                    ['command' => '/completar [ID]', 'description' => 'Completa una tarea en progreso'],
                    ['command' => '/crear [datos]', 'description' => 'Crea una nueva tarea (solo supervisores/admins)'],
                    ['command' => '/ayuda', 'description' => 'Muestra esta ayuda'],
                ],
            ],
        ]);
    }

    /**
     * List user's pending and in-progress tasks.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function listTasks(Request $request): JsonResponse
    {
        try {
            // Resolve user from token or whatsapp_phone
            $user = $this->resolveUser($request);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado o WhatsApp no verificado.',
                    'data' => null,
                ], 404);
            }

            // Build query with RBAC logic
            $query = Task::with('assignee:id,name')
                ->whereIn('status', ['Pendiente', 'En Progreso']);

            // Apply RBAC logic based on user role
            if ($user->hasRole('Admin')) {
                // Admin can see all tasks
            } elseif ($user->hasRole('Supervisor')) {
                // Supervisor can see their tasks + tasks assigned to Operadores
                $operadorIds = Cache::remember("operador_user_ids_{$user->organization_id}", 300, fn() => User::role('Operador')->pluck('id')->toArray());
                $query->where(function ($q) use ($user, $operadorIds) {
                    $q->where('assignee_id', $user->id)
                      ->orWhereIn('assignee_id', $operadorIds);
                });
            } else {
                // Operador can only see their assigned tasks
                $query->where('assignee_id', $user->id);
            }

            // Get tasks (max 15)
            $tasks = $query->orderBy('due_date', 'asc')
                ->orderBy('due_time', 'asc')
                ->limit(15)
                ->get(['id', 'title', 'status', 'due_date', 'due_time', 'assignee_id']);

            if ($tasks->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => '✅ No hay tareas pendientes en este momento.',
                    'data' => ['tasks' => []],
                ]);
            }

            // Build message based on role
            $roleLabel = $user->hasRole('Admin') ? 'Todas las Tareas' :
                        ($user->hasRole('Supervisor') ? 'Tareas del Equipo' : 'Tus Tareas');
            $message = "📋 *{$roleLabel}:*\n\n";
            $showAssignee = $user->hasRole('Admin') || $user->hasRole('Supervisor');

            foreach ($tasks as $index => $task) {
                $statusEmoji = $task->status === 'En Progreso' ? '▶️' : '⏸️';

                $dueInfo = $task->due_date
                    ? $task->due_date_carbon->format('d/m/Y') . ($task->due_time ? ' ' . $task->due_time : '')
                    : 'Sin fecha';

                $assigneeInfo = $showAssignee && $task->assignee
                    ? "\n   👤 " . $task->assignee->name
                    : '';

                $message .= sprintf(
                    "%d. %s *[%s]*\n   %s\n   📅 %s%s\n\n",
                    $index + 1,
                    $statusEmoji,
                    $task->id,
                    $task->title,
                    $dueInfo,
                    $assigneeInfo
                );
            }

            $message .= "_Usa `/tarea [ID]` para ver detalles._";

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'tasks' => $tasks->map(fn($task) => [
                        'id' => $task->id,
                        'title' => $task->title,
                        'status' => $task->status,
                        'due_date' => $task->due_date,
                        'due_time' => $task->due_time,
                        'assignee' => $task->assignee ? [
                            'id' => $task->assignee->id,
                            'name' => $task->assignee->name,
                        ] : null,
                    ]),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error in listTasks: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las tareas. Intenta nuevamente.',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Get task details by ID or search by name.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getTask(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'task_id' => 'nullable|string',
            'search' => 'nullable|string|min:3',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación: ' . $validator->errors()->first(),
                'data' => null,
            ], 400);
        }

        // Require at least one parameter
        if (!$request->task_id && !$request->search) {
            return response()->json([
                'success' => false,
                'message' => 'Debes proporcionar task_id o search (mínimo 3 caracteres).',
                'data' => null,
            ], 400);
        }

        try {
            // Resolve user from token or whatsapp_phone
            $user = $this->resolveUser($request);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado. Proporcione token o whatsapp_phone válido.',
                    'data' => null,
                ], 404);
            }

            // Build base query with RBAC
            $query = Task::with(['assignee', 'creator']);

            // Apply RBAC logic based on user role
            if ($user->hasRole('Admin')) {
                // Admin can see all tasks
            } elseif ($user->hasRole('Supervisor')) {
                // Supervisor can see their tasks + tasks assigned to Operadores
                $operadorIds = Cache::remember("operador_user_ids_{$user->organization_id}", 300, fn() => User::role('Operador')->pluck('id')->toArray());
                $query->where(function ($q) use ($user, $operadorIds) {
                    $q->where('assignee_id', $user->id)
                      ->orWhereIn('assignee_id', $operadorIds);
                });
            } else {
                // Operador can only see their assigned tasks
                $query->where('assignee_id', $user->id);
            }

            // Search by ID or by name
            if ($request->task_id) {
                // Direct search by ID
                $task = $query->where('id', $request->task_id)->first();

                if (!$task) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tarea no encontrada o no tienes acceso a ella.',
                        'data' => null,
                    ], 404);
                }
            } else {
                // Search by name/title
                $searchTerm = $request->search;
                $tasks = $query->where('title', 'LIKE', "%{$searchTerm}%")
                    ->orderBy('due_date', 'asc')
                    ->limit(10)
                    ->get();

                if ($tasks->isEmpty()) {
                    return response()->json([
                        'success' => false,
                        'message' => "🔍 No se encontraron tareas con '{$searchTerm}'.",
                        'data' => null,
                    ], 404);
                }

                // If multiple tasks found, return list for user to choose
                if ($tasks->count() > 1) {
                    $message = "🔍 *Se encontraron {$tasks->count()} tareas:*\n\n";

                    foreach ($tasks as $index => $t) {
                        $statusEmoji = match ($t->status) {
                            'Pendiente' => '⏸️',
                            'En Progreso' => '▶️',
                            'Completada' => '✅',
                            'Cancelada' => '❌',
                            default => '⚪',
                        };

                        $message .= sprintf(
                            "%d. %s *[%s]*\n   %s\n   👤 %s\n\n",
                            $index + 1,
                            $statusEmoji,
                            $t->id,
                            $t->title,
                            $t->assignee->name ?? 'Sin asignar'
                        );
                    }

                    $message .= "_Usa el ID completo para ver detalles._";

                    return response()->json([
                        'success' => true,
                        'message' => $message,
                        'data' => [
                            'multiple_results' => true,
                            'count' => $tasks->count(),
                            'tasks' => $tasks->map(fn($t) => [
                                'id' => $t->id,
                                'title' => $t->title,
                                'status' => $t->status,
                                'assignee' => $t->assignee ? $t->assignee->name : null,
                            ]),
                        ],
                    ]);
                }

                // Only one task found
                $task = $tasks->first();
            }

            $statusEmoji = match ($task->status) {
                'Pendiente' => '⏸️',
                'En Progreso' => '▶️',
                'Completada' => '✅',
                'Cancelada' => '❌',
                default => '⚪',
            };

            $dueInfo = $task->due_date
                ? $task->due_date_carbon->format('d/m/Y') . ($task->due_time ? ' a las ' . $task->due_time : '')
                : 'Sin fecha límite';

            $message = "📋 *Detalle de Tarea*\n\n"
                . "*ID:* `{$task->id}`\n"
                . "*Título:* {$task->title}\n"
                . "*Estado:* {$statusEmoji} {$task->status}\n"
                . "*Vencimiento:* 📅 {$dueInfo}\n"
                . "*Creado por:* {$task->creator->name}\n";

            if ($task->description) {
                $message .= "*Descripción:*\n{$task->description}\n";
            }

            // Add action suggestions based on status
            if ($task->status === 'Pendiente') {
                $message .= "\n_Usa `/iniciar {$task->id}` para comenzar._";
            } elseif ($task->status === 'En Progreso') {
                $message .= "\n_Usa `/completar {$task->id}` para marcarla como completada._";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'task' => [
                        'id' => $task->id,
                        'title' => $task->title,
                        'description' => $task->description,
                        'status' => $task->status,
                        'due_date' => $task->due_date,
                        'due_time' => $task->due_time,
                        'assignee' => [
                            'id' => $task->assignee->id,
                            'name' => $task->assignee->name,
                        ],
                        'creator' => [
                            'id' => $task->creator->id,
                            'name' => $task->creator->name,
                        ],
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getTask: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la tarea. Intenta nuevamente.',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Start a pending task (change status to "En Progreso").
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function startTask(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'task_id' => 'required|string',
            'message_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación: ' . $validator->errors()->first(),
                'data' => null,
            ], 400);
        }

        try {
            // Resolve user from token or whatsapp_phone
            $user = $this->resolveUser($request);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado. Proporcione token o whatsapp_phone válido.',
                    'data' => null,
                ], 404);
            }

            return DB::transaction(function () use ($request, $user) {
                // Check for idempotency (only if message_id is provided)
                if ($request->message_id) {
                    $existingMessage = WhatsAppMessage::where('message_id', $request->message_id)->first();
                    if ($existingMessage) {
                        Log::info("Duplicate message detected: {$request->message_id}");
                        return response()->json([
                            'success' => true,
                            'message' => '✅ Esta acción ya fue procesada anteriormente.',
                            'data' => ['already_processed' => true],
                        ]);
                    }
                }

                // Find task with RBAC logic
                $query = Task::where('id', $request->task_id);

                // Apply RBAC: Admin sees all, Supervisor sees team, Operador sees own
                if ($user->hasRole('Admin')) {
                    // Admin can access all tasks
                } elseif ($user->hasRole('Supervisor')) {
                    // Supervisor can access their tasks + Operadores' tasks
                    $operadorIds = Cache::remember("operador_user_ids_{$user->organization_id}", 300, fn() => User::role('Operador')->pluck('id')->toArray());
                    $query->where(function ($q) use ($user, $operadorIds) {
                        $q->where('assignee_id', $user->id)
                          ->orWhereIn('assignee_id', $operadorIds);
                    });
                } else {
                    // Operador can only access their assigned tasks
                    $query->where('assignee_id', $user->id);
                }

                $task = $query->first();

                if (!$task) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tarea no encontrada o no tienes acceso a ella.',
                        'data' => null,
                    ], 404);
                }

                // Validate task status
                if ($task->status !== 'Pendiente') {
                    return response()->json([
                        'success' => false,
                        'message' => "❌ La tarea está en estado '{$task->status}'. Solo puedes iniciar tareas pendientes.",
                        'data' => ['current_status' => $task->status],
                    ], 400);
                }

                // Update task status using TaskService
                $this->taskService->updateTaskStatus($task, 'En Progreso', $user);

                // Save to whatsapp_messages for idempotency (only if message_id is provided)
                if ($request->message_id) {
                    WhatsAppMessage::create([
                        'message_id' => $request->message_id,
                        'user_id' => $user->id,
                        'command' => 'start_task',
                        'task_id' => $task->id,
                        'processed_at' => now(),
                    ]);
                }

                $message = "✅ *Tarea Iniciada*\n\n"
                    . "*ID:* `{$task->id}`\n"
                    . "*Título:* {$task->title}\n"
                    . "*Estado:* ▶️ En Progreso\n\n"
                    . "_¡Mucho éxito! Usa `/completar {$task->id}` cuando termines._";

                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'data' => [
                        'task' => [
                            'id' => $task->id,
                            'title' => $task->title,
                            'status' => 'En Progreso',
                        ],
                    ],
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Error in startTask: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al iniciar la tarea. Intenta nuevamente.',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Complete a task in progress (change status to "Completada").
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function completeTask(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'task_id' => 'required|string',
            'message_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación: ' . $validator->errors()->first(),
                'data' => null,
            ], 400);
        }

        try {
            // Resolve user from token or whatsapp_phone
            $user = $this->resolveUser($request);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado. Proporcione token o whatsapp_phone válido.',
                    'data' => null,
                ], 404);
            }

            return DB::transaction(function () use ($request, $user) {
                // Check for idempotency (only if message_id is provided)
                if ($request->message_id) {
                    $existingMessage = WhatsAppMessage::where('message_id', $request->message_id)->first();
                    if ($existingMessage) {
                        Log::info("Duplicate message detected: {$request->message_id}");
                        return response()->json([
                            'success' => true,
                            'message' => '✅ Esta acción ya fue procesada anteriormente.',
                            'data' => ['already_processed' => true],
                        ]);
                    }
                }

                // Find task with RBAC logic
                $query = Task::where('id', $request->task_id);

                // Apply RBAC: Admin sees all, Supervisor sees team, Operador sees own
                if ($user->hasRole('Admin')) {
                    // Admin can access all tasks
                } elseif ($user->hasRole('Supervisor')) {
                    // Supervisor can access their tasks + Operadores' tasks
                    $operadorIds = Cache::remember("operador_user_ids_{$user->organization_id}", 300, fn() => User::role('Operador')->pluck('id')->toArray());
                    $query->where(function ($q) use ($user, $operadorIds) {
                        $q->where('assignee_id', $user->id)
                          ->orWhereIn('assignee_id', $operadorIds);
                    });
                } else {
                    // Operador can only access their assigned tasks
                    $query->where('assignee_id', $user->id);
                }

                $task = $query->first();

                if (!$task) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tarea no encontrada o no tienes acceso a ella.',
                        'data' => null,
                    ], 404);
                }

                // Validate task status
                if ($task->status !== 'En Progreso') {
                    return response()->json([
                        'success' => false,
                        'message' => "❌ La tarea está en estado '{$task->status}'. Solo puedes completar tareas en progreso.",
                        'data' => ['current_status' => $task->status],
                    ], 400);
                }

                // Update task status using TaskService
                $this->taskService->updateTaskStatus($task, 'Completada', $user);

                // Save to whatsapp_messages for idempotency (only if message_id is provided)
                if ($request->message_id) {
                    WhatsAppMessage::create([
                        'message_id' => $request->message_id,
                        'user_id' => $user->id,
                        'command' => 'complete_task',
                        'task_id' => $task->id,
                        'processed_at' => now(),
                    ]);
                }

                $message = "🎉 *¡Tarea Completada!*\n\n"
                    . "*ID:* `{$task->id}`\n"
                    . "*Título:* {$task->title}\n"
                    . "*Estado:* ✅ Completada\n\n"
                    . "_¡Excelente trabajo!_";

                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'data' => [
                        'task' => [
                            'id' => $task->id,
                            'title' => $task->title,
                            'status' => 'Completada',
                        ],
                    ],
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Error in completeTask: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al completar la tarea. Intenta nuevamente.',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Create a new task (Supervisor/Admin only).
     * Admin users can create tasks with just a title.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createTask(Request $request): JsonResponse
    {
        try {
            // Resolve user from token or whatsapp_phone first to determine role
            $creator = $this->resolveUser($request);

            if (!$creator) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado. Proporcione token o whatsapp_phone válido.',
                    'data' => null,
                ], 404);
            }

            // Check if user has permission to create tasks
            if (!$creator->hasPermissionTo('create-tasks')) {
                return response()->json([
                    'success' => false,
                    'message' => '❌ No tienes permisos para crear tareas.',
                    'data' => null,
                ], 403);
            }

            // Validation rules - due_date y assignee_name son opcionales para todos
            $rules = [
                'title'         => 'required|string|max:255',
                'due_date'      => 'nullable|date|date_format:Y-m-d',
                'due_time'      => 'nullable|date_format:H:i',
                'assignee_name' => 'nullable|string',
                'description'   => 'nullable|string',
            ];

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación: ' . $validator->errors()->first(),
                    'data' => null,
                ], 400);
            }

            // Determine assignee: buscar por nombre o asignar a sí mismo
            $assignee = null;

            if ($request->assignee_name) {
                $assigneeName = trim($request->assignee_name);
                $assignee = User::where('is_active', true)
                    ->where('name', 'LIKE', "%{$assigneeName}%")
                    ->first();

                if (!$assignee) {
                    $nameParts = explode(' ', $assigneeName);
                    foreach ($nameParts as $part) {
                        if (strlen($part) >= 3) {
                            $assignee = User::where('is_active', true)
                                ->where('name', 'LIKE', "%{$part}%")
                                ->first();
                            if ($assignee) break;
                        }
                    }
                }

                if (!$assignee) {
                    return response()->json([
                        'success' => false,
                        'message' => "❌ No se encontró un usuario con el nombre '{$assigneeName}'. Verifica el nombre e intenta nuevamente.",
                        'data' => null,
                    ], 404);
                }
            } else {
                // Sin assignee_name: asignar a quien crea la tarea
                $assignee = $creator;
            }

            // Determine due_date: usar la proporcionada o por defecto hoy
            $dueDate = $request->due_date ?? now()->format('Y-m-d');

            // Create task using TaskService
            $taskData = [
                'title' => $request->title,
                'description' => $request->description,
                'due_date' => $dueDate,
                'assignee_id' => $assignee->id,
            ];

            // Only include due_time if provided
            if ($request->due_time) {
                $taskData['due_time'] = $request->due_time;
            }

            $task = $this->taskService->createTask($taskData, $creator);

            // Format due date/time message
            $vencimiento = $task->due_date_carbon->format('d/m/Y');
            if ($task->due_time) {
                $vencimiento .= " a las {$task->due_time}";
            }

            $message = "✅ *Tarea Creada Exitosamente*\n\n"
                . "*ID:* `{$task->id}`\n"
                . "*Título:* {$task->title}\n"
                . "*Asignado a:* {$assignee->name}\n"
                . "*Vencimiento:* 📅 {$vencimiento}\n\n"
                . "_El usuario asignado recibirá una notificación._";

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'task' => [
                        'id' => $task->id,
                        'title' => $task->title,
                        'description' => $task->description,
                        'status' => $task->status,
                        'due_date' => $task->due_date_carbon->format('Y-m-d'),
                        'due_time' => $task->due_time,
                        'assignee' => [
                            'id' => $assignee->id,
                            'name' => $assignee->name,
                        ],
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error in createTask: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al crear la tarea. Intenta nuevamente.',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Create a new contact from WhatsApp/N8n.
     * Only Admin users can create contacts.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createContact(Request $request): JsonResponse
    {
        try {
            $user = $this->resolveUser($request);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado. Proporcione token o whatsapp_phone válido.',
                    'data' => null,
                ], 404);
            }

            if (!$user->hasRole('Admin') && !$user->hasPermissionTo('manage-contacts')) {
                return response()->json([
                    'success' => false,
                    'message' => '❌ No tienes permisos para crear contactos.',
                    'data' => null,
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'name'   => 'required|string|max:255',
                'phone'  => 'nullable|string|max:50',
                'email'  => 'nullable|email|max:255',
                'source' => 'nullable|string|max:255',
                'notes'  => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación: ' . $validator->errors()->first(),
                    'data' => null,
                ], 400);
            }

            $contact = Contact::create([
                'name'            => $request->name,
                'phone'           => $request->phone,
                'email'           => $request->email,
                'source'          => $request->source,
                'notes'           => $request->notes,
                'organization_id' => $user->organization_id,
                'created_by'      => $user->id,
            ]);

            $this->notifyContactCreatedToOrgUsers($contact, $user->name, $user->organization_id);

            $sourceInfo = $contact->source ? " de *{$contact->source}*" : '';
            $message = "✅ *Contacto Creado*\n\n"
                . "*Nombre:* {$contact->name}{$sourceInfo}\n"
                . ($contact->phone ? "*Teléfono:* {$contact->phone}\n" : '')
                . ($contact->email ? "*Email:* {$contact->email}\n" : '')
                . "*ID:* `{$contact->id}`";

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'contact' => [
                        'id'     => $contact->id,
                        'name'   => $contact->name,
                        'phone'  => $contact->phone,
                        'email'  => $contact->email,
                        'source' => $contact->source,
                    ],
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error in createContact: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al crear el contacto. Intenta nuevamente.',
                'data' => null,
            ], 500);
        }
    }

    private function notifyContactCreatedToOrgUsers(Contact $contact, string $createdByName, string $orgId): void
    {
        try {
            $users = User::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->whereNotNull('whatsapp_phone')
                ->permission('view-contacts')
                ->get();

            foreach ($users as $user) {
                Notification::create([
                    'organization_id' => $orgId,
                    'user_id'         => $user->id,
                    'channel'         => 'whatsapp',
                    'type'            => 'contact_created',
                    'title'           => 'Nuevo contacto registrado',
                    'message'         => "Se registró el contacto {$contact->name}",
                    'status'          => 'pending',
                    'data'            => [
                        'phone'    => $user->whatsapp_phone,
                        'template' => 'contacto_creado',
                        'template_params' => [
                            'user_name'      => $user->name,
                            'contact_name'   => $contact->name,
                            'contact_phone'  => $contact->phone ?? 'N/A',
                            'contact_source' => $contact->source ?? 'N/A',
                        ],
                        'contact_id' => $contact->id,
                    ],
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error creating contact notifications (N8n): ' . $e->getMessage());
        }
    }
}
