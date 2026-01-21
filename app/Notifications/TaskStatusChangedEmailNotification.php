<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskStatusChangedEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected Task $task;
    protected string $oldStatus;
    protected string $newStatus;
    protected User $changedBy;

    /**
     * Create a new notification instance.
     */
    public function __construct(Task $task, string $oldStatus, string $newStatus, User $changedBy)
    {
        $this->task = $task;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->changedBy = $changedBy;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $statusEmoji = match ($this->newStatus) {
            'Pendiente' => '⏸️',
            'En Progreso' => '▶️',
            'Completada' => '✅',
            'Cancelada' => '❌',
            default => '🔄',
        };

        $subject = match ($this->newStatus) {
            'Completada' => 'Tarea completada: ' . $this->task->title,
            'En Progreso' => 'Tarea iniciada: ' . $this->task->title,
            'Cancelada' => 'Tarea cancelada: ' . $this->task->title,
            default => 'Cambio de estado en tarea: ' . $this->task->title,
        };

        return (new MailMessage)
            ->subject($statusEmoji . ' ' . $subject)
            ->greeting('Hola ' . $notifiable->name . ',')
            ->line('Una tarea ha cambiado de estado.')
            ->line('')
            ->line('**Detalles del cambio:**')
            ->line('- **Tarea:** ' . $this->task->title)
            ->line('- **Estado anterior:** ' . $this->oldStatus)
            ->line('- **Estado nuevo:** ' . $this->newStatus)
            ->line('- **Cambiado por:** ' . $this->changedBy->name)
            ->line('- **Asignado a:** ' . ($this->task->assignee->name ?? 'Sin asignar'))
            ->line('')
            ->action('Ver tarea', config('app.frontend_url') . '/tasks/' . $this->task->id)
            ->line('Puedes ver mas detalles en el sistema.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'changed_by_id' => $this->changedBy->id,
            'changed_by_name' => $this->changedBy->name,
        ];
    }
}
