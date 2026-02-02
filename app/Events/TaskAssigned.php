<?php

namespace App\Events;

use App\Models\Task;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskAssigned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The task that was assigned/reassigned.
     */
    public Task $task;

    /**
     * The user to whom the task was assigned.
     */
    public User $assignee;

    /**
     * The user who assigned the task.
     */
    public User $assigner;

    /**
     * Create a new event instance.
     *
     * @param Task $task
     * @param User $assignee
     * @param User $assigner
     */
    public function __construct(Task $task, User $assignee, User $assigner)
    {
        $this->task = $task;
        $this->assignee = $assignee;
        $this->assigner = $assigner;
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
            new PrivateChannel('user.' . $this->assignee->id),
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
                'status' => $this->task->status,
                'due_date' => $this->task->due_date,
                'due_time' => $this->task->due_time,
            ],
            'assignee' => [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ],
            'assigner' => [
                'id' => $this->assigner->id,
                'name' => $this->assigner->name,
            ],
            'message' => "Tarea '{$this->task->title}' asignada a {$this->assignee->name}",
        ];
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'task.assigned';
    }
}
