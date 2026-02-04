<?php

namespace App\Listeners;

use App\Events\TaskAssigned;
use App\Events\TaskCreated;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskCreatedEmailNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendTaskCreatedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * Send notifications to assignee when a task is created or assigned:
     * - In-app notification
     * - Email notification
     * - WhatsApp notification (if enabled)
     * - Web Push notification (if enabled)
     *
     * @param TaskCreated|TaskAssigned $event
     * @return void
     */
    public function handle(TaskCreated|TaskAssigned $event): void
    {
        try {
            $task = $event->task;

            // Handle both TaskCreated and TaskAssigned events
            if ($event instanceof TaskAssigned) {
                $assignee = $event->assignee;
                $creator = $event->assigner;
            } else {
                $assignee = $task->assignee;
                $creator = $event->creator;
            }

            Log::info("Processing task created notification for task {$task->id} assigned to {$assignee->id}");

            // Get assignee notification preferences
            $preferences = $assignee->notification_preferences ?? [];

            // 1. Create IN-APP notification (always)
            $this->createInAppNotification($assignee, $task, $creator);

            // 2. Send EMAIL notification (if email exists)
            if ($assignee->email) {
                $this->sendEmailNotification($assignee, $task, $creator);
            }

            // 3. Create WHATSAPP notification (if has phone number)
            $phone = $assignee->whatsapp_phone ?? $assignee->phone;
            if (($preferences['whatsapp'] ?? true) && $phone) {
                $this->createWhatsAppNotification($assignee, $task, $creator);
            }

            // 4. Send WEB PUSH notification (if enabled) - in try-catch to not block other notifications
            try {
                if ($preferences['web_push'] ?? true) {
                    $assignee->notify(new TaskAssignedNotification($task));
                    Log::info("Web Push notification sent for task {$task->id} to user {$assignee->id}");
                }
            } catch (\Exception $e) {
                Log::warning("Web Push notification failed for task {$task->id}: " . $e->getMessage());
            }

            Log::info("Task created notifications processed for task {$task->id}");
        } catch (\Exception $e) {
            Log::error('Error creating task notifications: ' . $e->getMessage(), [
                'task_id' => $event->task->id ?? null,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Create in-app notification for user.
     */
    private function createInAppNotification(User $user, $task, User $creator): void
    {
        Notification::create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'type' => 'task_created',
            'channel' => 'in_app',
            'title' => 'Nueva tarea asignada',
            'message' => "Se te ha asignado la tarea: {$task->title}",
            'data' => [
                'task_id' => $task->id,
                'task_title' => $task->title,
                'due_date' => $task->due_date,
                'due_time' => $task->due_time,
                'creator_id' => $creator->id,
                'creator_name' => $creator->name,
            ],
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        Log::info("In-app notification created for task {$task->id} to user {$user->id}");
    }

    /**
     * Send email notification.
     */
    private function sendEmailNotification(User $user, $task, User $creator): void
    {
        try {
            $user->notify(new TaskCreatedEmailNotification($task, $creator));

            // Record email notification
            Notification::create([
                'user_id' => $user->id,
                'task_id' => $task->id,
                'type' => 'task_created',
                'channel' => 'email',
                'title' => 'Nueva tarea asignada',
                'message' => "Se te ha asignado la tarea: {$task->title}",
                'data' => [
                    'task_id' => $task->id,
                    'email' => $user->email,
                ],
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            Log::info("Email notification sent for task {$task->id} to {$user->email}");
        } catch (\Exception $e) {
            Log::error("Failed to send email notification: " . $e->getMessage());
        }
    }

    /**
     * Create WhatsApp notification (to be processed by n8n).
     */
    private function createWhatsAppNotification(User $user, $task, User $creator): void
    {
        $dueDate = $task->formatted_due_date ?? $task->due_date;
        $dueTime = $task->due_time ? " a las {$task->due_time}" : "";

        Notification::create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'type' => 'task_created',
            'channel' => 'whatsapp',
            'title' => 'Nueva tarea asignada',
            'message' => "Nueva tarea asignada: {$task->title}",
            'data' => [
                'phone' => $user->whatsapp_phone ?? $user->phone,
                'template' => 'tarea_asignada',
                'template_params' => [
                    'user_name' => $user->name,
                    'task_title' => $task->title,
                    'due_date' => $dueDate . $dueTime,
                    'creator_name' => $creator->name,
                ],
                'button_params' => [
                    'task_id' => $task->id,
                ],
            ],
            'status' => 'pending',
        ]);

        Log::info("WhatsApp notification created for task {$task->id} to user {$user->id}");
    }

    /**
     * Handle a job failure.
     */
    public function failed(TaskCreated|TaskAssigned $event, \Throwable $exception): void
    {
        Log::error('SendTaskCreatedNotification listener failed: ' . $exception->getMessage(), [
            'task_id' => $event->task->id ?? null,
        ]);
    }
}
