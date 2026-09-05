<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('activation_token', 64)->nullable()->after('notification_preferences');
            $table->timestamp('activation_token_expires_at')->nullable()->after('activation_token');

            $table->index('activation_token', 'users_activation_token_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_activation_token_index');
            $table->dropColumn(['activation_token', 'activation_token_expires_at']);
        });
    }
};