<?php

namespace Tests\Feature\Security;

use App\Models\Task;
use Tests\TestCase;

class TaskReassignTest extends TestCase
{
    public function test_reasignar_sin_body_devuelve_422(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');
        $task = $this->createTask($org, $admin, $operador);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/tasks/{$task->id}/reassign", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('assignee_id');
    }

    public function test_reasignar_a_usuario_inactivo_devuelve_422_con_mensaje(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');
        $inactivo = $this->createUser($org, 'Operador', ['is_active' => false]);
        $task = $this->createTask($org, $admin, $operador);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/tasks/{$task->id}/reassign", ['assignee_id' => $inactivo->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'El usuario seleccionado no está activo.');

        $this->assertSame($operador->id, Task::withoutGlobalScopes()->find($task->id)->assignee_id);
    }

    public function test_reasignar_a_usuario_de_otra_org_devuelve_422(): void
    {
        $org = $this->createOrganization();
        $otraOrg = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');
        $ajeno = $this->createUser($otraOrg, 'Operador');
        $task = $this->createTask($org, $admin, $operador);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/tasks/{$task->id}/reassign", ['assignee_id' => $ajeno->id])
            ->assertStatus(422);

        $this->assertSame($operador->id, Task::withoutGlobalScopes()->find($task->id)->assignee_id);
    }

    public function test_reasignar_a_operador_activo_devuelve_200(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');
        $nuevo = $this->createUser($org, 'Operador');
        $task = $this->createTask($org, $admin, $operador);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/tasks/{$task->id}/reassign", ['assignee_id' => $nuevo->id])
            ->assertStatus(200);

        $this->assertSame($nuevo->id, Task::withoutGlobalScopes()->find($task->id)->assignee_id);
    }
}
