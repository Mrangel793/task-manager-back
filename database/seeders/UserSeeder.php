<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $demoOrg = Organization::where('slug', 'demo')->firstOrFail();
        $testOrg = Organization::where('slug', 'test')->firstOrFail();

        // Set organization context for global scope
        app()->instance('current_organization_id', $demoOrg->id);

        // === Demo Organization Users ===

        // Create Admin user
        $admin = User::create([
            'organization_id' => $demoOrg->id,
            'phone' => '+573001111111',
            'email' => 'admin@taskmanager.local',
            'name' => 'Admin Test',
            'password' => 'Admin123!',
            'role' => 'Admin',
            'is_active' => true,
        ]);
        $admin->assignRole('Admin');
        $this->command->info('[Demo] Admin user created: ' . $admin->email);

        // Create Supervisor users
        $supervisors = [
            [
                'phone' => '+573002222222',
                'email' => 'supervisor1@taskmanager.local',
                'name' => 'Supervisor Uno',
                'password' => 'Super123!',
            ],
            [
                'phone' => '+573003333333',
                'email' => 'supervisor2@taskmanager.local',
                'name' => 'Supervisor Dos',
                'password' => 'Super123!',
            ],
        ];

        foreach ($supervisors as $supervisorData) {
            $supervisor = User::create([
                'organization_id' => $demoOrg->id,
                'phone' => $supervisorData['phone'],
                'email' => $supervisorData['email'],
                'name' => $supervisorData['name'],
                'password' => $supervisorData['password'],
                'role' => 'Supervisor',
                'is_active' => true,
            ]);
            $supervisor->assignRole('Supervisor');
            $this->command->info('[Demo] Supervisor user created: ' . $supervisor->email);
        }

        // Create Operador users
        for ($i = 1; $i <= 5; $i++) {
            $operador = User::create([
                'organization_id' => $demoOrg->id,
                'phone' => '+57300444444' . ($i + 3),
                'email' => "operador{$i}@taskmanager.local",
                'name' => "Operador {$i}",
                'password' => 'Opera123!',
                'role' => 'Operador',
                'is_active' => true,
            ]);
            $operador->assignRole('Operador');
            $this->command->info('[Demo] Operador user created: ' . $operador->email);
        }

        // === Test Organization Users (to verify isolation) ===

        app()->instance('current_organization_id', $testOrg->id);

        $testAdmin = User::create([
            'organization_id' => $testOrg->id,
            'phone' => '+573009999999',
            'email' => 'admin@testorg.local',
            'name' => 'Admin Test Org',
            'password' => 'Admin123!',
            'role' => 'Admin',
            'is_active' => true,
        ]);
        $testAdmin->assignRole('Admin');
        $this->command->info('[Test] Admin user created: ' . $testAdmin->email);

        $testOperador = User::create([
            'organization_id' => $testOrg->id,
            'phone' => '+573008888888',
            'email' => 'operador@testorg.local',
            'name' => 'Operador Test Org',
            'password' => 'Opera123!',
            'role' => 'Operador',
            'is_active' => true,
        ]);
        $testOperador->assignRole('Operador');
        $this->command->info('[Test] Operador user created: ' . $testOperador->email);

        $this->command->info('All users created successfully!');
    }
}
