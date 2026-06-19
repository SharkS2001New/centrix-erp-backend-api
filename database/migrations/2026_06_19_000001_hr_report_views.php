<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $views = [
            'v_statutory_deductions',
            'v_bank_transfer_report',
            'v_headcount_report',
            'v_contract_expiry',
            'v_staff_turnover',
            'v_hr_dashboard_kpi',
        ];

        foreach ($views as $view) {
            DB::unprepared('DROP VIEW IF EXISTS '.$view);
        }

        DB::unprepared(<<<'SQL'
CREATE VIEW v_statutory_deductions AS
SELECT
    e.organization_id,
    e.branch_id,
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
    pl.gross_pay,
    pl.taxable_income,
    pl.nssf,
    pl.shif,
    pl.housing_levy,
    pl.paye,
    pl.other_deductions,
    pl.deductions AS total_deductions,
    pl.net_pay,
    pl.employer_nssf,
    pl.employer_housing
FROM payroll_lines pl
JOIN payroll_runs pr ON pl.payroll_run_id = pr.id
JOIN pay_periods pp ON pr.pay_period_id = pp.id
JOIN employees e ON pl.employee_id = e.id
LEFT JOIN departments d ON e.department_id = d.id
SQL);

        DB::unprepared(<<<'SQL'
CREATE VIEW v_bank_transfer_report AS
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
    eba.bank_name,
    eba.bank_branch,
    eba.account_number,
    eba.account_name,
    eba.payment_method,
    pl.net_pay
FROM payroll_lines pl
JOIN payroll_runs pr ON pl.payroll_run_id = pr.id
JOIN pay_periods pp ON pr.pay_period_id = pp.id
JOIN employees e ON pl.employee_id = e.id
JOIN employee_bank_accounts eba ON eba.employee_id = e.id AND eba.is_primary = 1
LEFT JOIN branches b ON e.branch_id = b.id
WHERE eba.payment_method = 'bank_transfer' AND pl.net_pay > 0
SQL);

        DB::unprepared(<<<'SQL'
CREATE VIEW v_headcount_report AS
SELECT
    e.organization_id,
    e.branch_id,
    b.branch_name,
    e.department_id,
    d.department_name,
    e.id AS employee_id,
    e.employee_code,
    e.full_name,
    e.employment_status,
    e.employment_type,
    e.job_title,
    e.hire_date,
    e.base_salary,
    e.is_active
FROM employees e
LEFT JOIN departments d ON e.department_id = d.id
LEFT JOIN branches b ON e.branch_id = b.id
SQL);

        DB::unprepared(<<<'SQL'
CREATE VIEW v_contract_expiry AS
SELECT
    e.organization_id,
    e.branch_id,
    b.branch_name,
    e.id AS employee_id,
    e.employee_code,
    e.full_name,
    e.employment_type,
    e.employment_status,
    e.contract_start_date,
    e.contract_end_date,
    DATEDIFF(e.contract_end_date, CURDATE()) AS days_until_expiry,
    d.department_name,
    e.job_title
FROM employees e
LEFT JOIN departments d ON e.department_id = d.id
LEFT JOIN branches b ON e.branch_id = b.id
WHERE e.contract_end_date IS NOT NULL
  AND e.employment_status = 'active'
SQL);

        DB::unprepared(<<<'SQL'
CREATE VIEW v_staff_turnover AS
SELECT
    e.organization_id,
    e.branch_id,
    b.branch_name,
    e.department_id,
    d.department_name,
    SUM(CASE WHEN e.employment_status = 'active' AND e.is_active = 1 THEN 1 ELSE 0 END) AS active_employees,
    SUM(CASE WHEN e.employment_status IN ('terminated', 'retired') THEN 1 ELSE 0 END) AS separated_employees,
    COUNT(*) AS total_employees,
    ROUND(
        100.0 * SUM(CASE WHEN e.employment_status IN ('terminated', 'retired') THEN 1 ELSE 0 END)
        / NULLIF(COUNT(*), 0),
        2
    ) AS turnover_rate_pct
FROM employees e
LEFT JOIN departments d ON e.department_id = d.id
LEFT JOIN branches b ON e.branch_id = b.id
GROUP BY e.organization_id, e.branch_id, b.branch_name, e.department_id, d.department_name
SQL);

        DB::unprepared(<<<'SQL'
CREATE VIEW v_hr_dashboard_kpi AS
SELECT
    o.id AS organization_id,
    (SELECT COUNT(*) FROM employees emp WHERE emp.organization_id = o.id) AS total_employees,
    (SELECT COUNT(*) FROM employees emp WHERE emp.organization_id = o.id AND emp.is_active = 1 AND emp.employment_status = 'active') AS active_employees,
    (SELECT COUNT(*) FROM departments dep WHERE dep.organization_id = o.id AND dep.is_active = 1) AS department_count,
    (SELECT COALESCE(SUM(emp.base_salary), 0) FROM employees emp WHERE emp.organization_id = o.id AND emp.is_active = 1 AND emp.employment_status = 'active') AS monthly_base_payroll,
    (SELECT COUNT(*) FROM employees emp WHERE emp.organization_id = o.id AND emp.contract_end_date IS NOT NULL AND emp.contract_end_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND emp.employment_status = 'active') AS contracts_expiring_90d,
    (SELECT COUNT(*) FROM employees emp WHERE emp.organization_id = o.id AND emp.employment_status IN ('terminated', 'retired')) AS separated_employees,
    (SELECT COUNT(*) FROM payroll_runs pr JOIN pay_periods pp ON pr.pay_period_id = pp.id WHERE pp.organization_id = o.id AND pr.status IN ('processed', 'paid')) AS processed_payroll_runs
FROM organizations o
SQL);
    }

    public function down(): void
    {
        foreach ([
            'v_hr_dashboard_kpi',
            'v_staff_turnover',
            'v_contract_expiry',
            'v_headcount_report',
            'v_bank_transfer_report',
            'v_statutory_deductions',
        ] as $view) {
            DB::unprepared('DROP VIEW IF EXISTS '.$view);
        }
    }
};
