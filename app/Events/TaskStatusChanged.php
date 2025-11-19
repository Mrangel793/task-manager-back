<?php

namespace App\Events;

use App\Models\Task;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The task whose status changed.
     */
    public Task $task;

    /**
     * The old status value.
     */
    public string $oldStatus;

    /**
     * The new status value.
     */
    public string $newStatus;

    /**
     * The user who changed the status.
     */
    public User $user;

    /**
     * Create a new event instance.
     *
     * @param Task $task
     * @param string $oldStatus
     * @param string $newStatus
     * @param User $user
     */
    public function __construct(Task $task, string $oldStatus, string $newStatus, User $user)
    {
        $this->task = $task;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->user = $user;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            // Broadcast to the assignee's private channel
            new PrivateChannel('user.' . $this->task->assignee_id),
            // Broadcast to the creator's private channel
            new PrivateChannel('user.' . $this->task->creator_id),
            // Broadcast to admin channel
            new PrivateChannel('admin'),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        return [
            'task' => [
                'id' => $this->task->id,
                'title' => $this->task->title,
                'status' => $this->newStatus,
                'priority' => $this->task->priority,
            ],
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'changed_by' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],
            'message' => "Estado de tarea '{$this->task->title}' cambió de '{$this->oldStatus}' a '{$this->newStatus}'",
        ];
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'task.status-changed';
    }
}
