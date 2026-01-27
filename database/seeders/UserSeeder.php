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



        $this->command->info('All users created successfully!');
    }
}
