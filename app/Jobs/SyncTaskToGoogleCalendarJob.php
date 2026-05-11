<?php

namespace App\Jobs;

use App\Models\Task;
use App\Models\TaskCalendarEvent;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncTaskToGoogleCalendarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    /**
     * @param string $taskId
     * @param string $userId
     * @param string $action  'create' | 'update' | 'delete'
     * @param string $role    'assignee' | 'creator'
     */
    public function __construct(
        private readonly string $taskId,
        private readonly string $userId,
        private readonly string $action,
        private readonly string $role = 'assignee',
    ) {}

    public function handle(GoogleCalendarService $googleCalendar): void
    {
        $user = User::withoutGlobalScopes()->find($this->userId);
        if (!$user || !$googleCalendar->isConnected($user)) {
            return;
        }

        try {
            match ($this->action) {
                'create' => $this->handleCreate($googleCalendar, $user),
                'update' => $this->handleUpdate($googleCalendar, $user),
                'delete' => $this->handleDelete($googleCalendar, $user),
            };
        } catch (\RuntimeException $e) {
            // Token revocado u otros errores no retriables
            Log::warning("GoogleCalendar [{$this->action}] user={$this->userId} task={$this->taskId}: {$e->getMessage()}");
            $this->fail($e);
        }
    }

    private function handleCreate(GoogleCalendarService $googleCalendar, User $user): void
    {
        // Idempotent: skip if already synced
        $existing = TaskCalendarEvent::where('task_id', $this->taskId)
            ->where('user_id', $this->userId)
            ->first();

        if ($existing) {
            return;
        }

        $task = Task::withoutGlobalScopes()->withTrashed()->findOrFail($this->taskId);
        $eventId = $googleCalendar->createEvent($user, $task, $this->role);

        TaskCalendarEvent::create([
            'task_id' => $this->taskId,
            'user_id' => $this->userId,
            'google_event_id' => $eventId,
            'google_calendar_id' => 'primary',
        ]);
    }

    private function handleUpdate(GoogleCalendarService $googleCalendar, User $user): void
    {
        $calEvent = TaskCalendarEvent::where('task_id', $this->taskId)
            ->where('user_id', $this->userId)
            ->first();

        if (!$calEvent) {
            // No event to update — create it instead
            $this->handleCreate($googleCalendar, $user);
            return;
        }

        $task = Task::withoutGlobalScopes()->withTrashed()->findOrFail($this->taskId);
        $googleCalendar->updateEvent($user, $task, $calEvent->google_event_id, $this->role);
    }

    private function handleDelete(GoogleCalendarService $googleCalendar, User $user): void
    {
        $calEvent = TaskCalendarEvent::where('task_id', $this->taskId)
            ->where('user_id', $this->userId)
            ->first();

        if (!$calEvent) {
            return;
        }

        $googleCalendar->deleteEvent($user, $calEvent->google_event_id);
        $calEvent->delete();
    }
}
