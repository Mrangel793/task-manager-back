<?php

namespace App\Listeners;

use App\Events\TaskAssigned;
use App\Events\TaskCreated;
use App\Events\TaskStatusChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class LogTaskHistory implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * Log task changes to Laravel log for audit trail.
     * Note: TaskHistory records are already being created in TaskService.
     * This listener provides additional logging for debugging and auditing.
     *
     * @param object $event
     * @return void
     */
    public function handle(object $event): void
    {
        try {
            if ($event instanceof TaskCreated) {
                $this->logTaskCreated($event);
            } elseif ($event instanceof TaskStatusChanged) {
                $this->logTaskStatusChanged($event);
            } elseif ($event instanceof TaskAssigned) {
                $this->logTaskAssigned($event);
            }
        } catch (\Exception $e) {
            Log::error('Error in LogTaskHistory listener: ' . $e->getMessage());
        }
    }

    /**
     * Log task created event.
     *
     * @param TaskCreated $event
     * @return void
     */
    private function logTaskCreated(TaskCreated $event): void
    {
        Log::channel('daily')->info('TASK CREATED', [
            'event' => 'task.created',
            'task_id' => $event->task->id,
            'task_title' => $event->task->title,
            'status' => $event->task->status,
            'priority' => $event->task->priority,
            'assignee_id' => $event->task->assignee_id,
            'assignee_name' => $event->task->assignee->name,
            'creator_id' => $event->creator->id,
            'creator_name' => $event->creator->name,
            'due_date' => $event->task->due_date,
            'due_time' => $event->task->due_time,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log task status changed event.
     *
     * @param TaskStatusChanged $event
     * @return void
     */
    private function logTaskStatusChanged(TaskStatusChanged $event): void
    {
        Log::channel('daily')->info('TASK STATUS CHANGED', [
            'event' => 'task.status_changed',
            'task_id' => $event->task->id,
            'task_title' => $event->task->title,
            'old_status' => $event->oldStatus,
            'new_status' => $event->newStatus,
            'changed_by_id' => $event->user->id,
            'changed_by_name' => $event->user->name,
            'assignee_id' => $event->task->assignee_id,
            'creator_id' => $event->task->creator_id,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log task assigned/reassigned event.
     *
     * @param TaskAssigned $event
     * @return void
     */
    private function logTaskAssigned(TaskAssigned $event): void
    {
        Log::channel('daily')->info('TASK ASSIGNED', [
            'event' => 'task.assigned',
            'task_id' => $event->task->id,
            'task_title' => $event->task->title,
            'assignee_id' => $event->assignee->id,
            'assignee_name' => $event->assignee->name,
            'assigner_id' => $event->assigner->id,
            'assigner_name' => $event->assigner->name,
            'priority' => $event->task->priority,
            'status' => $event->task->status,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Handle a job failure.
     *
     * @param object $event
     * @param \Throwable $exception
     * @return void
     */
    public function failed(object $event, \Throwable $exception): void
    {
        Log::error('LogTaskHistory listener failed: ' . $exception->getMessage());
    }
}
