<?php

namespace Tests\Feature\MultiTenancy;

use App\Models\Task;
use App\Models\User;
use Tests\TestCase;

/**
 * Verifica el aislamiento multi-tenancy entre organizaciones.
 * Estos tests cubren el bug donde el Admin veía tareas de otras organizaciones
 * cuando current_organization_id no estaba en el contenedor.
 */
class OrganizationScopeTest extends TestCase
{
    // =========================================================
    // FAIL-CLOSED: sin contexto, los modelos sensibles devuelven vacío
    // =========================================================

    public function test_task_scope_fail_closed_sin_contexto_de_org(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');
        $this->createTask($org, $admin, $operador);

        // Sin contexto → debe devolver vacío (fail-closed)
        app()->instance('current_organization_id', null);

        $this->assertEquals(0, Task::count());

        // Con withoutGlobalScopes sí debe encontrarse
        $this->assertEquals(1, Task::withoutGlobalScopes()->count());
    }

    public function test_user_model_accesible_sin_contexto_de_org(): void
    {
        // User es fail-OPEN porque Sanctum necesita resolver tokens sin contexto
        $org = $this->createOrganization();
        $user = $this->createUser($org, 'Admin');

        app()->instance('current_organization_id', null);

        $found = User::find($user->id);
        $this->assertNotNull($found);
        $this->assertEquals($user->email, $found->email);
    }

    // =========================================================
    // AISLAMIENTO: Admin solo ve su organización
    // =========================================================

    public function test_admin_no_ve_tareas_de_otra_organizacion(): void
    {
        $org1 = $this->createOrganization();
        $org2 = $this->createOrganization();

        $admin1 = $this->createUser($org1, 'Admin');

        $admin2 = $this->createUser($org2, 'Admin');
        $operador2 = $this->createUser($org2, 'Operador');
        $this->createTask($org2, $admin2, $operador2, ['title' => 'Tarea secreta Org2']);

        $response = $this->actingAs($admin1, 'sanctum')
            ->getJson('/api/v1/tasks');

        $response->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertNotContains('Tarea secreta Org2', $titles);
    }

    public function test_admin_ve_solo_tareas_de_su_organizacion(): void
    {
        $org1 = $this->createOrganization();
        $org2 = $this->createOrganization();

        $admin1 = $this->createUser($org1, 'Admin');
        $operador1 = $this->createUser($org1, 'Operador');

        $admin2 = $this->createUser($org2, 'Admin');
        $operador2 = $this->createUser($org2, 'Operador');

        $this->createTask($org1, $admin1, $operador1, ['title' => 'Tarea Org1']);
        $this->createTask($org2, $admin2, $operador2, ['title' => 'Tarea Org2']);

        $response = $this->actingAs($admin1, 'sanctum')
            ->getJson('/api/v1/tasks');

        $response->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertContains('Tarea Org1', $titles);
        $this->assertNotContains('Tarea Org2', $titles);
    }

    // =========================================================
    // AISLAMIENTO: Usuarios solo ven usuarios de su organización
    // =========================================================

    public function test_admin_no_ve_usuarios_de_otra_organizacion(): void
    {
        $org1 = $this->createOrganization();
        $org2 = $this->createOrganization();

        $admin1 = $this->createUser($org1, 'Admin');
        $admin2 = $this->createUser($org2, 'Admin');
        $operador2 = $this->createUser($org2, 'Operador');

        $response = $this->actingAs($admin1, 'sanctum')
            ->getJson('/api/v1/users');

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($admin1->id, $ids);
        $this->assertNotContains($admin2->id, $ids);
        $this->assertNotContains($operador2->id, $ids);
    }

    public function test_operador_de_org1_no_accede_a_tarea_de_org2(): void
    {
        $org1 = $this->createOrganization();
        $org2 = $this->createOrganization();

        $operador1 = $this->createUser($org1, 'Operador');
        $admin2 = $this->createUser($org2, 'Admin');
        $operador2 = $this->createUser($org2, 'Operador');

        $tarea = $this->createTask($org2, $admin2, $operador2);

        $response = $this->actingAs($operador1, 'sanctum')
            ->getJson("/api/v1/tasks/{$tarea->id}");

        // No debe encontrar la tarea (fuera de su organización)
        $response->assertStatus(404);
    }

    // =========================================================
    // MÚLTIPLES ORGANIZACIONES: datos independientes
    // =========================================================

    public function test_dos_organizaciones_tienen_conteos_independientes(): void
    {
        $org1 = $this->createOrganization();
        $org2 = $this->createOrganization();

        $admin1 = $this->createUser($org1, 'Admin');
        $operador1 = $this->createUser($org1, 'Operador');

        $admin2 = $this->createUser($org2, 'Admin');
        $operador2 = $this->createUser($org2, 'Operador');

        // 2 tareas en org1, 3 en org2
        $this->createTask($org1, $admin1, $operador1);
        $this->createTask($org1, $admin1, $operador1);
        $this->createTask($org2, $admin2, $operador2);
        $this->createTask($org2, $admin2, $operador2);
        $this->createTask($org2, $admin2, $operador2);

        $responseOrg1 = $this->actingAs($admin1, 'sanctum')
            ->getJson('/api/v1/tasks?per_page=50');

        $responseOrg2 = $this->actingAs($admin2, 'sanctum')
            ->getJson('/api/v1/tasks?per_page=50');

        $responseOrg1->assertStatus(200);
        $responseOrg2->assertStatus(200);

        $this->assertCount(2, $responseOrg1->json('data'));
        $this->assertCount(3, $responseOrg2->json('data'));
    }
}
