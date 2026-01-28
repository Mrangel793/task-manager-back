<?php

namespace App\Services;

use App\Events\TaskAssigned;
use App\Events\TaskCreated;
use App\Events\TaskStatusChanged;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaskService
{
    /**
     * Create a new task.
     *
     * @param array $data
     * @param User $creator
     * @return Task
     * @throws \Exception
     */
    public function createTask(array $data, User $creator): Task
    {
        try {
            DB::beginTransaction();

            // Create task
            $task = Task::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'due_date' => $data['due_date'],
                'due_time' => $data['due_time'] ?? null,
                'priority' => $data['priority'] ?? 'Media',
                'assignee_id' => $data['assignee_id'],
                'creator_id' => $creator->id,
                'status' => 'Pendiente',
            ]);

            // Create history record
            TaskHistory::create([
                'task_id' => $task->id,
                'user_id' => $creator->id,
                'action' => 'created',
                'from_value' => null,
                'to_value' => [
                    'title' => $task->title,
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'assignee_id' => $task->assignee_id,
                ],
            ]);

            // Dispatch TaskCreated event
            event(new TaskCreated($task, $creator));

            DB::commit();

            return $task->load(['assignee', 'creator']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al crear tarea: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update a task.
     *
     * @param Task $task
     * @param array $data
     * @param User $user
     * @return Task
     * @throws \Exception
     */
    public function updateTask(Task $task, array $data, User $user): Task
    {
        try {
            DB::beginTransaction();

            $changes = [];
            $oldValues = [];
            $newValues = [];

            // Track changes
            foreach (['title', 'description', 'due_date', 'due_time', 'priority'] as $field) {
                if (isset($data[$field]) && $task->{$field} != $data[$field]) {
                    $oldValues[$field] = $task->{$field};
                    $newValues[$field] = $data[$field];
                    $changes[$field] = $data[$field];
                }
            }

            // Update task
            if (!empty($changes)) {
                $task->update($changes);

                // Create history record
                TaskHistory::create([
                    'task_id' => $task->id,
                    'user_id' => $user->id,
                    'action' => 'updated',
                    'from_value' => $oldValues,
                    'to_value' => $newValues,
                ]);

                // TODO: Dispatch TaskUpdated event
                // event(new TaskUpdated($task, $oldValues));
            }

            DB::commit();

            return $task->load(['assignee', 'creator']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al actualizar tarea: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update task status.
     *
     * @param Task $task
     * @param string $newStatus
     * @param User $user
     * @return Task
     * @throws \Exception
     */
    public function updateTaskStatus(Task $task, string $newStatus, User $user): Task
    {
        try {
            DB::beginTransaction();

            // Validate status transition
            if (!$task->canTransitionTo($newStatus)) {
                throw new \Exception("No se puede cambiar el estado de '{$task->status}' a '{$newStatus}'.");
            }

            $oldStatus = $task->status;

            // Update status
            $task->update(['status' => $newStatus]);

            // Set timestamps based on status
            if ($newStatus === 'En Progreso' && !$task->started_at) {
                $task->update(['started_at' => Carbon::now()]);
            } elseif ($newStatus === 'Completada') {
                $task->update(['completed_at' => Carbon::now()]);
            }

            // Create history record
            TaskHistory::create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'action' => 'status_changed',
                'from_value' => ['status' => $oldStatus],
                'to_value' => ['status' => $newStatus],
            ]);

            // Dispatch TaskStatusChanged event
            event(new TaskStatusChanged($task, $oldStatus, $newStatus, $user));

            DB::commit();

            return $task->load(['assignee', 'creator']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al cambiar estado de tarea: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Reassign task to another user.
     *
     * @param Task $task
     * @param User $newAssignee
     * @param User $user
     * @return Task
     * @throws \Exception
     */
    public function reassignTask(Task $task, User $newAssignee, User $user): Task
    {
        try {
            DB::beginTransaction();

            // Validate new assignee is an active Operador
            if (!$newAssignee->hasRole('Operador')) {
                throw new \Exception('Solo se pueden asignar tareas a operadores.');
            }

            if (!$newAssignee->is_active) {
                throw new \Exception('El operador seleccionado no está activo.');
            }

            $oldAssigneeId = $task->assignee_id;

            // Update assignee
            $task->update(['assignee_id' => $newAssignee->id]);

            // Create history record
            TaskHistory::create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'action' => 'reassigned',
                'from_value' => ['assignee_id' => $oldAssigneeId],
                'to_value' => ['assignee_id' => $newAssignee->id],
            ]);

            // Dispatch TaskAssigned event
            event(new TaskAssigned($task, $newAssignee, $user));

            DB::commit();

            return $task->load(['assignee', 'creator']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al reasignar tarea: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete a task (soft delete).
     *
     * @param Task $task
     * @param User $user
     * @return bool
     * @throws \Exception
     */
    public function deleteTask(Task $task, User $user): bool
    {
        try {
            DB::beginTransaction();

            // Create history record before deleting
            TaskHistory::create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'action' => 'deleted',
                'from_value' => [
                    'status' => $task->status,
                    'title' => $task->title,
                ],
                'to_value' => null,
            ]);

            // Soft delete
            $task->delete();

            // TODO: Dispatch TaskDeleted event
            // event(new TaskDeleted($task));

            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al eliminar tarea: ' . $e->getMessage());
            throw $e;
        }
    }
}
