<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $viewContacts   = Permission::firstOrCreate(['name' => 'view-contacts']);
        $manageContacts = Permission::firstOrCreate(['name' => 'manage-contacts']);

        $adminRole = Role::findByName('Admin');
        if ($adminRole) {
            $adminRole->givePermissionTo([$viewContacts, $manageContacts]);
        }
    }

    public function down(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::where('name', 'view-contacts')->delete();
        Permission::where('name', 'manage-contacts')->delete();
    }
};
