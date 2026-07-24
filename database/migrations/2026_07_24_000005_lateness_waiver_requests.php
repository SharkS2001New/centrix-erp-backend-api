<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lateness_waiver_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('employee_attendance_id')->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->date('attendance_date');
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->string('reason', 500)->nullable();
            $table->string('status', 20)->default('pending')->index(); // pending|approved|rejected
            $table->boolean('waive')->default(true); // true = apply waiver, false = undo
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_notes', 500)->nullable();
            $table->unsignedBigInteger('assigned_manager_user_id')->nullable()->index();
            $table->timestamps();

            $table->index(['employee_attendance_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lateness_waiver_requests');
    }
};
