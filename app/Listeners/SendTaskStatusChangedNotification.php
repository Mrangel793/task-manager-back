<?php

namespace App\Listeners;

use App\Events\TaskStatusChanged;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\TaskStatusChangedEmailNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendTaskStatusChangedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * Notify Admin users when a task status changes:
     * - In-app notification
     * - Email notification
     * - WhatsApp notification (if enabled)
     *
     * @param TaskStatusChanged $event
     * @return void
     */
    public function handle(TaskStatusChanged $event): void
    {
        try {
            $task = $event->task;
            $changedBy = $event->user;

            Log::info("Processing status change notification for task {$task->id}: {$event->oldStatus} -> {$event->newStatus}");

            // Get all Admin users in the same organization (except the one who made the change)
            $admins = User::role('Admin')
                ->where('organization_id', $task->organization_id)
                ->where('is_active', true)
                ->where('id', '!=', $changedBy->id)
                ->get();

            foreach ($admins as $admin) {
                $this->sendNotificationsToUser($admin, $event);
            }

            // Also notify the task creator if they're not Admin and not the one who changed
            $creator = $task->creator;
            if ($creator->id !== $changedBy->id && !$creator->hasRole('Admin')) {
                $this->sendNotificationsToUser($creator, $event);
            }

            // Notify assignee if they didn't make the change
            $assignee = $task->assignee;
            if ($assignee && $assignee->id !== $changedBy->id && $assignee->id !== $creator->id) {
                $this->sendNotificationsToUser($assignee, $event);
            }

            Log::info("Status change notifications processed for task {$task->id}");
        } catch (\Exception $e) {
            Log::error('Error creating status change notifications: ' . $e->getMessage(), [
                'task_id' => $event->task->id ?? null,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Send all notification types to a user.
     */
    private function sendNotificationsToUser(User $user, TaskStatusChanged $event): void
    {
        $task = $event->task;
        $preferences = $user->notification_preferences ?? [];

        // 1. Create IN-APP notification (always)
        $this->createInAppNotification($user, $event);

        // 2. Send EMAIL notification (if email exists)
        if ($user->email) {
            $this->sendEmailNotification($user, $event);
        }

        // 3. Create WHATSAPP notification (if has phone number)
        $phone = $user->whatsapp_phone ?? $user->phone;
        if (($preferences['whatsapp'] ?? true) && $phone) {
            $this->createWhatsAppNotification($user, $event);
        }
    }

    /**
     * Create in-app notification.
     */
    private function createInAppNotification(User $user, TaskStatusChanged $event): void
    {
        $task = $event->task;

        Notification::create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'type' => 'task_status_changed',
            'channel' => 'in_app',
            'title' => 'Cambio de estado de tarea',
            'message' => "La tarea '{$task->title}' cambio de '{$event->oldStatus}' a '{$event->newStatus}'",
            'data' => [
                'task_id' => $task->id,
                'task_title' => $task->title,
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus,
                'changed_by_id' => $event->user->id,
                'changed_by_name' => $event->user->name,
                'assignee_name' => $task->assignee->name ?? null,
            ],
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        Log::info("In-app status change notification created for task {$task->id} to user {$user->id}");
    }

    /**
     * Send email notification.
     */
    private function sendEmailNotification(User $user, TaskStatusChanged $event): void
    {
        try {
            $task = $event->task;

            $user->notify(new TaskStatusChangedEmailNotification(
                $task,
                $event->oldStatus,
                $event->newStatus,
                $event->user
            ));

            // Record email notification
            Notification::create([
                'user_id' => $user->id,
                'task_id' => $task->id,
                'type' => 'task_status_changed',
                'channel' => 'email',
                'title' => 'Cambio de estado de tarea',
                'message' => "La tarea '{$task->title}' cambio de '{$event->oldStatus}' a '{$event->newStatus}'",
                'data' => [
                    'task_id' => $task->id,
                    'email' => $user->email,
                ],
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            Log::info("Email status change notification sent for task {$task->id} to {$user->email}");
        } catch (\Exception $e) {
            Log::error("Failed to send status change email notification: " . $e->getMessage());
        }
    }

    /**
     * Create WhatsApp notification (to be processed by n8n).
     */
    private function createWhatsAppNotification(User $user, TaskStatusChanged $event): void
    {
        $task = $event->task;

        $statusEmoji = match ($event->newStatus) {
            'Pendiente' => '⏸️',
            'En Progreso' => '▶️',
            'Completada' => '✅',
            'Cancelada' => '❌',
            default => '🔄',
        };

        Notification::create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'type' => 'task_status_changed',
            'channel' => 'whatsapp',
            'title' => 'Cambio de estado de tarea',
            'message' => "{$statusEmoji} *Cambio de estado*\n\n"
                . "*Tarea:* {$task->title}\n"
                . "*Estado anterior:* {$event->oldStatus}\n"
                . "*Estado nuevo:* {$event->newStatus}\n"
                . "*Cambiado por:* {$event->user->name}\n"
                . "*Asignado a:* " . ($task->assignee->name ?? 'Sin asignar'),
            'data' => [
                'phone' => $user->whatsapp_phone ?? $user->phone,
                'template' => 'tarea_estado_cambio',
                'template_params' => [
                    'user_name' => $user->name,
                    'task_title' => $task->title,
                    'old_status' => $event->oldStatus,
                    'new_status' => $event->newStatus,
                    'changed_by_name' => $event->user->name,
                ],
                'button_params' => [
                    'task_id' => $task->id,
                ],
            ],
            'status' => 'pending',
        ]);

        Log::info("WhatsApp status change notification created for task {$task->id} to user {$user->id}");
    }

    /**
     * Handle a job failure.
     */
    public function failed(TaskStatusChanged $event, \Throwable $exception): void
    {
        Log::error('SendTaskStatusChangedNotification listener failed: ' . $exception->getMessage(), [
            'task_id' => $event->task->id ?? null,
        ]);
    }
}
