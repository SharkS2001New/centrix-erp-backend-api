<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_kra_receipts_replay');
        DB::statement(<<<'SQL'
CREATE VIEW v_kra_receipts_replay AS
SELECT
    receipts.*,
    DATE(COALESCE(sale.completed_at, sale.created_at)) AS sale_date,
    CASE
        WHEN LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(receipts.request_payload, '$.is_test')), '')) IN ('true', '1')
          OR JSON_UNQUOTE(JSON_EXTRACT(receipts.request_payload, '$.sign_structure.SignType')) = '0'
        THEN 1
        ELSE 0
    END AS is_test,
    CASE
        WHEN JSON_UNQUOTE(JSON_EXTRACT(receipts.response_payload, '$.live_replay_status')) = 'processing'
        THEN 1
        ELSE 0
    END AS is_live_replay_in_progress
FROM v_kra_receipts receipts
INNER JOIN sales sale ON sale.id = receipts.sale_id
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_kra_receipts_replay');
    }
};
