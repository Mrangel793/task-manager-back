<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('title')->nullable()->after('type');
            $table->text('message')->nullable()->after('title');
            $table->json('data')->nullable()->after('message');
        });

        // Update channel enum to include 'email' and 'in_app'
        DB::statement("ALTER TABLE notifications MODIFY COLUMN channel ENUM('web_push', 'whatsapp', 'email', 'in_app') NOT NULL");

        // Update type enum to include more notification types
        DB::statement("ALTER TABLE notifications MODIFY COLUMN type ENUM('task_assigned', 'task_created', 'task_reminder', 'task_due_soon', 'task_overdue', 'status_changed', 'task_status_changed', 'task_reassigned') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn(['title', 'message', 'data']);
        });

        DB::statement("ALTER TABLE notifications MODIFY COLUMN channel ENUM('web_push', 'whatsapp', 'email') NOT NULL");
        DB::statement("ALTER TABLE notifications MODIFY COLUMN type ENUM('task_assigned', 'task_reminder', 'task_due_soon', 'task_overdue', 'status_changed', 'task_reassigned') NOT NULL");
    }
};
