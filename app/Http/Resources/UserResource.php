<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'is_active' => $this->is_active,
            'whatsapp_verified' => $this->whatsapp_verified,
            'whatsapp_phone' => $this->whatsapp_phone,
            'notification_preferences' => $this->notification_preferences,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'last_login_at' => $this->last_login_at?->toISOString(),

            // Optional relationships
            'tasks_assigned' => $this->whenLoaded('tasksAssigned', function () {
                return $this->tasksAssigned->map(function ($task) {
                    return [
                        'id' => $task->id,
                        'title' => $task->title,
                        'status' => $task->status,
                        'priority' => $task->priority,
                        'due_date' => $task->due_date,
                    ];
                });
            }),

            'tasks_created' => $this->whenLoaded('tasksCreated', function () {
                return $this->tasksCreated->map(function ($task) {
                    return [
                        'id' => $task->id,
                        'title' => $task->title,
                        'status' => $task->status,
                        'priority' => $task->priority,
                        'due_date' => $task->due_date,
                    ];
                });
            }),

            // Include permissions and roles only for authenticated user or admins
            'permissions' => $this->when(
                $request->user()?->hasRole('Admin') || $request->user()?->id === $this->id,
                fn() => $this->getAllPermissions()->pluck('name')
            ),

            'roles' => $this->when(
                $request->user()?->hasRole('Admin') || $request->user()?->id === $this->id,
                fn() => $this->getRoleNames()
            ),
        ];
    }
}
