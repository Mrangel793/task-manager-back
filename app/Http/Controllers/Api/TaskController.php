<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskHistoryResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TaskController extends Controller
{
    protected TaskService $taskService;

    /**
     * Constructor with dependency injection.
     */
    public function __construct(TaskService $taskService)
    {
        $this->taskService = $taskService;
    }

    /**
     * Display a listing of tasks with filters.
     *
     * Admin can see all tasks.
     * Supervisor can see their tasks + tasks assigned to Operadores.
     * Operador can only see their assigned tasks.
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        try {
            $user = $request->user();

            $query = Task::with(['assignee', 'creator']);

            // Apply RBAC logic
            if ($user->hasRole('Admin')) {
                // Admin can see all tasks
            } elseif ($user->hasRole('Supervisor')) {
                // Supervisor can see tasks assigned to them or created by them or assigned to Operadores
                // Cache Operador IDs for 5 minutes to avoid repeated queries (scoped by organization)
                $orgId = $user->organization_id;
                $operadorIds = Cache::remember("operador_user_ids_{$orgId}", 300, function () {
                    return User::role('Operador')->pluck('id')->toArray();
                });

                $query->where(function ($q) use ($user, $operadorIds) {
                    $q->where('assignee_id', $user->id)
                        ->orWhere('creator_id', $user->id)
                        ->orWhereIn('assignee_id', $operadorIds);
                });
            } else {
                // Operador can only see tasks assigned to them
                $query->where('assignee_id', $user->id);
            }

            // Apply filters
            if ($request->has('assignee_id')) {
                $query->where('assignee_id', $request->assignee_id);
            }

            if ($request->has('creator_id')) {
                $query->where('creator_id', $request->creator_id);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('due_date_from')) {
                $query->where('due_date', '>=', $request->due_date_from);
            }

            if ($request->has('due_date_to')) {
                $query->where('due_date', '<=', $request->due_date_to);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('id', 'like', "%{$search}%");
                });
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $tasks = $query->paginate($perPage);

            return TaskResource::collection($tasks);
        } catch (\Exception $e) {
            Log::error('Error al listar tareas: ' . $e->getMessage());

            return TaskResource::collection([]);
        }
    }

    /**
     * Store a newly created task.
     *
     * @param StoreTaskRequest $request
     * @return JsonResponse
     */
    public function store(StoreTaskRequest $request): JsonResponse
    {
        try {
            $task = $this->taskService->createTask(
                $request->validated(),
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'Tarea creada exitosamente.',
                'data' => new TaskResource($task),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error al crear tarea: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al crear la tarea.',
                'errors' => ['server' => [$e->getMessage()]],
            ], 500);
        }
    }

    /**
     * Display the specified task with history.
     *
     * @param Task $task
     * @param Request $request
     * @return JsonResponse
     */
    public function show(Task $task, Request $request): JsonResponse
    {
        try {
            // Load relationships
            $task->load(['assignee', 'creator']);

            // Load history if requested
            if ($request->boolean('include_history')) {
                $task->load(['histories.user']);
            }

            return response()->json([
                'success' => true,
                'data' => new TaskResource($task),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al mostrar tarea: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la tarea.',
                'errors' => ['server' => [$e->getMessage()]],
            ], 500);
        }
    }

    /**
     * Update the specified task.
     *
     * @param UpdateTaskRequest $request
     * @param Task $task
     * @return JsonResponse
     */
    public function update(UpdateTaskRequest $request, Task $task): JsonResponse
    {
        try {
            $data = $request->validated();

            // Handle status change separately if provided
            if (isset($data['status'])) {
                $task = $this->taskService->updateTaskStatus(
                    $task,
                    $data['status'],
                    $request->user()
                );
                unset($data['status']);
            }

            // Handle reassignment separately if provided
            if (isset($data['assignee_id']) && $data['assignee_id'] != $task->assignee_id) {
                $newAssignee = User::findOrFail($data['assignee_id']);
                $task = $this->taskService->reassignTask(
                    $task,
                    $newAssignee,
                    $request->user()
                );
                unset($data['assignee_id']);
            }

            // Update other fields
            if (!empty($data)) {
                $task = $this->taskService->updateTask(
                    $task,
                    $data,
                    $request->user()
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Tarea actualizada exitosamente.',
                'data' => new TaskResource($task),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al actualizar tarea: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la tarea.',
                'errors' => ['server' => [$e->getMessage()]],
            ], 500);
        }
    }

    /**
     * Soft delete the specified task.
     *
     * @param Task $task
     * @param Request $request
     * @return JsonResponse
     */
    public function destroy(Task $task, Request $request): JsonResponse
    {
        try {
            $this->taskService->deleteTask($task, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Tarea eliminada exitosamente.',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al eliminar tarea: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la tarea.',
                'errors' => ['server' => [$e->getMessage()]],
            ], 500);
        }
    }

    /**
     * Reassign task to another operator.
     *
     * @param Request $request
     * @param Task $task
     * @return JsonResponse
     */
    public function reassign(Request $request, Task $task): JsonResponse
    {
        try {
            $request->validate([
                'assignee_id' => [
                    'required',
                    'uuid',
                    'exists:users,id',
                ],
            ], [
                'assignee_id.required' => 'Debe especificar el nuevo operador.',
                'assignee_id.uuid' => 'El ID del operador no es válido.',
                'assignee_id.exists' => 'El operador seleccionado no existe.',
            ]);

            $newAssignee = User::findOrFail($request->assignee_id);

            $task = $this->taskService->reassignTask(
                $task,
                $newAssignee,
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'Tarea reasignada exitosamente.',
                'data' => new TaskResource($task),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al reasignar tarea: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al reasignar la tarea.',
                'errors' => ['server' => [$e->getMessage()]],
            ], 500);
        }
    }

    /**
     * Get the history of changes for a specific task.
     *
     * @param Task $task
     * @param Request $request
     * @return JsonResponse
     */
    public function getHistory(Task $task, Request $request): JsonResponse
    {
        try {
            // Get history records with user relationship, ordered by most recent first
            $histories = $task->history()
                ->with('user')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => TaskHistoryResource::collection($histories),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al obtener historial de tarea: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el historial de la tarea.',
                'errors' => ['server' => [$e->getMessage()]],
            ], 500);
        }
    }
}
