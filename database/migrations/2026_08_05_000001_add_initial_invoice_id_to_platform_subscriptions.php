<?php

use App\Models\PlatformInvoice;
use App\Models\PlatformSubscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('platform_subscriptions', 'initial_invoice_id')) {
                $table->unsignedBigInteger('initial_invoice_id')->nullable()->after('invoice_id');
                $table->foreign('initial_invoice_id')
                    ->references('id')
                    ->on('platform_invoices')
                    ->nullOnDelete();
            }
        });

        if (! Schema::hasColumn('platform_subscriptions', 'initial_invoice_id')) {
            return;
        }

        PlatformSubscription::query()
            ->whereNotNull('invoice_id')
            ->whereNull('initial_invoice_id')
            ->chunkById(100, function ($subs) {
                foreach ($subs as $sub) {
                    $invoice = PlatformInvoice::query()->find($sub->invoice_id);
                    if (! $invoice) {
                        continue;
                    }

                    $first = (float) ($sub->first_payment_price ?? 0);
                    $renewal = (float) ($sub->renewal_price ?? 0);
                    $total = (float) ($invoice->total ?? 0);

                    if ($first > 0 && abs($total - $first) < 0.01) {
                        $sub->forceFill([
                            'initial_invoice_id' => $invoice->id,
                            'invoice_id' => ($renewal > 0 && abs($total - $renewal) >= 0.01)
                                ? null
                                : $sub->invoice_id,
                        ])->save();

                        continue;
                    }

                    if ($renewal > 0 && abs($total - $renewal) < 0.01) {
                        continue;
                    }

                    $sub->forceFill(['initial_invoice_id' => $invoice->id, 'invoice_id' => null])->save();
                }
            });
    }

    public function down(): void
    {
        Schema::table('platform_subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('platform_subscriptions', 'initial_invoice_id')) {
                $table->dropForeign(['initial_invoice_id']);
                $table->dropColumn('initial_invoice_id');
            }
        });
    }
};
