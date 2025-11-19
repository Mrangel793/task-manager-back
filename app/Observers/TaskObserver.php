<?php

namespace App\Observers;

use App\Models\Task;
use App\Models\TaskHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TaskObserver
{
    /**
     * Handle the Task "created" event.
     *
     * Note: TaskHistory is already being created in TaskService.
     * This observer can be used for additional side effects like
     * sending notifications or triggering webhooks.
     */
    public function created(Task $task): void
    {
        // TaskHistory is already created in TaskService::createTask()
        // This is where you can add additional logic like:
        // - Send notification to assignee
        // - Trigger webhook to n8n
        // - Log to external service

        Log::info("Task created: {$task->id} - {$task->title}");
    }

    /**
     * Handle the Task "updated" event.
     *
     * Note: TaskHistory is already being created in TaskService.
     * This observer can be used for additional side effects.
     */
    public function updated(Task $task): void
    {
        // TaskHistory is already created in TaskService methods
        // Check if status changed
        if ($task->isDirty('status')) {
            $oldStatus = $task->getOriginal('status');
            $newStatus = $task->status;

            Log::info("Task status changed: {$task->id} from '{$oldStatus}' to '{$newStatus}'");

            // Here you can:
            // - Send notification about status change
            // - Trigger webhook
            // - Update related records
        }

        // Check if assignee changed
        if ($task->isDirty('assignee_id')) {
            $oldAssigneeId = $task->getOriginal('assignee_id');
            $newAssigneeId = $task->assignee_id;

            Log::info("Task reassigned: {$task->id} from user {$oldAssigneeId} to user {$newAssigneeId}");

            // Here you can:
            // - Send notification to new assignee
            // - Notify old assignee
            // - Trigger webhook
        }
    }

    /**
     * Handle the Task "deleted" event.
     *
     * Note: TaskHistory is already being created in TaskService.
     * This observer can be used for additional side effects.
     */
    public function deleted(Task $task): void
    {
        // TaskHistory is already created in TaskService::deleteTask()
        Log::info("Task deleted: {$task->id} - {$task->title}");

        // Here you can:
        // - Send notification about deletion
        // - Trigger webhook
        // - Clean up related resources
    }

    /**
     * Handle the Task "restored" event.
     */
    public function restored(Task $task): void
    {
        try {
            // Create history record for restoration
            TaskHistory::create([
                'task_id' => $task->id,
                'user_id' => Auth::id(),
                'action' => 'restored',
                'from_value' => null,
                'to_value' => [
                    'status' => $task->status,
                    'title' => $task->title,
                ],
            ]);

            Log::info("Task restored: {$task->id} - {$task->title}");
        } catch (\Exception $e) {
            Log::error("Error creating history for restored task: {$e->getMessage()}");
        }
    }

    /**
     * Handle the Task "force deleted" event.
     */
    public function forceDeleted(Task $task): void
    {
        Log::warning("Task force deleted: {$task->id} - {$task->title}");

        // Here you can:
        // - Clean up related records
        // - Archive data
        // - Send alerts
    }
}
