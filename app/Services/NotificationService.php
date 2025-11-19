<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Create a notification record.
     *
     * @param User $user
     * @param Task $task
     * @param string $type
     * @param string $channel
     * @return Notification
     */
    public function createNotification(User $user, Task $task, string $type, string $channel): Notification
    {
        $template = $this->getNotificationTemplate($type, $task);

        return Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'channel' => $channel,
            'title' => $template['title'],
            'message' => $template['message'],
            'data' => array_merge($template['data'], [
                'task_id' => $task->id,
                'phone' => $channel === 'whatsapp' ? ($user->whatsapp_phone ?? $user->phone) : null,
            ]),
            'status' => 'pending',
        ]);
    }

    /**
     * Send Web Push notification.
     *
     * @param Notification $notification
     * @return bool
     */
    public function sendWebPushNotification(Notification $notification): bool
    {
        try {
            // TODO: Implement actual Web Push notification using service like OneSignal, FCM, etc.
            // For now, we'll simulate the sending

            Log::info("Sending Web Push notification {$notification->id} to user {$notification->user_id}");

            // Simulate API call
            // $response = Http::post('https://api.onesignal.com/notifications', [
            //     'app_id' => config('services.onesignal.app_id'),
            //     'include_player_ids' => [$notification->user->push_token],
            //     'headings' => ['en' => $notification->title],
            //     'contents' => ['en' => $notification->message],
            //     'data' => $notification->data,
            // ]);

            // Mark as sent
            $notification->markAsSent();

            Log::info("Web Push notification {$notification->id} sent successfully");

            return true;
        } catch (\Exception $e) {
            Log::error("Error sending Web Push notification {$notification->id}: " . $e->getMessage());

            $notification->markAsFailed('Error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Send WhatsApp notification via N8n webhook.
     *
     * @param Notification $notification
     * @return bool
     */
    public function sendWhatsAppNotification(Notification $notification): bool
    {
        try {
            Log::info("Sending WhatsApp notification {$notification->id} to user {$notification->user_id}");

            $n8nService = app(N8nWebhookService::class);

            $data = [
                'notification_id' => $notification->id,
                'user_id' => $notification->user_id,
                'phone' => $notification->data['phone'] ?? null,
                'message' => $notification->message,
                'task_id' => $notification->data['task_id'] ?? null,
                'type' => $notification->type,
            ];

            $success = $n8nService->sendWhatsAppNotification($data);

            if ($success) {
                $notification->markAsSent();
                Log::info("WhatsApp notification {$notification->id} sent successfully");
                return true;
            } else {
                $notification->markAsFailed('N8n webhook failed');
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Error sending WhatsApp notification {$notification->id}: " . $e->getMessage());

            $notification->markAsFailed('Error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Send task reminder to assignee.
     *
     * @param Task $task
     * @return void
     */
    public function sendTaskReminder(Task $task): void
    {
        try {
            $assignee = $task->assignee;
            $preferences = $assignee->notification_preferences;

            Log::info("Sending task reminder for task {$task->id} to user {$assignee->id}");

            // Send Web Push reminder if enabled
            if ($preferences['web_push'] ?? true) {
                $notification = $this->createNotification($assignee, $task, 'task_reminder', 'web_push');
                $this->sendWebPushNotification($notification);
            }

            // Send WhatsApp reminder if enabled and verified
            if (($preferences['whatsapp'] ?? true) && $assignee->whatsapp_verified) {
                $notification = $this->createNotification($assignee, $task, 'task_reminder', 'whatsapp');
                $this->sendWhatsAppNotification($notification);
            }
        } catch (\Exception $e) {
            Log::error("Error sending task reminder for task {$task->id}: " . $e->getMessage());
        }
    }

    /**
     * Get notification template based on type.
     *
     * @param string $type
     * @param Task $task
     * @return array
     */
    public function getNotificationTemplate(string $type, Task $task): array
    {
        $templates = [
            'task_created' => [
                'title' => 'Nueva tarea asignada',
                'message' => "Se te ha asignado la tarea: {$task->title}",
                'data' => [
                    'task_title' => $task->title,
                    'priority' => $task->priority,
                    'due_date' => $task->due_date?->toDateString(),
                    'due_time' => $task->due_time,
                ],
            ],
            'task_status_changed' => [
                'title' => 'Cambio de estado de tarea',
                'message' => "La tarea '{$task->title}' cambió de estado",
                'data' => [
                    'task_title' => $task->title,
                    'status' => $task->status,
                ],
            ],
            'task_reminder' => [
                'title' => 'Recordatorio de tarea',
                'message' => "⏰ *Recordatorio*\n\n"
                    . "La tarea *{$task->title}* vence en 30 minutos.\n\n"
                    . "*Prioridad:* {$task->priority}\n"
                    . "*Vence:* {$task->due_date?->format('d/m/Y')} a las {$task->due_time}\n\n"
                    . "¡No olvides completarla a tiempo!",
                'data' => [
                    'task_title' => $task->title,
                    'priority' => $task->priority,
                    'due_date' => $task->due_date?->toDateString(),
                    'due_time' => $task->due_time,
                    'status' => $task->status,
                ],
            ],
        ];

        return $templates[$type] ?? [
            'title' => 'Notificación',
            'message' => 'Tienes una nueva notificación',
            'data' => [],
        ];
    }
}
