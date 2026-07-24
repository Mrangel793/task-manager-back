<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
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
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'due_date' => $this->due_date,
            'due_time' => $this->due_time,
            'due_time_formatted' => $this->due_time,
            'is_overdue' => $this->isOverdue(),
            'project_id' => $this->project_id,
            'assignee_id' => $this->assignee_id,
            'creator_id' => $this->creator_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),

            // Relationships
            'project' => $this->when($this->relationLoaded('project') && $this->project, fn () => [
                'id' => $this->project->id,
                'name' => $this->project->name,
                'color' => $this->project->color,
            ]),
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'creator' => new UserResource($this->whenLoaded('creator')),

            // Include history only if explicitly requested
            'history' => $this->when(
                $request->boolean('include_history') && $this->relationLoaded('histories'),
                function () {
                    return $this->histories->map(function ($history) {
                        return [
                            'id' => $history->id,
                            'action' => $history->action,
                            'from_value' => $history->from_value,
                            'to_value' => $history->to_value,
                            'created_at' => $history->created_at?->toISOString(),
                            'user' => [
                                'id' => $history->user->id ?? null,
                                'name' => $history->user->name ?? null,
                            ],
                        ];
                    });
                }
            ),
        ];
    }
}
