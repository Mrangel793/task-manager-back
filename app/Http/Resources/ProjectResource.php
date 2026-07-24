<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $progress = $this->progress;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'color' => $this->color,
            'due_date' => $this->due_date?->format('Y-m-d'),
            'health' => $this->health,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            // Progress bar data
            'progress' => $progress,

            // Relationships
            'creator' => new UserResource($this->whenLoaded('creator')),

            // Tasks (only when explicitly loaded)
            'tasks' => TaskResource::collection($this->whenLoaded('tasks')),
        ];
    }
}
