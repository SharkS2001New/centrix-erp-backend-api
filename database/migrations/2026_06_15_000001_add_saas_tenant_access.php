<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('access_scope', ['org', 'branch'])->default('org')->after('is_admin');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->integer('organization_id')->nullable()->after('id');
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
        });

        // Inline UNIQUE on role_name from schema.sql uses index name `role_name`.
        DB::statement('ALTER TABLE roles DROP INDEX role_name');

        Schema::table('roles', function (Blueprint $table) {
            $table->unique(['organization_id', 'role_name'], 'uq_org_role_name');
        });

        Schema::create('user_memberships', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->integer('organization_id');
            $table->integer('branch_id')->nullable();
            $table->integer('role_id');
            $table->string('username', 50);
            $table->enum('access_scope', ['org', 'branch'])->default('branch');
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('role_id')->references('id')->on('roles');
            $table->unique(['organization_id', 'username'], 'uq_membership_org_username');
            $table->unique(['user_id', 'organization_id'], 'uq_user_org_membership');
        });

        DB::table('users')->where('is_admin', 1)->update(['access_scope' => 'org']);
        DB::table('users')->where('is_admin', 0)->update(['access_scope' => 'branch']);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_memberships');

        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropUnique('uq_org_role_name');
            $table->dropColumn('organization_id');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->unique('role_name');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('access_scope');
        });
    }
};
