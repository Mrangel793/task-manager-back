<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TaskHistory API Resource
 *
 * @property string $id
 * @property string $task_id
 * @property string $user_id
 * @property string $action
 * @property array|null $from_value
 * @property array|null $to_value
 * @property \Illuminate\Support\Carbon $created_at
 * @property \App\Models\User $user
 *
 * @mixin \App\Models\TaskHistory
 */
class TaskHistoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'event_type' => $this->action,
            'description' => $this->getDescription(),
            'old_value' => $this->from_value,
            'new_value' => $this->to_value,
            'created_at' => $this->created_at?->toISOString(),
            'changed_by' => new UserResource($this->whenLoaded('user')),
        ];
    }

    /**
     * Generate a human-readable description for the history event.
     *
     * @return string
     */
    protected function getDescription(): string
    {
        $userName = $this->user?->name ?? 'Usuario desconocido';

        return match ($this->action) {
            'created' => "{$userName} creó la tarea",
            'status_changed' => $this->getStatusChangeDescription($userName),
            'assigned' => "{$userName} asignó la tarea a {$this->getUserName($this->to_value)}",
            'reassigned' => "{$userName} reasignó la tarea de {$this->getUserName($this->from_value)} a {$this->getUserName($this->to_value)}",
            'updated' => "{$userName} actualizó la tarea",
            'deleted' => "{$userName} eliminó la tarea",
            default => "{$userName} realizó una acción en la tarea",
        };
    }

    /**
     * Get status change description.
     *
     * @param string $userName
     * @return string
     */
    protected function getStatusChangeDescription(string $userName): string
    {
        $oldStatus = $this->from_value['status'] ?? 'desconocido';
        $newStatus = $this->to_value['status'] ?? 'desconocido';

        return "{$userName} cambió el estado de '{$oldStatus}' a '{$newStatus}'";
    }

    /**
     * Get user name from value array.
     *
     * @param array|null $value
     * @return string
     */
    protected function getUserName(?array $value): string
    {
        return $value['assignee_name'] ?? $value['name'] ?? 'desconocido';
    }
}
