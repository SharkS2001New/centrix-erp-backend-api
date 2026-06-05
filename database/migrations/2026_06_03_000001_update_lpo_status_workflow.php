<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE lpo_mst SET lpo_status_code = CASE lpo_status_code
            WHEN 5 THEN 6
            WHEN 4 THEN 5
            WHEN 3 THEN 4
            WHEN 2 THEN 3
            WHEN 1 THEN 2
            WHEN 0 THEN 1
            ELSE lpo_status_code
        END WHERE deleted_at IS NULL');

        $statuses = [
            0 => 'Pending – Awaiting LPO To be Checked',
            1 => 'Pending – Awaiting Approval',
            2 => 'Awaiting to be Sent to Supplier',
            3 => 'Awaiting Items to be Received',
            4 => 'Awaiting Last Items to be Received',
            5 => 'Items Fully Received',
            6 => 'LPO Cleared',
        ];

        foreach ($statuses as $code => $name) {
            $exists = DB::table('lpo_statuses')->where('status_code', $code)->exists();
            if ($exists) {
                DB::table('lpo_statuses')->where('status_code', $code)->update(['status_name' => $name]);
            } else {
                DB::table('lpo_statuses')->insert([
                    'status_code' => $code,
                    'status_name' => $name,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::statement('UPDATE lpo_mst SET lpo_status_code = CASE lpo_status_code
            WHEN 6 THEN 5
            WHEN 5 THEN 4
            WHEN 4 THEN 3
            WHEN 3 THEN 2
            WHEN 2 THEN 1
            WHEN 1 THEN 0
            ELSE lpo_status_code
        END WHERE deleted_at IS NULL');

        $legacy = [
            0 => 'Pending Approval',
            1 => 'Not Sent',
            2 => 'Pending Received',
            3 => 'Partially Received',
            4 => 'Fully Received',
            5 => 'Cleared',
        ];

        foreach ($legacy as $code => $name) {
            DB::table('lpo_statuses')->where('status_code', $code)->update(['status_name' => $name]);
        }

        DB::table('lpo_statuses')->where('status_code', 6)->delete();
    }
};
