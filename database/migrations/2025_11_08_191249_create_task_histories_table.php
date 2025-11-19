<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('task_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('task_id', 8);
            $table->uuid('user_id');
            $table->enum('action', ['created', 'status_changed', 'assigned', 'reassigned', 'updated', 'deleted']);
            $table->json('from_value')->nullable();
            $table->json('to_value')->nullable();
            $table->timestamp('created_at');

            // Foreign keys
            $table->foreign('task_id')->references('id')->on('tasks')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Índices
            $table->index(['task_id', 'created_at']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_histories');
    }
};
