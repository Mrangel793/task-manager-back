<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class TaskAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The task instance.
     *
     * @var Task
     */
    protected Task $task;

    /**
     * Create a new notification instance.
     *
     * @param Task $task
     */
    public function __construct(Task $task)
    {
        $this->task = $task;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param object $notifiable
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        // Add WebPush channel if user has push subscriptions
        if ($notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * Get the web push representation of the notification.
     *
     * @param object $notifiable
     * @return WebPushMessage
     */
    public function toWebPush(object $notifiable): WebPushMessage
    {
        $dueDate = $this->task->due_date
            ? $this->task->due_date_carbon->format('d/m/Y') . ($this->task->due_time ? ' ' . $this->task->due_time : '')
            : 'Sin fecha límite';

        return (new WebPushMessage)
            ->title('Nueva tarea asignada')
            ->body("{$this->task->title} - Vence: {$dueDate}")
            ->icon('/icons/icon-192.png')
            ->badge('/icons/badge.png')
            ->action('Ver tarea', 'view-task')
            ->data([
                'taskId' => $this->task->id,
                'url' => config('app.frontend_url') . '/tasks/' . $this->task->id,
                'priority' => $this->task->priority,
                'status' => $this->task->status,
            ])
            ->options([
                'TTL' => 3600,
                'urgency' => $this->task->priority === 'high' ? 'high' : 'normal',
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param object $notifiable
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'task_priority' => $this->task->priority,
            'task_due_date' => $this->task->due_date,
        ];
    }
}
