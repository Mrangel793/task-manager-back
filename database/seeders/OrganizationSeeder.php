<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $demoOrg = Organization::create([
            'name' => 'Demo Organization',
            'slug' => 'demo',
            'invite_code' => 'DEMO1234',
            'is_active' => true,
        ]);

        $this->command->info('Demo organization created: ' . $demoOrg->name . ' (invite_code: ' . $demoOrg->invite_code . ')');

        $testOrg = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test',
            'invite_code' => 'TEST5678',
            'is_active' => true,
        ]);

        $this->command->info('Test organization created: ' . $testOrg->name . ' (invite_code: ' . $testOrg->invite_code . ')');
    }
}
