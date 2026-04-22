<?php

namespace Tests;

use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function createOrganization(array $attrs = []): Organization
    {
        return Organization::create(array_merge([
            'name' => 'Org ' . uniqid(),
            'is_active' => true,
        ], $attrs));
    }

    protected function createUser(Organization $org, string $role = 'Operador', array $attrs = []): User
    {
        $user = User::create(array_merge([
            'organization_id' => $org->id,
            'name' => 'Usuario Test',
            'email' => uniqid() . '@test.com',
            'password' => 'Password1',
            'phone' => '+57300' . rand(1000000, 9999999),
            'role' => $role,
            'is_active' => true,
        ], $attrs));

        $user->assignRole($role);

        return $user;
    }

    /**
     * Crea una tarea directamente en la base de datos (sin pasar por la API).
     * Se pasa organization_id explícitamente para no depender del contexto del contenedor.
     */
    protected function createTask(Organization $org, User $creator, User $assignee, array $attrs = []): Task
    {
        return Task::create(array_merge([
            'organization_id' => $org->id,
            'title' => 'Tarea de prueba ' . uniqid(),
            'description' => 'Descripción de prueba',
            'status' => 'Pendiente',
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'due_time' => '17:00',
            'assignee_id' => $assignee->id,
            'creator_id' => $creator->id,
        ], $attrs));
    }
}
