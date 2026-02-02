<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskCreatedEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected Task $task;
    protected User $creator;

    /**
     * Create a new notification instance.
     */
    public function __construct(Task $task, User $creator)
    {
        $this->task = $task;
        $this->creator = $creator;
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
        $dueDate = $this->task->formatted_due_date ?? $this->task->due_date;
        $dueTime = $this->task->due_time ?? '';

        return (new MailMessage)
            ->subject('Nueva tarea asignada: ' . $this->task->title)
            ->greeting('Hola ' . $notifiable->name . ',')
            ->line('Se te ha asignado una nueva tarea.')
            ->line('')
            ->line('**Detalles de la tarea:**')
            ->line('- **Titulo:** ' . $this->task->title)
            ->line('- **Fecha limite:** ' . $dueDate . ($dueTime ? ' a las ' . $dueTime : ''))
            ->line('- **Asignado por:** ' . $this->creator->name)
            ->line('')
            ->when($this->task->description, function ($mail) {
                return $mail->line('**Descripcion:** ' . $this->task->description);
            })
            ->action('Ver tarea', config('app.frontend_url') . '/tasks/' . $this->task->id)
            ->line('Por favor, revisa la tarea y comienza a trabajar en ella.');
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
            'creator_id' => $this->creator->id,
            'creator_name' => $this->creator->name,
        ];
    }
}
