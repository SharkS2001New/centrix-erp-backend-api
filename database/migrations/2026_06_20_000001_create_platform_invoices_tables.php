<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_invoices')) {
            Schema::create('platform_invoices', function (Blueprint $table) {
                $table->id();
                $table->string('invoice_number', 40)->unique();
                $table->integer('organization_id')->nullable();
                $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
                $table->string('status', 20)->default('draft');
                $table->string('template_id', 40)->default('modern');
                $table->string('currency', 8)->default('KES');
                $table->date('issue_date');
                $table->date('due_date')->nullable();
                $table->string('bill_to_name', 200)->nullable();
                $table->string('bill_to_email', 200)->nullable();
                $table->string('bill_to_phone', 60)->nullable();
                $table->text('bill_to_address')->nullable();
                $table->string('bill_to_tax_pin', 60)->nullable();
                $table->string('bill_to_company_code', 45)->nullable();
                $table->json('seller')->nullable();
                $table->json('line_items');
                $table->json('selected_modules')->nullable();
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('tax_rate', 6, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->text('notes')->nullable();
                $table->text('terms')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['organization_id', 'status']);
                $table->index('issue_date');
            });
        }

        if (! Schema::hasTable('platform_invoice_saved_templates')) {
            Schema::create('platform_invoice_saved_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('description', 400)->nullable();
                $table->string('template_id', 40)->default('modern');
                $table->json('line_items')->nullable();
                $table->json('selected_modules')->nullable();
                $table->text('notes')->nullable();
                $table->text('terms')->nullable();
                $table->decimal('tax_rate', 6, 2)->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique('name');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoice_saved_templates');
        Schema::dropIfExists('platform_invoices');
    }
};
