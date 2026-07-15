<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair sales.customer_name_override values that were copied from another
 * organization's customer sharing the same customer_num.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL: fix overrides that match a different org's customer with the same number.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
UPDATE sales s
INNER JOIN customers own
    ON own.organization_id = s.organization_id
   AND own.customer_num = s.customer_num
   AND own.deleted_at IS NULL
INNER JOIN customers other
    ON other.customer_num = s.customer_num
   AND other.organization_id <> s.organization_id
   AND other.deleted_at IS NULL
   AND other.customer_name = s.customer_name_override
SET s.customer_name_override = own.customer_name
WHERE s.customer_num IS NOT NULL
  AND s.customer_name_override IS NOT NULL
  AND TRIM(s.customer_name_override) <> ''
  AND TRIM(own.customer_name) <> ''
  AND s.customer_name_override <> own.customer_name
SQL);

            return;
        }

        $rows = DB::table('sales as s')
            ->join('customers as own', function ($join) {
                $join->on('own.organization_id', '=', 's.organization_id')
                    ->on('own.customer_num', '=', 's.customer_num')
                    ->whereNull('own.deleted_at');
            })
            ->whereNotNull('s.customer_num')
            ->whereNotNull('s.customer_name_override')
            ->where('s.customer_name_override', '<>', '')
            ->whereColumn('s.customer_name_override', '<>', 'own.customer_name')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('customers as other')
                    ->whereColumn('other.customer_num', 's.customer_num')
                    ->whereColumn('other.organization_id', '<>', 's.organization_id')
                    ->whereColumn('other.customer_name', 's.customer_name_override')
                    ->whereNull('other.deleted_at');
            })
            ->select('s.id', 'own.customer_name')
            ->get();

        foreach ($rows as $row) {
            DB::table('sales')->where('id', $row->id)->update([
                'customer_name_override' => $row->customer_name,
            ]);
        }
    }

    public function down(): void
    {
        // Irreversible data repair.
    }
};
