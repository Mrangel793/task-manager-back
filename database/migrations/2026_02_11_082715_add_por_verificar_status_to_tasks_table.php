<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SQLite no soporta MODIFY COLUMN — solo ejecutar en MySQL/MariaDB
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE tasks MODIFY COLUMN status ENUM('Pendiente', 'En Progreso', 'Por Verificar', 'Completada', 'Cancelada') DEFAULT 'Pendiente'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE tasks MODIFY COLUMN status ENUM('Pendiente', 'En Progreso', 'Completada', 'Cancelada') DEFAULT 'Pendiente'");
        }
    }
};
