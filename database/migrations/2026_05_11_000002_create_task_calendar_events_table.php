<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->string('task_id', 8);
            $table->uuid('user_id');
            $table->string('google_event_id');
            $table->string('google_calendar_id')->default('primary');
            $table->timestamps();

            $table->unique(['task_id', 'user_id']);
            $table->index('task_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_calendar_events');
    }
};
