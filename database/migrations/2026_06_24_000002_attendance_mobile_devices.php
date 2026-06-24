<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_mobile_devices')) {
            Schema::create('attendance_mobile_devices', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('organization_id');
                $table->string('device_identifier', 120);
                $table->string('device_label', 120)->nullable();
                $table->string('platform', 32)->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('registered_by')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('organization_id')->references('id')->on('organizations');
                $table->foreign('registered_by')->references('id')->on('users')->nullOnDelete();
                $table->unique(['organization_id', 'device_identifier'], 'uq_org_attendance_mobile_device');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_mobile_devices');
    }
};
