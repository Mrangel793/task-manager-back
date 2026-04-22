<?php

namespace Tests\Feature\Users;

use Tests\TestCase;

class UserTest extends TestCase
{
    // =========================================================
    // LISTAR USUARIOS - RBAC
    // =========================================================

    public function test_admin_ve_todos_los_usuarios_de_su_org(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $supervisor = $this->createUser($org, 'Supervisor');
        $operador = $this->createUser($org, 'Operador');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/users');

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($admin->id, $ids);
        $this->assertContains($supervisor->id, $ids);
        $this->assertContains($operador->id, $ids);
    }

    public function test_supervisor_solo_ve_operadores(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $supervisor = $this->createUser($org, 'Supervisor');
        $operador = $this->createUser($org, 'Operador');

        $response = $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/v1/users');

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($operador->id, $ids);
        $this->assertNotContains($admin->id, $ids);
        $this->assertNotContains($supervisor->id, $ids);
    }

    public function test_operador_solo_se_ve_a_si_mismo(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador1 = $this->createUser($org, 'Operador');
        $operador2 = $this->createUser($org, 'Operador');

        $response = $this->actingAs($operador1, 'sanctum')
            ->getJson('/api/v1/users');

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($operador1->id, $ids);
        $this->assertNotContains($operador2->id, $ids);
        $this->assertNotContains($admin->id, $ids);
    }

    // =========================================================
    // VER USUARIO
    // =========================================================

    public function test_admin_puede_ver_cualquier_usuario_de_su_org(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/users/{$operador->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $operador->id);
    }

    // =========================================================
    // CREAR USUARIO (manage-users)
    // =========================================================

    public function test_admin_puede_crear_usuario(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Nuevo Operador',
                'email' => 'nuevo@test.com',
                'phone' => '+573001234567',
                'password' => 'Password1',
                'role' => 'Operador',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'nuevo@test.com',
            'organization_id' => $org->id,
        ]);
    }

    public function test_supervisor_no_puede_crear_usuario(): void
    {
        $org = $this->createOrganization();
        $supervisor = $this->createUser($org, 'Supervisor');

        $this->actingAs($supervisor, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Usuario No Permitido',
                'email' => 'nopermitido@test.com',
                'password' => 'Password1',
                'role' => 'Operador',
            ])->assertStatus(403);
    }

    public function test_operador_no_puede_crear_usuario(): void
    {
        $org = $this->createOrganization();
        $operador = $this->createUser($org, 'Operador');

        $this->actingAs($operador, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Intento No Permitido',
                'email' => 'nopermitido@test.com',
                'password' => 'Password1',
                'role' => 'Operador',
            ])->assertStatus(403);
    }

    // =========================================================
    // ELIMINAR USUARIO (manage-users)
    // =========================================================

    public function test_admin_puede_eliminar_usuario(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/users/{$operador->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('users', ['id' => $operador->id]);
    }

    public function test_supervisor_no_puede_eliminar_usuario(): void
    {
        $org = $this->createOrganization();
        $supervisor = $this->createUser($org, 'Supervisor');
        $operador = $this->createUser($org, 'Operador');

        $this->actingAs($supervisor, 'sanctum')
            ->deleteJson("/api/v1/users/{$operador->id}")
            ->assertStatus(403);
    }

    // =========================================================
    // ACTUALIZAR USUARIO
    // =========================================================

    public function test_admin_puede_actualizar_cualquier_usuario(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/users/{$operador->id}", [
                'name' => 'Nombre Actualizado',
            ])->assertStatus(200)
            ->assertJsonPath('data.name', 'Nombre Actualizado');
    }

    public function test_usuario_puede_actualizarse_a_si_mismo(): void
    {
        $org = $this->createOrganization();
        $operador = $this->createUser($org, 'Operador');

        $this->actingAs($operador, 'sanctum')
            ->patchJson("/api/v1/users/{$operador->id}", [
                'name' => 'Mi Nuevo Nombre',
            ])->assertStatus(200)
            ->assertJsonPath('data.name', 'Mi Nuevo Nombre');
    }

    // =========================================================
    // RUTAS REQUIEREN AUTENTICACIÓN
    // =========================================================

    public function test_listar_usuarios_requiere_autenticacion(): void
    {
        $this->getJson('/api/v1/users')
            ->assertStatus(401);
    }
}
