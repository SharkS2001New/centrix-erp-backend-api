<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_allowances')) {
            Schema::create('employee_allowances', function (Blueprint $table) {
                $table->id();
                $table->integer('employee_id');
                $table->integer('organization_id');
                $table->string('name', 120);
                $table->decimal('amount', 12, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->string('notes', 500)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
                $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
                $table->index(['employee_id', 'is_active']);
            });
        }

        if (Schema::hasColumn('employees', 'monthly_allowance')) {
            $employees = DB::table('employees')
                ->where('monthly_allowance', '>', 0)
                ->get(['id', 'organization_id', 'monthly_allowance']);
            foreach ($employees as $emp) {
                $exists = DB::table('employee_allowances')
                    ->where('employee_id', $emp->id)
                    ->where('name', 'Monthly allowance')
                    ->exists();
                if (! $exists) {
                    DB::table('employee_allowances')->insert([
                        'employee_id' => $emp->id,
                        'organization_id' => $emp->organization_id,
                        'name' => 'Monthly allowance',
                        'amount' => $emp->monthly_allowance,
                        'is_active' => true,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_allowances');
    }
};
