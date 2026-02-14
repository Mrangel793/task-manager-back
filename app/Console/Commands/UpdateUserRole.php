<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class UpdateUserRole extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:update-role {phone} {role}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update user role by phone number';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $phone = $this->argument('phone');
        $role = $this->argument('role');

        $user = User::withoutGlobalScopes()->where('phone', $phone)->first();

        if (!$user) {
            $this->error("User with phone {$phone} not found!");
            return Command::FAILURE;
        }

        // Remove all current roles
        $user->syncRoles([]);

        // Assign new role
        $user->assignRole($role);
        $user->update(['role' => $role]);

        $this->info("User {$user->name} ({$user->phone}) updated to role: {$role}");
        $this->info("Permissions: " . $user->getAllPermissions()->pluck('name')->implode(', '));

        return Command::SUCCESS;
    }
}
