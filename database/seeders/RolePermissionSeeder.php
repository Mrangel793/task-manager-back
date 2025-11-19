<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            'view-tasks',
            'create-tasks',
            'edit-tasks',
            'delete-tasks',
            'reassign-tasks', // New permission for reassigning tasks
            'manage-users',
            'view-reports',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Create roles and assign permissions

        // Admin role - all permissions
        $adminRole = Role::firstOrCreate(['name' => 'Admin']);
        $adminRole->syncPermissions(Permission::all());

        // Supervisor role - specific permissions (can create tasks but NOT reassign)
        $supervisorRole = Role::firstOrCreate(['name' => 'Supervisor']);
        $supervisorRole->syncPermissions([
            'view-tasks',
            'create-tasks',
            'edit-tasks',
            'view-reports',
        ]);

        // Operador role - can view, create and edit their own tasks
        $operadorRole = Role::firstOrCreate(['name' => 'Operador']);
        $operadorRole->syncPermissions([
            'view-tasks',
            'create-tasks',
            'edit-tasks', // Operadores pueden editar sus propias tareas
        ]);

        $this->command->info('Roles and permissions created successfully!');
    }
}
