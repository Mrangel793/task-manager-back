<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * UserController::sendWelcomeWhatsApp crea notificaciones con type = 'user_welcome',
     * pero ese valor nunca se agregó al ENUM de la columna. El INSERT fallaba y el error
     * quedaba tragado por el try/catch del método: el usuario se creaba y el WhatsApp
     * de bienvenida jamás se enviaba, sin señal visible en la interfaz.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("SET SESSION sql_mode=''");

        DB::statement("ALTER TABLE notifications
            MODIFY COLUMN type ENUM(
                'task_assigned',
                'task_created',
                'task_reminder',
                'task_due_soon',
                'task_overdue',
                'status_changed',
                'task_status_changed',
                'task_reassigned',
                'contact_created',
                'user_welcome'
            ) NOT NULL
        ");

        DB::statement("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Las notificaciones de bienvenida ya existentes no caben en el enum anterior.
        DB::table('notifications')->where('type', 'user_welcome')->delete();

        DB::statement("SET SESSION sql_mode=''");

        DB::statement("ALTER TABLE notifications
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
};
