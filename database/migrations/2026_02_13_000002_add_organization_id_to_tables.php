<?php

use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 1: Add nullable organization_id columns
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable()->after('id');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable()->after('id');
        });

        Schema::table('task_histories', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable()->after('id');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable()->after('id');
        });

        // Step 2: Create default organization and backfill existing records
        $defaultOrgId = Str::uuid()->toString();
        DB::table('organizations')->insert([
            'id' => $defaultOrgId,
            'name' => 'Organización Principal',
            'slug' => 'organizacion-principal',
            'invite_code' => strtoupper(Str::random(8)),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->whereNull('organization_id')->update(['organization_id' => $defaultOrgId]);
        DB::table('tasks')->whereNull('organization_id')->update(['organization_id' => $defaultOrgId]);
        DB::table('task_histories')->whereNull('organization_id')->update(['organization_id' => $defaultOrgId]);
        DB::table('notifications')->whereNull('organization_id')->update(['organization_id' => $defaultOrgId]);

        // Step 3: Make columns NOT NULL and add foreign keys + indexes
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable(false)->change();
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('restrict');
            $table->index(['organization_id', 'role', 'is_active']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable(false)->change();
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->index(['organization_id', 'assignee_id', 'status', 'due_date'], 'tasks_org_assignee_status_due_idx');
            $table->index(['organization_id', 'creator_id', 'status'], 'tasks_org_creator_status_idx');
            $table->index(['organization_id', 'status', 'due_date'], 'tasks_org_status_due_idx');
        });

        Schema::table('task_histories', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable(false)->change();
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->index(['organization_id', 'task_id', 'created_at'], 'task_histories_org_task_created_idx');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable(false)->change();
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->index(['organization_id', 'user_id', 'read_at'], 'notifications_org_user_read_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_org_user_read_idx');
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::table('task_histories', function (Blueprint $table) {
            $table->dropIndex('task_histories_org_task_created_idx');
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_org_assignee_status_due_idx');
            $table->dropIndex('tasks_org_creator_status_idx');
            $table->dropIndex('tasks_org_status_due_idx');
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'role', 'is_active']);
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        // Delete the default organization created during migration
        DB::table('organizations')->where('slug', 'organizacion-principal')->delete();
    }
};
