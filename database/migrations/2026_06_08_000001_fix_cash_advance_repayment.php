<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_cash_advances')) {
            return;
        }

        DB::table('employee_cash_advances')
            ->where(function ($q) {
                $q->whereNull('repayment_mode')
                    ->orWhere('repayment_mode', '')
                    ->orWhereNotIn('repayment_mode', ['full_next_cycle', 'fixed_per_cycle']);
            })
            ->update([
                'repayment_mode' => 'full_next_cycle',
                'repayment_amount' => null,
            ]);

        DB::table('employee_cash_advances')
            ->where('repayment_mode', 'fixed_per_cycle')
            ->where(function ($q) {
                $q->whereNull('repayment_amount')->orWhere('repayment_amount', '<=', 0);
            })
            ->update([
                'repayment_mode' => 'full_next_cycle',
                'repayment_amount' => null,
            ]);

        DB::table('employee_cash_advances')
            ->where('status', 'open')
            ->whereColumn('balance', '<', 'amount')
            ->update(['balance' => DB::raw('amount')]);

        if (Schema::hasTable('payroll_run_settlements')) {
            $rows = DB::table('payroll_run_settlements')
                ->where('item_type', 'cash_advance')
                ->orderBy('id')
                ->get();

            $deductedByAdvance = [];
            foreach ($rows as $row) {
                $snapshot = json_decode($row->snapshot ?? '{}', true) ?: [];
                $deduct = (float) ($snapshot['amount_deducted'] ?? 0);
                $deductedByAdvance[$row->item_id] = ($deductedByAdvance[$row->item_id] ?? 0) + $deduct;
            }

            foreach ($deductedByAdvance as $advanceId => $totalDeducted) {
                $advance = DB::table('employee_cash_advances')->where('id', $advanceId)->first();
                if (! $advance) {
                    continue;
                }
                $amount = (float) $advance->amount;
                if ($amount <= 0 || $totalDeducted >= $amount - 0.01) {
                    continue;
                }
                $remaining = round($amount - $totalDeducted, 2);
                DB::table('employee_cash_advances')
                    ->where('id', $advanceId)
                    ->update([
                        'status' => 'open',
                        'balance' => $remaining,
                        'repayment_mode' => 'full_next_cycle',
                        'repayment_amount' => null,
                    ]);
            }
        }
    }

    public function down(): void
    {
        //
    }
};
