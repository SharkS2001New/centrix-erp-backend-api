<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organization_kpis')) {
            Schema::create('organization_kpis', function (Blueprint $table) {
                $table->id();
                $table->integer('organization_id');
                $table->string('kpi_code', 64)->nullable();
                $table->string('label', 200);
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->decimal('target_value', 12, 2)->nullable();
                $table->string('unit', 32)->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('created_by')->nullable();
                $table->timestamps();

                $table->foreign('organization_id')->references('id')->on('organizations');
                $table->foreign('created_by')->references('id')->on('users');
                $table->index(['organization_id', 'is_active']);
            });
        }

        if (Schema::hasTable('employee_kpis') && ! Schema::hasColumn('employee_kpis', 'organization_kpi_id')) {
            Schema::table('employee_kpis', function (Blueprint $table) {
                $table->unsignedBigInteger('organization_kpi_id')->nullable()->after('employee_id');
                $table->foreign('organization_kpi_id')
                    ->references('id')
                    ->on('organization_kpis')
                    ->nullOnDelete();
                $table->index(['organization_kpi_id', 'employee_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_kpis') && Schema::hasColumn('employee_kpis', 'organization_kpi_id')) {
            Schema::table('employee_kpis', function (Blueprint $table) {
                $table->dropForeign(['organization_kpi_id']);
                $table->dropColumn('organization_kpi_id');
            });
        }

        Schema::dropIfExists('organization_kpis');
    }
};
