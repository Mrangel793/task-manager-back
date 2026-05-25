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
        // Desactivar strict mode temporalmente para evitar warning 1265 en MySQL
        DB::statement("SET SESSION sql_mode=''");
        DB::statement("ALTER TABLE notifications MODIFY COLUMN type ENUM(
            'task_assigned',
            'task_reminder',
            'task_due_soon',
            'task_overdue',
            'status_changed',
            'task_reassigned',
            'contact_created'
        ) NOT NULL");
        DB::statement("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

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

        DB::statement("SET SESSION sql_mode=''");
        DB::statement("ALTER TABLE notifications MODIFY COLUMN type ENUM(
            'task_assigned',
            'task_reminder',
            'task_due_soon',
            'task_overdue',
            'status_changed',
            'task_reassigned'
        ) NOT NULL");
        DB::statement("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        Schema::table('notifications', function (Blueprint $table) {
            $table->foreign('task_id')->references('id')->on('tasks')->onDelete('cascade');
        });
    }
};
