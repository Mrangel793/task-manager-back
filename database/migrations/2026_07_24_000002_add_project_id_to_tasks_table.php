<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->uuid('project_id')->nullable()->after('organization_id');
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->index(['organization_id', 'project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropIndex(['organization_id', 'project_id', 'status']);
            $table->dropColumn('project_id');
        });
    }
};
