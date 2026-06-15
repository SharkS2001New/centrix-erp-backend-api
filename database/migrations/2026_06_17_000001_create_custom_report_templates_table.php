<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('custom_report_templates')) {
            return;
        }

        Schema::create('custom_report_templates', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->integer('created_by');
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->json('spec');
            $table->boolean('is_shared')->default(false);
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations');
            $table->foreign('created_by')->references('id')->on('users');
            $table->index(['organization_id', 'is_shared']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_report_templates');
    }
};
