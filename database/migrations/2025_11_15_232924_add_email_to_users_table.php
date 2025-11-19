<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if email column already exists
        if (!Schema::hasColumn('users', 'email')) {
            Schema::table('users', function (Blueprint $table) {
                // Add email field as nullable first
                $table->string('email')->nullable()->after('phone');
            });
        }

        // Update existing users to have emails based on their phone (only those without email)
        DB::table('users')->whereNull('email')->get()->each(function ($user) {
            $email = 'user' . str_replace(['+', ' ', '-'], '', $user->phone) . '@taskmanager.local';
            DB::table('users')->where('id', $user->id)->update(['email' => $email]);
        });

        Schema::table('users', function (Blueprint $table) {
            // Now make email unique and required
            $table->string('email')->unique()->nullable(false)->change();

            // Make phone nullable since we'll use email for auth
            $table->string('phone', 20)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Remove email field
            $table->dropColumn('email');

            // Restore phone as required and unique
            $table->string('phone', 20)->nullable(false)->change();
            $table->unique('phone');
        });
    }
};
