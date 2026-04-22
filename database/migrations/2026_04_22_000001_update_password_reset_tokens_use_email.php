<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La tabla password_reset_tokens usaba 'phone' como PK (auth original por teléfono).
     * Ahora que el auth es por email, se recrea la tabla con 'email' como PK
     * para que el Password broker de Laravel funcione correctamente.
     */
    public function up(): void
    {
        Schema::dropIfExists('password_reset_tokens');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('phone', 20)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }
};
