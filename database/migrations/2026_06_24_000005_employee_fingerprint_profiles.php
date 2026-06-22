<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_fingerprint_profiles')) {
            Schema::create('employee_fingerprint_profiles', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('employee_id')->unique();
                $table->unsignedInteger('organization_id');
                $table->mediumText('fingerprint_template');
                $table->unsignedSmallInteger('template_size')->default(0);
                $table->string('scanner_model', 120)->nullable();
                $table->timestamp('enrolled_at');
                $table->string('enrolled_device_identifier', 100)->nullable();

                $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
                $table->foreign('organization_id')->references('id')->on('organizations');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_fingerprint_profiles');
    }
};
