<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Admin user
        $admin = User::create([
            'phone' => '+573001111111',
            'email' => 'admin@taskmanager.local',
            'name' => 'Admin Test',
            'password' => 'Admin123!',
            'role' => 'Admin',
            'is_active' => true,
        ]);
        $admin->assignRole('Admin');

        $this->command->info('Admin user created: ' . $admin->phone);

        // Create Supervisor users
        $supervisors = [
            [
                'phone' => '+573002222222',
                'email' => 'supervisor1@taskmanager.local',
                'name' => 'Supervisor 1',
                'password' => 'Super123!',
            ],
            [
                'phone' => '+573003333333',
                'email' => 'supervisor2@taskmanager.local',
                'name' => 'Supervisor 2',
                'password' => 'Super123!',
            ],
        ];

        foreach ($supervisors as $supervisorData) {
            $supervisor = User::create([
                'phone' => $supervisorData['phone'],
                'email' => $supervisorData['email'],
                'name' => $supervisorData['name'],
                'password' => $supervisorData['password'],
                'role' => 'Supervisor',
                'is_active' => true,
            ]);
            $supervisor->assignRole('Supervisor');

            $this->command->info('Supervisor user created: ' . $supervisor->phone);
        }

        // Create Operador users
        for ($i = 4; $i <= 8; $i++) {
            $operador = User::create([
                'phone' => '+57300444444' . $i,
                'email' => 'operador' . ($i - 3) . '@taskmanager.local',
                'name' => 'Operador ' . ($i - 3),
                'password' => 'Opera123!',
                'role' => 'Operador',
                'is_active' => true,
            ]);
            $operador->assignRole('Operador');

            $this->command->info('Operador user created: ' . $operador->phone);
        }

        $this->command->info('All users created successfully!');
    }
}
