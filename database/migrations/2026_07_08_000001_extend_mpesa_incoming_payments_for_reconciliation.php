<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mpesa_incoming_payments')) {
            return;
        }

        Schema::table('mpesa_incoming_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('mpesa_incoming_payments', 'bill_ref_number')) {
                $table->string('bill_ref_number', 100)->nullable()->after('phone_number');
            }
            if (! Schema::hasColumn('mpesa_incoming_payments', 'payer_name')) {
                $table->string('payer_name', 100)->nullable()->after('bill_ref_number');
            }
            if (! Schema::hasColumn('mpesa_incoming_payments', 'business_short_code')) {
                $table->string('business_short_code', 20)->nullable()->after('payer_name');
            }
            if (! Schema::hasColumn('mpesa_incoming_payments', 'parsed_order_num')) {
                $table->unsignedInteger('parsed_order_num')->nullable()->after('business_short_code');
            }
            if (! Schema::hasColumn('mpesa_incoming_payments', 'parsed_customer_num')) {
                $table->unsignedInteger('parsed_customer_num')->nullable()->after('parsed_order_num');
            }
            if (! Schema::hasColumn('mpesa_incoming_payments', 'applied_sale_id')) {
                $table->unsignedBigInteger('applied_sale_id')->nullable()->after('applied_cart_id');
            }
            if (! Schema::hasColumn('mpesa_incoming_payments', 'applied_invoice_id')) {
                $table->unsignedBigInteger('applied_invoice_id')->nullable()->after('applied_sale_id');
            }
            if (! Schema::hasColumn('mpesa_incoming_payments', 'match_method')) {
                $table->string('match_method', 30)->nullable()->after('applied_invoice_id');
            }
            if (! Schema::hasColumn('mpesa_incoming_payments', 'match_confidence')) {
                $table->string('match_confidence', 20)->nullable()->after('match_method');
            }
            if (! Schema::hasColumn('mpesa_incoming_payments', 'reconciliation_status')) {
                $table->string('reconciliation_status', 20)->default('unmatched')->after('match_confidence');
            }
            if (! Schema::hasColumn('mpesa_incoming_payments', 'matched_by_user_id')) {
                $table->unsignedBigInteger('matched_by_user_id')->nullable()->after('reconciliation_status');
            }
            if (! Schema::hasColumn('mpesa_incoming_payments', 'reconciliation_notes')) {
                $table->text('reconciliation_notes')->nullable()->after('matched_by_user_id');
            }
            if (! Schema::hasColumn('mpesa_incoming_payments', 'matched_at')) {
                $table->timestamp('matched_at')->nullable()->after('applied_at');
            }
        });

        Schema::table('mpesa_incoming_payments', function (Blueprint $table) {
            if (Schema::hasColumn('mpesa_incoming_payments', 'organization_id')
                && Schema::hasColumn('mpesa_incoming_payments', 'reconciliation_status')) {
                $table->index(
                    ['organization_id', 'status', 'reconciliation_status', 'received_at'],
                    'idx_mpesa_org_recon_received',
                );
            }
            if (Schema::hasColumn('mpesa_incoming_payments', 'parsed_order_num')) {
                $table->index(['organization_id', 'parsed_order_num'], 'idx_mpesa_org_order_num');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mpesa_incoming_payments')) {
            return;
        }

        Schema::table('mpesa_incoming_payments', function (Blueprint $table) {
            $table->dropIndex('idx_mpesa_org_recon_received');
            $table->dropIndex('idx_mpesa_org_order_num');
            $table->dropColumn([
                'bill_ref_number',
                'payer_name',
                'business_short_code',
                'parsed_order_num',
                'parsed_customer_num',
                'applied_sale_id',
                'applied_invoice_id',
                'match_method',
                'match_confidence',
                'reconciliation_status',
                'matched_by_user_id',
                'reconciliation_notes',
                'matched_at',
            ]);
        });
    }
};
