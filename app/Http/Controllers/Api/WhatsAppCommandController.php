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
        $validator = Validator::make($request->all(), [
            'whatsapp_phone' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación: ' . $validator->errors()->first(),
                'data' => null,
            ], 400);
        }

        try {
            // Find user by WhatsApp phone
            $user = User::where('whatsapp_phone', $request->whatsapp_phone)
                ->where('whatsapp_verified', true)
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado o WhatsApp no verificado.',
                    'data' => null,
                ], 404);
            }

            // Get user's pending and in-progress tasks (max 10)
            $tasks = Task::where('assignee_id', $user->id)
                ->whereIn('status', ['Pendiente', 'En Progreso'])
                ->orderBy('due_date', 'asc')
                ->orderBy('due_time', 'asc')
                ->orderBy('priority', 'desc')
                ->limit(10)
                ->get(['id', 'title', 'status', 'priority', 'due_date', 'due_time']);

            if ($tasks->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => '✅ No tienes tareas pendientes en este momento.',
                    'data' => ['tasks' => []],
                ]);
            }

            $message = "📋 *Tus Tareas:*\n\n";
            foreach ($tasks as $index => $task) {
                $priorityEmoji = match ($task->priority) {
                    'Alta' => '🔴',
                    'Media' => '🟡',
                    'Baja' => '🟢',
                    default => '⚪',
                };

                $statusEmoji = $task->status === 'En Progreso' ? '▶️' : '⏸️';

                $dueInfo = $task->due_date
                    ? $task->due_date->format('d/m/Y') . ($task->due_time ? ' ' . $task->due_time : '')
                    : 'Sin fecha';

                $message .= sprintf(
                    "%d. %s %s *[%s]*\n   %s\n   📅 %s\n\n",
                    $index + 1,
                    $statusEmoji,
                    $priorityEmoji,
                    $task->id,
                    $task->title,
                    $dueInfo
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
                        'due_date' => $task->due_date?->format('Y-m-d'),
                        'due_time' => $task->due_time,
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
     * Get task details.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getTask(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'whatsapp_phone' => 'required|string',
            'task_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación: ' . $validator->errors()->first(),
                'data' => null,
            ], 400);
        }

        try {
            // Find user by WhatsApp phone
            $user = User::where('whatsapp_phone', $request->whatsapp_phone)
                ->where('whatsapp_verified', true)
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado o WhatsApp no verificado.',
                    'data' => null,
                ], 404);
            }

            // Find task assigned to user
            $task = Task::with(['assignee', 'creator'])
                ->where('id', $request->task_id)
                ->where('assignee_id', $user->id)
                ->first();

            if (!$task) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tarea no encontrada o no tienes acceso a ella.',
                    'data' => null,
                ], 404);
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
                ? $task->due_date->format('d/m/Y') . ($task->due_time ? ' a las ' . $task->due_time : '')
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
                        'due_date' => $task->due_date?->format('Y-m-d'),
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
            'whatsapp_phone' => 'required|string',
            'task_id' => 'required|string',
            'message_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación: ' . $validator->errors()->first(),
                'data' => null,
            ], 400);
        }

        try {
            return DB::transaction(function () use ($request) {
                // Check for idempotency
                $existingMessage = WhatsAppMessage::where('message_id', $request->message_id)->first();
                if ($existingMessage) {
                    Log::info("Duplicate message detected: {$request->message_id}");
                    return response()->json([
                        'success' => true,
                        'message' => '✅ Esta acción ya fue procesada anteriormente.',
                        'data' => ['already_processed' => true],
                    ]);
                }

                // Find user by WhatsApp phone
                $user = User::where('whatsapp_phone', $request->whatsapp_phone)
                    ->where('whatsapp_verified', true)
                    ->first();

                if (!$user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Usuario no encontrado o WhatsApp no verificado.',
                        'data' => null,
                    ], 404);
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

                // Save to whatsapp_messages for idempotency
                WhatsAppMessage::create([
                    'message_id' => $request->message_id,
                    'user_id' => $user->id,
                    'command' => 'start_task',
                    'task_id' => $task->id,
                    'processed_at' => now(),
                ]);

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
            'whatsapp_phone' => 'required|string',
            'task_id' => 'required|string',
            'message_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación: ' . $validator->errors()->first(),
                'data' => null,
            ], 400);
        }

        try {
            return DB::transaction(function () use ($request) {
                // Check for idempotency
                $existingMessage = WhatsAppMessage::where('message_id', $request->message_id)->first();
                if ($existingMessage) {
                    Log::info("Duplicate message detected: {$request->message_id}");
                    return response()->json([
                        'success' => true,
                        'message' => '✅ Esta acción ya fue procesada anteriormente.',
                        'data' => ['already_processed' => true],
                    ]);
                }

                // Find user by WhatsApp phone
                $user = User::where('whatsapp_phone', $request->whatsapp_phone)
                    ->where('whatsapp_verified', true)
                    ->first();

                if (!$user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Usuario no encontrado o WhatsApp no verificado.',
                        'data' => null,
                    ], 404);
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

                // Save to whatsapp_messages for idempotency
                WhatsAppMessage::create([
                    'message_id' => $request->message_id,
                    'user_id' => $user->id,
                    'command' => 'complete_task',
                    'task_id' => $task->id,
                    'processed_at' => now(),
                ]);

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
            'whatsapp_phone' => 'required|string',
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
            // Find creator by WhatsApp phone
            $creator = User::where('whatsapp_phone', $request->whatsapp_phone)
                ->where('whatsapp_verified', true)
                ->first();

            if (!$creator) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado o WhatsApp no verificado.',
                    'data' => null,
                ], 404);
            }

            // Check if user is Supervisor or Admin
            if (!$creator->hasAnyRole(['Supervisor', 'Admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => '❌ No tienes permisos para crear tareas. Solo Supervisores y Administradores pueden hacerlo.',
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
                . "*Vencimiento:* 📅 {$task->due_date->format('d/m/Y')} a las {$task->due_time}\n\n"
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
                        'due_date' => $task->due_date->format('Y-m-d'),
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
