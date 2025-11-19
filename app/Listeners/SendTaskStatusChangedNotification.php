<?php

namespace App\Listeners;

use App\Events\TaskStatusChanged;
use App\Models\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendTaskStatusChangedNotification implements ShouldQueue
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
     * Notify supervisor and creator about status change.
     *
     * @param TaskStatusChanged $event
     * @return void
     */
    public function handle(TaskStatusChanged $event): void
    {
        try {
            $task = $event->task;
            $creator = $task->creator;
            $assignee = $task->assignee;

            // Notify creator if they're not the one who changed the status
            if ($creator->id !== $event->user->id) {
                $this->sendNotificationToUser($creator, $event);
            }

            // Notify assignee if they're not the one who changed the status
            if ($assignee->id !== $event->user->id) {
                $this->sendNotificationToUser($assignee, $event);
            }

            // Notify supervisors (users with Supervisor role)
            // Only if a supervisor is not the creator or assignee and didn't make the change
            $supervisors = \App\Models\User::role('Supervisor')
                ->where('is_active', true)
                ->whereNotIn('id', [
                    $creator->id,
                    $assignee->id,
                    $event->user->id,
                ])
                ->get();

            foreach ($supervisors as $supervisor) {
                $this->sendNotificationToUser($supervisor, $event);
            }
        } catch (\Exception $e) {
            Log::error('Error creating status change notifications: ' . $e->getMessage());
        }
    }

    /**
     * Send notification to a specific user.
     *
     * @param \App\Models\User $user
     * @param TaskStatusChanged $event
     * @return void
     */
    private function sendNotificationToUser(\App\Models\User $user, TaskStatusChanged $event): void
    {
        $task = $event->task;
        $preferences = $user->notification_preferences;

        // Create Web Push notification if enabled
        if ($preferences['web_push'] ?? true) {
            Notification::create([
                'user_id' => $user->id,
                'type' => 'task_status_changed',
                'channel' => 'web_push',
                'title' => 'Cambio de estado de tarea',
                'message' => "La tarea '{$task->title}' cambió de '{$event->oldStatus}' a '{$event->newStatus}'",
                'data' => [
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                    'old_status' => $event->oldStatus,
                    'new_status' => $event->newStatus,
                    'changed_by' => [
                        'id' => $event->user->id,
                        'name' => $event->user->name,
                    ],
                ],
                'status' => 'pending',
            ]);

            Log::info("Web Push status change notification created for task {$task->id} to user {$user->id}");
        }

        // Create WhatsApp notification if enabled and user has WhatsApp verified
        if (($preferences['whatsapp'] ?? true) && $user->whatsapp_verified) {
            Notification::create([
                'user_id' => $user->id,
                'type' => 'task_status_changed',
                'channel' => 'whatsapp',
                'title' => 'Cambio de estado de tarea',
                'message' => "🔄 *Cambio de estado*\n\n"
                    . "*Tarea:* {$task->title}\n"
                    . "*Estado anterior:* {$event->oldStatus}\n"
                    . "*Estado nuevo:* {$event->newStatus}\n"
                    . "*Cambiado por:* {$event->user->name}",
                'data' => [
                    'task_id' => $task->id,
                    'phone' => $user->whatsapp_phone ?? $user->phone,
                ],
                'status' => 'pending',
            ]);

            Log::info("WhatsApp status change notification created for task {$task->id} to user {$user->id}");
        }

        // TODO: Dispatch notification jobs to queue
        // dispatch(new SendWebPushNotificationJob($notification));
        // dispatch(new SendWhatsAppNotificationJob($notification));
    }

    /**
     * Handle a job failure.
     *
     * @param TaskStatusChanged $event
     * @param \Throwable $exception
     * @return void
     */
    public function failed(TaskStatusChanged $event, \Throwable $exception): void
    {
        Log::error('SendTaskStatusChangedNotification listener failed: ' . $exception->getMessage());
    }
}
