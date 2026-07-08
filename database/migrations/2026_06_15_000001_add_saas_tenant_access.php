<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'access_scope')) {
            Schema::table('users', function (Blueprint $table) {
                $table->enum('access_scope', ['org', 'branch'])->default('org')->after('is_admin');
            });
        }

        if (! Schema::hasColumn('roles', 'organization_id')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->integer('organization_id')->nullable()->after('id');
                $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            });
        }

        if ($this->rolesHasLegacyUniqueIndex()) {
            DB::statement('ALTER TABLE roles DROP INDEX role_name');
        }

        if (! $this->rolesHasOrgScopedUniqueIndex()) {
            Schema::table('roles', function (Blueprint $table) {
                $table->unique(['organization_id', 'role_name'], 'uq_org_role_name');
            });
        }

        if (! Schema::hasTable('user_memberships')) {
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
        }

        if (Schema::hasColumn('users', 'access_scope')) {
            DB::table('users')->where('is_admin', 1)->update(['access_scope' => 'org']);
            DB::table('users')->where('is_admin', 0)->update(['access_scope' => 'branch']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_memberships');

        if (Schema::hasColumn('roles', 'organization_id')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropForeign(['organization_id']);
                if ($this->rolesHasOrgScopedUniqueIndex()) {
                    $table->dropUnique('uq_org_role_name');
                }
                $table->dropColumn('organization_id');
            });
        }

        if (! $this->rolesHasLegacyUniqueIndex()) {
            Schema::table('roles', function (Blueprint $table) {
                $table->unique('role_name');
            });
        }

        if (Schema::hasColumn('users', 'access_scope')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('access_scope');
            });
        }
    }

    protected function rolesHasLegacyUniqueIndex(): bool
    {
        $rows = DB::select("SHOW INDEX FROM roles WHERE Key_name = 'role_name'");

        return $rows !== [];
    }

    protected function rolesHasOrgScopedUniqueIndex(): bool
    {
        $rows = DB::select("SHOW INDEX FROM roles WHERE Key_name = 'uq_org_role_name'");

        return $rows !== [];
    }
};
