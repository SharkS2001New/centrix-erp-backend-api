<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hospitality ops MVP: rate plans, reservation extras, night-audit log, folio charge business_date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hospitality_rate_plans', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained('hospitality_room_types')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->decimal('amount', 14, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'hosp_rate_plan_code_uq');
            $table->index(['organization_id', 'room_type_id', 'is_active'], 'hosp_rate_plan_type_idx');
        });

        Schema::create('hospitality_night_audits', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->date('business_date');
            $table->integer('ran_by')->nullable();
            $table->foreign('ran_by')->references('id')->on('users')->nullOnDelete();
            $table->unsignedInteger('rooms_posted')->default(0);
            $table->decimal('amount_posted', 14, 2)->default(0);
            $table->json('details')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'business_date'], 'hosp_night_audit_date_uq');
        });

        Schema::table('hospitality_reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('hospitality_reservations', 'rate_plan_id')) {
                $table->foreignId('rate_plan_id')->nullable()->after('folio_id')
                    ->constrained('hospitality_rate_plans')->nullOnDelete();
            }
            if (! Schema::hasColumn('hospitality_reservations', 'adults')) {
                $table->unsignedSmallInteger('adults')->default(1)->after('deposit_amount');
            }
            if (! Schema::hasColumn('hospitality_reservations', 'notes')) {
                $table->string('notes', 500)->nullable()->after('adults');
            }
        });

        Schema::table('hospitality_folio_charges', function (Blueprint $table) {
            if (! Schema::hasColumn('hospitality_folio_charges', 'business_date')) {
                $table->date('business_date')->nullable()->after('vat_amount');
                $table->index(['folio_id', 'business_date', 'charge_type'], 'hosp_folio_charge_biz_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hospitality_folio_charges', function (Blueprint $table) {
            if (Schema::hasColumn('hospitality_folio_charges', 'business_date')) {
                $table->dropIndex('hosp_folio_charge_biz_idx');
                $table->dropColumn('business_date');
            }
        });

        Schema::table('hospitality_reservations', function (Blueprint $table) {
            if (Schema::hasColumn('hospitality_reservations', 'rate_plan_id')) {
                $table->dropConstrainedForeignId('rate_plan_id');
            }
            if (Schema::hasColumn('hospitality_reservations', 'adults')) {
                $table->dropColumn('adults');
            }
            if (Schema::hasColumn('hospitality_reservations', 'notes')) {
                $table->dropColumn('notes');
            }
        });

        Schema::dropIfExists('hospitality_night_audits');
        Schema::dropIfExists('hospitality_rate_plans');
    }
};
