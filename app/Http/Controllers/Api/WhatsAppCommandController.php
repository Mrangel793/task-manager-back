<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        // Priority 1: If Bearer token is provided, authenticate with Sanctum
        if ($request->bearerToken()) {
            $user = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken());
            if ($user && $user->tokenable) {
                return $user->tokenable;
            }
        }

        // Priority 2: If whatsapp_phone is provided, find user by that
        if ($request->has('whatsapp_phone')) {
            return User::where('whatsapp_phone', $request->whatsapp_phone)
                ->where('whatsapp_verified', true)
                ->first();
        }

        return null;
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
            . "Ejemplo: `/crear Revisar inventario | 2025-11-10 | 14:30 | Juan Pérez`\n\n"
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
                $operadorIds = User::role('Operador')->pluck('id');
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
                ->orderByRaw("FIELD(priority, 'Alta', 'Media', 'Baja')")
                ->limit(15)
                ->get(['id', 'title', 'status', 'priority', 'due_date', 'due_time', 'assignee_id']);

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
                $priorityEmoji = match ($task->priority) {
                    'Alta' => '🔴',
                    'Media' => '🟡',
                    'Baja' => '🟢',
                    default => '⚪',
                };

                $statusEmoji = $task->status === 'En Progreso' ? '▶️' : '⏸️';

                $dueInfo = $task->due_date
                    ? $task->due_date_carbon->format('d/m/Y') . ($task->due_time ? ' ' . $task->due_time : '')
                    : 'Sin fecha';

                $assigneeInfo = $showAssignee && $task->assignee
                    ? "\n   👤 " . $task->assignee->name
                    : '';

                $message .= sprintf(
                    "%d. %s %s *[%s]*\n   %s\n   📅 %s%s\n\n",
                    $index + 1,
                    $statusEmoji,
                    $priorityEmoji,
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
                        'priority' => $task->priority,
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
                $operadorIds = User::role('Operador')->pluck('id');
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

            $priorityEmoji = match ($task->priority) {
                'Alta' => '🔴',
                'Media' => '🟡',
                'Baja' => '🟢',
                default => '⚪',
            };

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
                . "*Prioridad:* {$priorityEmoji} {$task->priority}\n"
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
                        'priority' => $task->priority,
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

                // Find task assigned to user
                $task = Task::where('id', $request->task_id)
                    ->where('assignee_id', $user->id)
                    ->first();

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

                // Find task assigned to user
                $task = Task::where('id', $request->task_id)
                    ->where('assignee_id', $user->id)
                    ->first();

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
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createTask(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'due_date' => 'required|date|date_format:Y-m-d',
            'due_time' => 'required|date_format:H:i',
            'assignee_name' => 'required|string',
            'description' => 'nullable|string',
            'priority' => 'nullable|in:Alta,Media,Baja',
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

            // Find assignee by name (fuzzy match)
            $assigneeName = trim($request->assignee_name);
            $assignee = User::where('is_active', true)
                ->where(function ($query) use ($assigneeName) {
                    $query->where('name', 'LIKE', "%{$assigneeName}%")
                        ->orWhere('name', 'LIKE', "%{$assigneeName}%");
                })
                ->first();

            if (!$assignee) {
                // Try to find by exact words
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

            // Create task using TaskService
            $task = $this->taskService->createTask([
                'title' => $request->title,
                'description' => $request->description,
                'priority' => $request->priority ?? 'Media',
                'due_date' => $request->due_date,
                'due_time' => $request->due_time,
                'assignee_id' => $assignee->id,
            ], $creator);

            $priorityEmoji = match ($task->priority) {
                'Alta' => '🔴',
                'Media' => '🟡',
                'Baja' => '🟢',
                default => '⚪',
            };

            $message = "✅ *Tarea Creada Exitosamente*\n\n"
                . "*ID:* `{$task->id}`\n"
                . "*Título:* {$task->title}\n"
                . "*Prioridad:* {$priorityEmoji} {$task->priority}\n"
                . "*Asignado a:* {$assignee->name}\n"
                . "*Vencimiento:* 📅 {$task->due_date_carbon->format('d/m/Y')} a las {$task->due_time}\n\n"
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
                        'priority' => $task->priority,
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
}
