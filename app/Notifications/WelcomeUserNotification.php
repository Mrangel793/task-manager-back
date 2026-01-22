<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeUserNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected string $password;
    protected User $createdBy;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $password, User $createdBy)
    {
        $this->password = $password;
        $this->createdBy = $createdBy;
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
        $loginUrl = config('app.frontend_url') . '/login';

        return (new MailMessage)
            ->subject('Bienvenido a Task Manager - Credenciales de acceso')
            ->greeting('Hola ' . $notifiable->name . ',')
            ->line('Se ha creado tu cuenta en **Task Manager**.')
            ->line('')
            ->line('**Tus credenciales de acceso son:**')
            ->line('- **Correo:** ' . $notifiable->email)
            ->line('- **Contraseña:** ' . $this->password)
            ->line('- **Rol asignado:** ' . $notifiable->role)
            ->line('')
            ->action('Iniciar sesion', $loginUrl)
            ->line('')
            ->line('Por seguridad, te recomendamos cambiar tu contraseña despues de iniciar sesion por primera vez.')
            ->line('')
            ->line('Cuenta creada por: ' . $this->createdBy->name);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'created_by_id' => $this->createdBy->id,
            'created_by_name' => $this->createdBy->name,
        ];
    }
}
