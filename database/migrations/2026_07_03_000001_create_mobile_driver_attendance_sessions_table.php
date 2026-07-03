<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mobile_driver_attendance_sessions')) {
            return;
        }

        Schema::create('mobile_driver_attendance_sessions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('organization_id');
            $table->integer('branch_id')->nullable();
            $table->integer('user_id');
            $table->integer('driver_id');
            $table->dateTime('sign_in_at');
            $table->dateTime('sign_out_at')->nullable();
            $table->dateTime('suspended_at')->nullable();
            $table->dateTime('last_resumed_at')->nullable();
            $table->unsignedInteger('accumulated_work_seconds')->default(0);
            $table->unsignedInteger('accumulated_suspended_seconds')->default(0);
            $table->string('close_reason', 50)->nullable();
            $table->decimal('sign_in_latitude', 10, 7);
            $table->decimal('sign_in_longitude', 10, 7);
            $table->decimal('sign_out_latitude', 10, 7)->nullable();
            $table->decimal('sign_out_longitude', 10, 7)->nullable();
            $table->string('sign_in_address', 500)->nullable();
            $table->string('sign_out_address', 500)->nullable();
            $table->string('sign_in_photo_path', 255)->nullable();
            $table->string('sign_out_photo_path', 255)->nullable();
            $table->string('device_identifier', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations');
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('driver_id')->references('id')->on('drivers')->cascadeOnDelete();
            $table->index(['user_id', 'sign_out_at'], 'idx_mobile_driver_attendance_open');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_driver_attendance_sessions');
    }
};
