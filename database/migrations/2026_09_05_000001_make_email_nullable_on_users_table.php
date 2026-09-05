<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * StoreUserRequest valida 'email' como nullable (solo 'phone' es obligatorio),
     * pero la columna quedó NOT NULL en 2025_11_15_232924_add_email_to_users_table.
     * Crear un usuario sin correo reventaba con "Column 'email' cannot be null".
     *
     * El índice unique se conserva: MySQL permite múltiples NULL en índices únicos.
     * El login acepta email o teléfono, así que un usuario sin correo puede entrar.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rellenar los NULL antes de restaurar NOT NULL, con el mismo criterio
        // que usó la migración original que introdujo la columna.
        DB::table('users')->whereNull('email')->get()->each(function ($user) {
            $email = 'user' . str_replace(['+', ' ', '-'], '', (string) $user->phone) . '@taskmanager.local';
            DB::table('users')->where('id', $user->id)->update(['email' => $email]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
