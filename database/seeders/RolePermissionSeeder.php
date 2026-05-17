<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view-tasks',
            'create-tasks',
            'edit-tasks',
            'delete-tasks',
            'reassign-tasks',
            'manage-users',
            'view-reports',
            'view-contacts',
            'manage-contacts',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Admin - all permissions
        $adminRole = Role::firstOrCreate(['name' => 'Admin']);
        $adminRole->syncPermissions(Permission::all());

        // Supervisor - tasks + reports (no contacts by default)
        $supervisorRole = Role::firstOrCreate(['name' => 'Supervisor']);
        $supervisorRole->syncPermissions([
            'view-tasks',
            'create-tasks',
            'edit-tasks',
            'view-reports',
        ]);

        // Operador - tasks only
        $operadorRole = Role::firstOrCreate(['name' => 'Operador']);
        $operadorRole->syncPermissions([
            'view-tasks',
            'create-tasks',
            'edit-tasks',
        ]);

        $this->command->info('Roles and permissions created successfully!');
    }
}
