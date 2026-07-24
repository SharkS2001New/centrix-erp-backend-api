<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS v_nssf_remittance_report');
        DB::unprepared('DROP VIEW IF EXISTS v_other_deductions_by_period');

        DB::unprepared(<<<'SQL'
CREATE VIEW v_nssf_remittance_report AS
SELECT
    e.organization_id,
    e.branch_id,
    b.branch_name,
    pr.id AS payroll_run_id,
    pp.period_code,
    pp.period_start,
    pp.period_end,
    pr.run_date,
    pr.status AS payroll_status,
    e.id AS employee_id,
    e.employee_code,
    e.last_name AS surname,
    TRIM(CONCAT_WS(' ', e.first_name, e.middle_name)) AS other_names,
    e.national_id AS id_number,
    e.nssf_number,
    pl.gross_pay AS income,
    pl.nssf AS member,
    pl.employer_nssf AS employer,
    ROUND(pl.nssf + pl.employer_nssf, 2) AS total
FROM payroll_lines pl
JOIN payroll_runs pr ON pl.payroll_run_id = pr.id
JOIN pay_periods pp ON pr.pay_period_id = pp.id
JOIN employees e ON pl.employee_id = e.id
LEFT JOIN branches b ON e.branch_id = b.id
WHERE (pl.nssf + pl.employer_nssf) > 0
SQL);

        DB::unprepared(<<<'SQL'
CREATE VIEW v_other_deductions_by_period AS
SELECT
    e.organization_id,
    e.branch_id,
    b.branch_name,
    pr.id AS payroll_run_id,
    pp.period_code,
    pp.period_start,
    pp.period_end,
    pr.run_date,
    pr.status AS payroll_status,
    e.id AS employee_id,
    e.employee_code,
    e.full_name,
    d.department_name,
    jt.deduction_name,
    jt.source_type,
    jt.deduction_scope,
    jt.calc_type,
    jt.frequency,
    jt.amount
FROM payroll_lines pl
JOIN payroll_runs pr ON pl.payroll_run_id = pr.id
JOIN pay_periods pp ON pr.pay_period_id = pp.id
JOIN employees e ON pl.employee_id = e.id
LEFT JOIN departments d ON e.department_id = d.id
LEFT JOIN branches b ON e.branch_id = b.id
JOIN JSON_TABLE(
    COALESCE(pl.statutory_meta, JSON_OBJECT()),
    '$.payroll.deductions_detail[*]'
    COLUMNS (
        deduction_name VARCHAR(200) PATH '$.name',
        source_type VARCHAR(50) PATH '$.type',
        deduction_scope VARCHAR(50) PATH '$.scope',
        calc_type VARCHAR(50) PATH '$.calc_type',
        frequency VARCHAR(50) PATH '$.frequency',
        amount DECIMAL(15, 2) PATH '$.amount'
    )
) AS jt
WHERE jt.amount > 0

UNION ALL

SELECT
    e.organization_id,
    e.branch_id,
    b.branch_name,
    pr.id AS payroll_run_id,
    pp.period_code,
    pp.period_start,
    pp.period_end,
    pr.run_date,
    pr.status AS payroll_status,
    e.id AS employee_id,
    e.employee_code,
    e.full_name,
    d.department_name,
    'Other deductions' AS deduction_name,
    'aggregate' AS source_type,
    NULL AS deduction_scope,
    NULL AS calc_type,
    NULL AS frequency,
    pl.other_deductions AS amount
FROM payroll_lines pl
JOIN payroll_runs pr ON pl.payroll_run_id = pr.id
JOIN pay_periods pp ON pr.pay_period_id = pp.id
JOIN employees e ON pl.employee_id = e.id
LEFT JOIN departments d ON e.department_id = d.id
LEFT JOIN branches b ON e.branch_id = b.id
WHERE pl.other_deductions > 0
  AND COALESCE(
      JSON_LENGTH(JSON_EXTRACT(pl.statutory_meta, '$.payroll.deductions_detail')),
      0
  ) = 0
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS v_nssf_remittance_report');
        DB::unprepared('DROP VIEW IF EXISTS v_other_deductions_by_period');
    }
};
