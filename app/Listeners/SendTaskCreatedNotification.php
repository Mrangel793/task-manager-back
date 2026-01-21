<?php

namespace App\Listeners;

use App\Events\TaskCreated;
use App\Models\Notification;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendTaskCreatedNotification implements ShouldQueue
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
     * Enqueue notification jobs for Web Push and WhatsApp.
     *
     * @param TaskCreated $event
     * @return void
     */
    public function handle(TaskCreated $event): void
    {
        try {
            $task = $event->task;
            $assignee = $task->assignee;

            // Send push notification using Laravel notification system
            // This will automatically handle WebPush if user has subscriptions
            $assignee->notify(new TaskAssignedNotification($task));

            Log::info("TaskAssignedNotification sent for task {$task->id} to user {$assignee->id}");

            // Check assignee's notification preferences for other channels
            $preferences = $assignee->notification_preferences;

            // Create WhatsApp notification if enabled and user has WhatsApp verified
            if (($preferences['whatsapp'] ?? true) && $assignee->whatsapp_verified) {
                Notification::create([
                    'user_id' => $assignee->id,
                    'type' => 'task_created',
                    'channel' => 'whatsapp',
                    'title' => 'Nueva tarea asignada',
                    'message' => "📋 *Nueva tarea asignada*\n\n"
                        . "*Título:* {$task->title}\n"
                        . "*Prioridad:* {$task->priority}\n"
                        . "*Vence:* {$task->formatted_due_date} a las {$task->due_time}\n"
                        . "*Asignado por:* {$event->creator->name}",
                    'data' => [
                        'task_id' => $task->id,
                        'phone' => $assignee->whatsapp_phone ?? $assignee->phone,
                    ],
                    'status' => 'pending',
                ]);

                Log::info("WhatsApp notification created for task {$task->id} assigned to user {$assignee->id}");
            }

            // TODO: Dispatch WhatsApp notification job to queue
            // dispatch(new SendWhatsAppNotificationJob($notification));
        } catch (\Exception $e) {
            Log::error('Error creating task notifications: ' . $e->getMessage());
        }
    }

    /**
     * Handle a job failure.
     *
     * @param TaskCreated $event
     * @param \Throwable $exception
     * @return void
     */
    public function failed(TaskCreated $event, \Throwable $exception): void
    {
        Log::error('SendTaskCreatedNotification listener failed: ' . $exception->getMessage());
    }
}
