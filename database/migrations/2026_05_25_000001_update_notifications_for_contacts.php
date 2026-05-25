<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("SET SESSION sql_mode=''");

        // Hacer task_id nullable y ampliar el enum type en una sola sentencia
        DB::statement("ALTER TABLE notifications
            MODIFY COLUMN task_id varchar(8) NULL,
            MODIFY COLUMN type ENUM(
                'task_assigned',
                'task_created',
                'task_reminder',
                'task_due_soon',
                'task_overdue',
                'status_changed',
                'task_status_changed',
                'task_reassigned',
                'contact_created'
            ) NOT NULL
        ");

        DB::statement("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("SET SESSION sql_mode=''");

        DB::statement("ALTER TABLE notifications
            MODIFY COLUMN task_id varchar(8) NOT NULL,
            MODIFY COLUMN type ENUM(
                'task_assigned',
                'task_created',
                'task_reminder',
                'task_due_soon',
                'task_overdue',
                'status_changed',
                'task_status_changed',
                'task_reassigned'
            ) NOT NULL
        ");

        DB::statement("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }
};
