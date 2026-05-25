<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Hacer task_id nullable y quitar la foreign key
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
            $table->string('task_id', 8)->nullable()->change();
        });

        // 2. Ampliar el enum type para incluir contact_created
        // MySQL no permite ALTER ENUM directamente con Blueprint en todos los casos,
        // así que usamos una sentencia raw.
        DB::statement("ALTER TABLE notifications MODIFY COLUMN type ENUM(
            'task_assigned',
            'task_reminder',
            'task_due_soon',
            'task_overdue',
            'status_changed',
            'task_reassigned',
            'contact_created'
        ) NOT NULL");

        // 3. Volver a agregar la foreign key como nullable
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreign('task_id')->references('id')->on('tasks')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
            $table->string('task_id', 8)->nullable(false)->change();
        });

        DB::statement("ALTER TABLE notifications MODIFY COLUMN type ENUM(
            'task_assigned',
            'task_reminder',
            'task_due_soon',
            'task_overdue',
            'status_changed',
            'task_reassigned'
        ) NOT NULL");

        Schema::table('notifications', function (Blueprint $table) {
            $table->foreign('task_id')->references('id')->on('tasks')->onDelete('cascade');
        });
    }
};
