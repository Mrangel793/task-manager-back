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
        DB::statement("ALTER TABLE tasks MODIFY COLUMN status ENUM('Pendiente', 'En Progreso', 'Por Verificar', 'Completada', 'Cancelada') DEFAULT 'Pendiente'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE tasks MODIFY COLUMN status ENUM('Pendiente', 'En Progreso', 'Completada', 'Cancelada') DEFAULT 'Pendiente'");
    }
};
