<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProjectService
{
    public function createProject(array $data, User $creator): Project
    {
        try {
            DB::beginTransaction();

            $project = Project::create([
                'organization_id' => $creator->organization_id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => 'Activo',
                'color' => $data['color'] ?? '#3B82F6',
                'due_date' => $data['due_date'] ?? null,
                'created_by' => $creator->id,
                'visibility' => $data['visibility'] ?? 'todos',
            ]);

            DB::commit();

            return $project->load('creator');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al crear proyecto: ' . $e->getMessage());
            throw $e;
        }
    }

    public function updateProject(Project $project, array $data, User $user): Project
    {
        try {
            DB::beginTransaction();

            $updateData = array_filter([
                'name' => $data['name'] ?? null,
                'description' => array_key_exists('description', $data) ? $data['description'] : null,
                'color' => $data['color'] ?? null,
                'due_date' => array_key_exists('due_date', $data) ? $data['due_date'] : null,
                'visibility' => $data['visibility'] ?? null,
            ], fn ($v) => $v !== null);

            $project->update($updateData);

            DB::commit();

            return $project->load('creator');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al actualizar proyecto: ' . $e->getMessage());
            throw $e;
        }
    }

    public function updateStatus(Project $project, string $status): Project
    {
        $project->update(['status' => $status]);
        return $project;
    }

    public function deleteProject(Project $project): bool
    {
        try {
            DB::beginTransaction();

            // Desasociar tareas del proyecto (no las elimina)
            $project->tasks()->update(['project_id' => null]);

            $project->delete();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al eliminar proyecto: ' . $e->getMessage());
            throw $e;
        }
    }
}
