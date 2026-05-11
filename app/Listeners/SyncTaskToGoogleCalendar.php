<?php

namespace App\Listeners;

use App\Events\TaskAssigned;
use App\Events\TaskCreated;
use App\Events\TaskDeleted;
use App\Events\TaskStatusChanged;
use App\Events\TaskUpdated;
use App\Jobs\SyncTaskToGoogleCalendarJob;
use App\Models\TaskCalendarEvent;

class SyncTaskToGoogleCalendar
{
    public function handleTaskCreated(TaskCreated $event): void
    {
        $task = $event->task;
        $this->dispatchForUsers($task->id, $task->assignee_id, $task->creator_id, 'create');
    }

    public function handleTaskUpdated(TaskUpdated $event): void
    {
        $task = $event->task;
        $this->dispatchForUsers($task->id, $task->assignee_id, $task->creator_id, 'update');
    }

    public function handleTaskStatusChanged(TaskStatusChanged $event): void
    {
        $task = $event->task;
        $terminal = in_array($event->newStatus, ['Completada', 'Cancelada']);
        $action = $terminal ? 'delete' : 'update';

        $this->dispatchForUsers($task->id, $task->assignee_id, $task->creator_id, $action);
    }

    public function handleTaskAssigned(TaskAssigned $event): void
    {
        $task = $event->task;

        // Remove old assignee's calendar event (if they had one)
        if ($event->oldAssigneeId && $event->oldAssigneeId !== $task->creator_id) {
            SyncTaskToGoogleCalendarJob::dispatch($task->id, $event->oldAssigneeId, 'delete');
        }

        // Create event for new assignee
        SyncTaskToGoogleCalendarJob::dispatch($task->id, $event->assignee->id, 'create', 'assignee');

        // Update creator's event (description changes to reflect new assignee)
        if ($task->creator_id !== $event->assignee->id) {
            SyncTaskToGoogleCalendarJob::dispatch($task->id, $task->creator_id, 'update', 'creator');
        }
    }

    public function handleTaskDeleted(TaskDeleted $event): void
    {
        $task = $event->task;

        // Delete all calendar events for this task
        $calEvents = TaskCalendarEvent::where('task_id', $task->id)->get();
        foreach ($calEvents as $calEvent) {
            SyncTaskToGoogleCalendarJob::dispatch($task->id, $calEvent->user_id, 'delete');
        }
    }

    private function dispatchForUsers(string $taskId, string $assigneeId, string $creatorId, string $action): void
    {
        SyncTaskToGoogleCalendarJob::dispatch($taskId, $assigneeId, $action, 'assignee');

        if ($creatorId !== $assigneeId) {
            SyncTaskToGoogleCalendarJob::dispatch($taskId, $creatorId, $action, 'creator');
        }
    }
}
