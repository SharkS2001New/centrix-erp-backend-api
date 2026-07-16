<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hospitality domain tables — intentionally separate from retail sales /
 * temporary_carts. Shared FKs only: organizations, branches, products, users,
 * payment_methods, vats.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hospitality_outlets', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->integer('branch_id')->nullable();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('outlet_type', 32)->default('bar'); // bar|restaurant|other
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'hosp_org_code_uq');
            $table->index(['organization_id', 'outlet_type'], 'hosp_org_outlet_type_idx');
        });

        Schema::create('hospitality_floor_tables', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained('hospitality_outlets')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('label', 80);
            $table->unsignedSmallInteger('seats')->default(4);
            $table->string('zone', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'code'], 'hosp_outlet_code_uq');
        });

        Schema::create('hospitality_room_types', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->decimal('base_rate', 14, 2)->default(0);
            $table->unsignedSmallInteger('max_occupancy')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'hosp_org_code_uq');
        });

        Schema::create('hospitality_rooms', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->integer('branch_id')->nullable();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreignId('room_type_id')->constrained('hospitality_room_types')->cascadeOnDelete();
            $table->string('room_number', 40);
            $table->string('floor', 40)->nullable();
            $table->string('status', 32)->default('vacant'); // vacant|occupied|dirty|clean|ooo
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'room_number'], 'hosp_org_room_no_uq');
            $table->index(['organization_id', 'status'], 'hosp_org_status_idx');
        });

        Schema::create('hospitality_folios', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->integer('branch_id')->nullable();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('hospitality_rooms')->nullOnDelete();
            $table->string('folio_number', 40);
            $table->string('guest_name', 160);
            $table->string('guest_phone', 40)->nullable();
            $table->string('status', 32)->default('open'); // open|checked_out|void
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('checked_out_at')->nullable();
            $table->integer('opened_by')->nullable();
            $table->foreign('opened_by')->references('id')->on('users')->nullOnDelete();
            $table->decimal('balance', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'folio_number'], 'hosp_org_folio_no_uq');
            $table->index(['organization_id', 'status'], 'hosp_org_status_idx');
        });

        Schema::create('hospitality_reservations', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->integer('branch_id')->nullable();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreignId('room_type_id')->nullable()->constrained('hospitality_room_types')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('hospitality_rooms')->nullOnDelete();
            $table->foreignId('folio_id')->nullable()->constrained('hospitality_folios')->nullOnDelete();
            $table->string('confirmation_code', 40);
            $table->string('guest_name', 160);
            $table->string('guest_phone', 40)->nullable();
            $table->date('arrival_date');
            $table->date('departure_date');
            $table->string('status', 32)->default('booked'); // booked|checked_in|cancelled|no_show
            $table->decimal('deposit_amount', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'confirmation_code'], 'hosp_org_confirm_uq');
            $table->index(['organization_id', 'arrival_date', 'status'], 'hosp_org_arrival_status_idx');
        });

        // F&B checks — NOT sales / temporary_carts
        Schema::create('hospitality_checks', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->integer('branch_id')->nullable();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreignId('outlet_id')->constrained('hospitality_outlets')->cascadeOnDelete();
            $table->foreignId('floor_table_id')->nullable()->constrained('hospitality_floor_tables')->nullOnDelete();
            $table->foreignId('folio_id')->nullable()->constrained('hospitality_folios')->nullOnDelete();
            $table->string('check_number', 40);
            $table->string('status', 32)->default('open'); // open|held|settled|void|posted_to_folio
            $table->string('service_mode', 32)->default('counter'); // counter|table|room_service|takeaway
            $table->integer('opened_by')->nullable();
            $table->foreign('opened_by')->references('id')->on('users')->nullOnDelete();
            $table->integer('closed_by')->nullable();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('vat_total', 14, 2)->default(0);
            $table->decimal('service_charge', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'check_number'], 'hosp_org_check_no_uq');
            $table->index(['organization_id', 'status', 'outlet_id'], 'hosp_org_status_outlet_idx');
        });

        Schema::create('hospitality_check_lines', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreignId('check_id')->constrained('hospitality_checks')->cascadeOnDelete();
            $table->integer('product_id')->nullable();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->string('product_code', 64)->nullable();
            $table->string('description', 255);
            $table->decimal('qty', 14, 4)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->integer('vat_id')->nullable();
            $table->foreign('vat_id')->references('id')->on('vats')->nullOnDelete();
            $table->json('modifiers')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['check_id']);
        });

        Schema::create('hospitality_check_payments', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreignId('check_id')->constrained('hospitality_checks')->cascadeOnDelete();
            $table->integer('payment_method_id')->nullable();
            $table->foreign('payment_method_id')->references('id')->on('payment_methods')->nullOnDelete();
            $table->string('method_code', 40)->nullable(); // CASH|MPESA|CARD|ROOM|…
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('reference', 120)->nullable();
            $table->integer('received_by')->nullable();
            $table->foreign('received_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['check_id']);
        });

        Schema::create('hospitality_folio_charges', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreignId('folio_id')->constrained('hospitality_folios')->cascadeOnDelete();
            $table->foreignId('check_id')->nullable()->constrained('hospitality_checks')->nullOnDelete();
            $table->string('charge_type', 40)->default('other'); // room|fnb|minibar|laundry|other
            $table->string('description', 255);
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->integer('posted_by')->nullable();
            $table->foreign('posted_by')->references('id')->on('users')->nullOnDelete();
            $table->dateTime('posted_at')->nullable();
            $table->timestamps();

            $table->index(['folio_id', 'charge_type'], 'hosp_folio_charge_type_idx');
        });

        Schema::create('hospitality_folio_payments', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreignId('folio_id')->constrained('hospitality_folios')->cascadeOnDelete();
            $table->integer('payment_method_id')->nullable();
            $table->foreign('payment_method_id')->references('id')->on('payment_methods')->nullOnDelete();
            $table->string('method_code', 40)->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('reference', 120)->nullable();
            $table->integer('received_by')->nullable();
            $table->foreign('received_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['folio_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hospitality_folio_payments');
        Schema::dropIfExists('hospitality_folio_charges');
        Schema::dropIfExists('hospitality_check_payments');
        Schema::dropIfExists('hospitality_check_lines');
        Schema::dropIfExists('hospitality_checks');
        Schema::dropIfExists('hospitality_reservations');
        Schema::dropIfExists('hospitality_folios');
        Schema::dropIfExists('hospitality_rooms');
        Schema::dropIfExists('hospitality_room_types');
        Schema::dropIfExists('hospitality_floor_tables');
        Schema::dropIfExists('hospitality_outlets');
    }
};
