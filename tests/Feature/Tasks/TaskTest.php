<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use Tests\TestCase;

class TaskTest extends TestCase
{
    // =========================================================
    // LISTAR TAREAS - RBAC
    // =========================================================

    public function test_admin_ve_todas_las_tareas_de_su_org(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador1 = $this->createUser($org, 'Operador');
        $operador2 = $this->createUser($org, 'Operador');

        $this->createTask($org, $admin, $operador1, ['title' => 'Tarea A']);
        $this->createTask($org, $admin, $operador2, ['title' => 'Tarea B']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/tasks');

        $response->assertStatus(200);
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertContains('Tarea A', $titles);
        $this->assertContains('Tarea B', $titles);
    }

    public function test_operador_solo_ve_sus_tareas_asignadas(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador1 = $this->createUser($org, 'Operador');
        $operador2 = $this->createUser($org, 'Operador');

        $this->createTask($org, $admin, $operador1, ['title' => 'Mi tarea']);
        $this->createTask($org, $admin, $operador2, ['title' => 'Tarea de otro']);

        $response = $this->actingAs($operador1, 'sanctum')
            ->getJson('/api/v1/tasks');

        $response->assertStatus(200);
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertContains('Mi tarea', $titles);
        $this->assertNotContains('Tarea de otro', $titles);
    }

    public function test_supervisor_ve_sus_tareas_y_las_de_operadores(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $supervisor = $this->createUser($org, 'Supervisor');
        $operador = $this->createUser($org, 'Operador');

        $this->createTask($org, $admin, $supervisor, ['title' => 'Tarea del supervisor']);
        $this->createTask($org, $supervisor, $operador, ['title' => 'Tarea creada por supervisor']);
        $this->createTask($org, $admin, $operador, ['title' => 'Tarea del operador']);

        $response = $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/v1/tasks');

        $response->assertStatus(200);
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertContains('Tarea del supervisor', $titles);
        $this->assertContains('Tarea creada por supervisor', $titles);
        $this->assertContains('Tarea del operador', $titles);
    }

    // =========================================================
    // VER TAREA
    // =========================================================

    public function test_admin_puede_ver_cualquier_tarea_de_su_org(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');
        $tarea = $this->createTask($org, $admin, $operador);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/tasks/{$tarea->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $tarea->id);
    }

    public function test_operador_puede_ver_su_tarea(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');
        $tarea = $this->createTask($org, $admin, $operador);

        $this->actingAs($operador, 'sanctum')
            ->getJson("/api/v1/tasks/{$tarea->id}")
            ->assertStatus(200);
    }

    public function test_operador_no_puede_ver_tarea_de_otro(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador1 = $this->createUser($org, 'Operador');
        $operador2 = $this->createUser($org, 'Operador');
        $tarea = $this->createTask($org, $admin, $operador2);

        $this->actingAs($operador1, 'sanctum')
            ->getJson("/api/v1/tasks/{$tarea->id}")
            ->assertStatus(403);
    }

    // =========================================================
    // CREAR TAREA
    // =========================================================

    public function test_admin_puede_crear_tarea(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/tasks', [
                'title' => 'Nueva tarea',
                'description' => 'Descripción',
                'due_date' => now()->addDays(5)->format('Y-m-d'),
                'due_time' => '14:00',
                'assignee_id' => $operador->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Nueva tarea');

        $this->assertEquals(1, Task::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->count());
    }

    public function test_supervisor_puede_crear_tarea(): void
    {
        $org = $this->createOrganization();
        $supervisor = $this->createUser($org, 'Supervisor');
        $operador = $this->createUser($org, 'Operador');

        $this->actingAs($supervisor, 'sanctum')
            ->postJson('/api/v1/tasks', [
                'title' => 'Tarea del supervisor',
                'due_date' => now()->addDays(5)->format('Y-m-d'),
                'assignee_id' => $operador->id,
            ])->assertStatus(201);
    }

    public function test_operador_no_puede_crear_tarea(): void
    {
        $org = $this->createOrganization();
        $operador = $this->createUser($org, 'Operador');

        $this->actingAs($operador, 'sanctum')
            ->postJson('/api/v1/tasks', [
                'title' => 'Tarea no permitida',
                'due_date' => now()->addDays(5)->format('Y-m-d'),
                'assignee_id' => $operador->id,
            ])->assertStatus(403);
    }

    public function test_crear_tarea_falla_sin_titulo(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/tasks', [
                'due_date' => now()->addDays(5)->format('Y-m-d'),
                'assignee_id' => $operador->id,
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    public function test_crear_tarea_falla_con_fecha_en_el_pasado(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/tasks', [
                'title' => 'Tarea pasada',
                'due_date' => now()->subDays(1)->format('Y-m-d'),
                'assignee_id' => $operador->id,
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['due_date']);
    }

    public function test_no_se_puede_asignar_tarea_a_usuario_de_otra_org(): void
    {
        $org1 = $this->createOrganization();
        $org2 = $this->createOrganization();

        $admin1 = $this->createUser($org1, 'Admin');
        $operador2 = $this->createUser($org2, 'Operador');

        $this->actingAs($admin1, 'sanctum')
            ->postJson('/api/v1/tasks', [
                'title' => 'Tarea cross-org',
                'due_date' => now()->addDays(5)->format('Y-m-d'),
                'assignee_id' => $operador2->id,
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['assignee_id']);
    }

    // =========================================================
    // ACTUALIZAR TAREA - TRANSICIONES DE ESTADO
    // =========================================================

    public function test_admin_puede_actualizar_tarea(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');
        $tarea = $this->createTask($org, $admin, $operador);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/tasks/{$tarea->id}", [
                'title' => 'Título actualizado',
            ])->assertStatus(200)
            ->assertJsonPath('data.title', 'Título actualizado');
    }

    public function test_operador_puede_cambiar_estado_de_su_tarea(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');
        $tarea = $this->createTask($org, $admin, $operador);

        $this->actingAs($operador, 'sanctum')
            ->patchJson("/api/v1/tasks/{$tarea->id}", [
                'status' => 'En Progreso',
            ])->assertStatus(200)
            ->assertJsonPath('data.status', 'En Progreso');
    }

    public function test_transicion_de_estado_invalida_es_rechazada(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');
        $tarea = $this->createTask($org, $admin, $operador, ['status' => 'Pendiente']);

        // No se puede pasar directamente de Pendiente a Completada sin pasar por En Progreso
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/tasks/{$tarea->id}", [
                'status' => 'Por Verificar',
            ])->assertStatus(422);
    }

    // =========================================================
    // ELIMINAR TAREA
    // =========================================================

    public function test_admin_puede_eliminar_tarea(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');
        $tarea = $this->createTask($org, $admin, $operador);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/tasks/{$tarea->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('tasks', ['id' => $tarea->id]);
    }

    public function test_operador_no_puede_eliminar_tarea(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');
        $tarea = $this->createTask($org, $admin, $operador);

        $this->actingAs($operador, 'sanctum')
            ->deleteJson("/api/v1/tasks/{$tarea->id}")
            ->assertStatus(403);
    }

    public function test_supervisor_no_puede_eliminar_tarea(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $supervisor = $this->createUser($org, 'Supervisor');
        $operador = $this->createUser($org, 'Operador');
        $tarea = $this->createTask($org, $admin, $operador);

        $this->actingAs($supervisor, 'sanctum')
            ->deleteJson("/api/v1/tasks/{$tarea->id}")
            ->assertStatus(403);
    }

    // =========================================================
    // FILTROS
    // =========================================================

    public function test_filtrar_tareas_por_status(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');

        $this->createTask($org, $admin, $operador, ['status' => 'Pendiente', 'title' => 'Pendiente']);
        $this->createTask($org, $admin, $operador, ['status' => 'En Progreso', 'title' => 'En Progreso']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/tasks?status=Pendiente');

        $response->assertStatus(200);
        $statuses = collect($response->json('data'))->pluck('status')->unique()->values();
        $this->assertEquals(['Pendiente'], $statuses->toArray());
    }

    public function test_buscar_tareas_por_titulo(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');

        $this->createTask($org, $admin, $operador, ['title' => 'Revisar informe mensual']);
        $this->createTask($org, $admin, $operador, ['title' => 'Llamar al cliente']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/tasks?search=informe');

        $response->assertStatus(200);
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertContains('Revisar informe mensual', $titles);
        $this->assertNotContains('Llamar al cliente', $titles);
    }
}
