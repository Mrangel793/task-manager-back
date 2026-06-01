<?php

namespace App\Events;

use App\Models\Task;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The task that was created.
     */
    public Task $task;

    /**
     * The user who created the task.
     */
    public User $creator;

    /**
     * Create a new event instance.
     *
     * @param Task $task
     * @param User $creator
     */
    public function __construct(Task $task, User $creator)
    {
        $this->task = $task;
        $this->creator = $creator;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('user.' . $this->task->assignee_id),
        ];

        // Only notify the admin channel if creator and assignee are different
        // (i.e., admin assigned the task to someone else)
        if ($this->task->creator_id !== $this->task->assignee_id) {
            $channels[] = new PrivateChannel('user.' . $this->task->creator_id);
        }

        return $channels;
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
            'creator' => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ],
            'message' => "Nueva tarea asignada: {$this->task->title}",
        ];
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'task.created';
    }
}
