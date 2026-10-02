<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiToolResultReplyBuilder;
use Tests\TestCase;

class AiToolResultReplyBuilderTest extends TestCase
{
    public function test_formats_payroll_preview_instead_of_tip(): void
    {
        $builder = new AiToolResultReplyBuilder;
        $tip = 'Report these Centrix engine figures — do not invent 22-day/8-hour formulas. '
            .'Explain shift hours, attendance ratio, pays_sha, and that this is a preview (finalize at /hr/payroll). '
            .'Name the person; never use numeric employee id.';

        $reply = $builder->build([
            [
                'name' => 'get_employee_payroll_preview',
                'result' => [
                    'preview' => true,
                    'employee' => ['name' => 'ALEXANDRIA QUINCY', 'username' => 'alexandria'],
                    'shift' => [
                        'name' => 'Office',
                        'start_time' => '08:00',
                        'end_time' => '17:00',
                    ],
                    'contract' => [
                        'basic_salary' => 80000,
                        'pays_sha' => true,
                    ],
                    'period' => [
                        'from_date' => '2026-08-01',
                        'to_date' => '2026-08-31',
                    ],
                    'earnings' => [
                        'expected_work_days' => 22,
                        'paid_work_days' => 20,
                        'period_gross' => 72727.27,
                    ],
                    'statutory' => [
                        'nssf' => 2160,
                        'shif' => 2000,
                        'housing_levy' => 1090.91,
                        'paye' => 9000,
                        'pays_sha' => true,
                    ],
                    'totals' => [
                        'period_gross' => 72727.27,
                        'total_deductions' => 14250.91,
                        'net_pay' => 58476.36,
                    ],
                    'screens' => [
                        ['label' => 'Payroll', 'path' => '/hr/payroll'],
                    ],
                    'tip' => $tip,
                ],
            ],
        ], ['get_employee_payroll_preview']);

        $this->assertStringContainsString('ALEXANDRIA QUINCY', $reply);
        $this->assertStringContainsString('58,476.36', $reply);
        $this->assertStringContainsString('Pays SHA/SHIF', $reply);
        $this->assertStringContainsString('/hr/payroll', $reply);
        $this->assertStringNotContainsString('do not invent', $reply);
        $this->assertStringNotContainsString('never use numeric', $reply);
    }

    public function test_detects_tip_like_model_instruction(): void
    {
        $builder = new AiToolResultReplyBuilder;
        $tip = 'Report these Centrix engine figures — do not invent 22-day/8-hour formulas. '
            .'Name the person; never use numeric employee id.';

        $this->assertTrue($builder->looksLikeModelInstruction($tip));
        $this->assertTrue($builder->looksLikeEchoedToolTip($tip."\n\n[Payroll](/hr/payroll)", [
            ['name' => 'get_employee_payroll_preview', 'result' => ['tip' => $tip]],
        ]));
        $this->assertFalse($builder->looksLikeModelInstruction(
            'ALEXANDRIA QUINCY would take home about KES 58,476.36 this month.'
        ));
    }

    public function test_training_notes_do_not_paste_sample_answer(): void
    {
        $builder = new AiToolResultReplyBuilder;
        $reply = $builder->build([
            [
                'name' => 'search_training_notes',
                'result' => [
                    'notes' => [
                        [
                            'usage' => 'exemplar',
                            'sample_question' => 'How do I run payroll?',
                            'sample_answer_style' => 'Open /hr/payroll and click Generate.',
                            'path' => '/hr/payroll',
                            'content' => 'Open /hr/payroll and click Generate.',
                        ],
                    ],
                    'hint' => 'Treat each note as an EXEMPLAR — do NOT paste sample_answer_style verbatim.',
                ],
            ],
        ]);

        $this->assertStringContainsString('How do I run payroll?', $reply);
        $this->assertStringContainsString('/hr/payroll', $reply);
        $this->assertStringNotContainsString('click Generate', $reply);
        $this->assertStringNotContainsString('do NOT paste', $reply);
    }

    public function test_formats_cashier_sales_and_till_health_together(): void
    {
        $builder = new AiToolResultReplyBuilder;
        $reply = $builder->build([
            [
                'name' => 'get_sales_by_cashier',
                'result' => [
                    'from_date' => '2026-08-26',
                    'to_date' => '2026-08-26',
                    'cashiers' => [
                        [
                            'cashier_name' => 'Jane Doe',
                            'username' => 'jane',
                            'gross_sales' => 125000.5,
                            'transactions' => 18,
                        ],
                    ],
                    'currency' => 'KES',
                ],
            ],
            [
                'name' => 'get_till_health',
                'result' => [
                    'type' => 'cash_till_health',
                    'lookback_days' => 14,
                    'blind_till_close' => false,
                    'sessions' => [['till' => 'Till 1', 'cashier' => 'Jane Doe', 'variance' => 0]],
                    'variance_outliers' => [],
                    'payment_mix' => [
                        'cash' => 40000,
                        'mpesa' => 80000,
                        'bank' => 5000,
                        'order_total' => 125000,
                    ],
                    'screens' => [['label' => 'POS', 'path' => '/sales/pos']],
                ],
            ],
        ], ['get_sales_by_cashier', 'get_till_health']);

        $this->assertStringContainsString('Jane Doe', $reply);
        $this->assertStringContainsString('125,000.50', $reply);
        $this->assertStringContainsString('Cash & till health', $reply);
        $this->assertStringContainsString('M-Pesa', $reply);
        $this->assertStringNotContainsString('could not format a full answer', $reply);
        $this->assertFalse($builder->isGenericFailureReply($reply));
    }

    public function test_formats_run_insight_anomaly_detection(): void
    {
        $builder = new AiToolResultReplyBuilder;
        $reply = $builder->build([
            [
                'name' => 'run_insight',
                'result' => [
                    'insight_type' => 'anomaly_detection',
                    'insight_label' => 'Anomaly detection',
                    'lookback_days' => 7,
                    'avg_order_total' => 12000.5,
                    'large_order_threshold' => 50000,
                    'unusual_large_orders' => [
                        [
                            'order_num' => 501,
                            'order_total' => 98000,
                            'customer' => 'ACME Traders',
                            'channel' => 'backend',
                        ],
                    ],
                    'after_hours_sales' => [],
                    'multi_branch_customers' => [],
                    'deep_discounts' => [
                        [
                            'order_num' => 502,
                            'product_code' => 'SUGAR',
                            'discount_given' => 5000,
                            'line_value' => 15000,
                        ],
                    ],
                    'screens' => [
                        ['label' => 'Sales orders', 'path' => '/sales/orders'],
                    ],
                ],
            ],
        ], ['run_insight']);

        $this->assertStringContainsString('Sales anomalies', $reply);
        $this->assertStringContainsString('ACME Traders', $reply);
        $this->assertStringContainsString('98,000.00', $reply);
        $this->assertStringContainsString('Backoffice', $reply);
        $this->assertStringNotContainsString('| backend |', $reply);
        $this->assertStringNotContainsString('|backend|', $reply);
        $this->assertStringContainsString('Deep discounts', $reply);
        $this->assertStringContainsString('/sales/orders', $reply);
        $this->assertStringNotContainsString('could not format a full answer', $reply);
        $this->assertFalse($builder->isGenericFailureReply($reply));
    }

    public function test_formats_anomaly_detection_when_no_flags(): void
    {
        $builder = new AiToolResultReplyBuilder;
        $reply = $builder->build([
            [
                'name' => 'run_insight',
                'result' => [
                    'insight_type' => 'anomaly_detection',
                    'lookback_days' => 7,
                    'avg_order_total' => 8000,
                    'large_order_threshold' => 50000,
                    'unusual_large_orders' => [],
                    'after_hours_sales' => [],
                    'multi_branch_customers' => [],
                    'deep_discounts' => [],
                ],
            ],
        ], ['run_insight']);

        $this->assertStringContainsString('No unusual large orders', $reply);
        $this->assertFalse($builder->isGenericFailureReply($reply));
    }

    public function test_formats_order_details_with_payments_and_correction_guidance(): void
    {
        $builder = new AiToolResultReplyBuilder;
        $reply = $builder->build([
            [
                'name' => 'get_order_details',
                'result' => [
                    'order_num' => 1537,
                    'order_num_display' => 'S1537',
                    'customer_name' => 'Kilimani Distributors',
                    'payment_status' => 'paid',
                    'order_total' => 12500,
                    'amount_paid' => 12500,
                    'balance_due' => 0,
                    'path' => '/sales/orders/99',
                    'payments' => [
                        [
                            'method_name' => 'M-Pesa',
                            'amount' => 12500,
                            'reference_number' => 'QWER123',
                            'paid_at' => '2026-09-20T10:00:00+03:00',
                        ],
                    ],
                    'correction_guidance' => [
                        'Right customer, wrong amount — reverse then re-apply.',
                    ],
                    'tip' => 'Never say you cannot look up an invoice/order by number.',
                ],
            ],
        ], ['get_order_details']);

        $this->assertStringContainsString('S1537', $reply);
        $this->assertStringContainsString('Kilimani Distributors', $reply);
        $this->assertStringContainsString('12,500.00', $reply);
        $this->assertStringContainsString('M-Pesa', $reply);
        $this->assertStringContainsString('/sales/orders/99', $reply);
        $this->assertStringContainsString('wrong', strtolower($reply));
        $this->assertStringNotContainsString('Never say you cannot', $reply);
        $this->assertFalse($builder->isGenericFailureReply($reply));
    }

    public function test_formats_employee_attendance_org_late_sample(): void
    {
        $builder = new AiToolResultReplyBuilder;
        $reply = $builder->build([
            [
                'name' => 'get_employee_attendance',
                'result' => [
                    'from_date' => '2026-09-01',
                    'to_date' => '2026-09-30',
                    'late_only' => true,
                    'unwaived_only' => true,
                    'summary' => [
                        'records' => 2,
                        'late_or_lateness_records' => 2,
                        'unwaived_late_records' => 2,
                    ],
                    'sample' => [
                        [
                            'name' => 'Jane Doe',
                            'username' => 'jane',
                            'date' => '2026-09-12',
                            'check_in' => '08:42',
                            'late_minutes' => 42,
                            'lunch_late_minutes' => 0,
                            'total_late_minutes' => 42,
                            'lateness_waived' => false,
                            'status' => 'late',
                        ],
                        [
                            'name' => 'John Smith',
                            'username' => 'john',
                            'date' => '2026-09-03',
                            'check_in' => '09:10',
                            'late_minutes' => 70,
                            'lunch_late_minutes' => 15,
                            'total_late_minutes' => 85,
                            'lateness_waived' => false,
                            'status' => 'present',
                        ],
                    ],
                    'screens' => [
                        ['label' => 'Lateness', 'path' => '/hr/lateness'],
                    ],
                    'tip' => 'These rows are late attendance only. Never invent people.',
                ],
            ],
        ], ['get_employee_attendance']);

        $this->assertStringContainsString('Jane Doe', $reply);
        $this->assertStringContainsString('John Smith', $reply);
        $this->assertStringContainsString('42', $reply);
        $this->assertStringContainsString('85', $reply);
        $this->assertStringContainsString('/hr/lateness', $reply);
        $this->assertStringNotContainsString('could not format a full answer', $reply);
        $this->assertStringNotContainsString('Never invent', $reply);
        $this->assertFalse($builder->isGenericFailureReply($reply));
    }

    public function test_formats_named_employee_attendance_without_generic_failure(): void
    {
        $builder = new AiToolResultReplyBuilder;
        $reply = $builder->build([
            [
                'name' => 'get_employee_attendance',
                'result' => [
                    'from_date' => '2026-09-01',
                    'to_date' => '2026-09-30',
                    'employee' => ['name' => 'Ada Attendance', 'username' => 'ada'],
                    'days' => [
                        [
                            'date' => '2026-09-10',
                            'check_in' => '08:05',
                            'late_minutes' => 5,
                            'lunch_late_minutes' => 0,
                            'total_late_minutes' => 5,
                            'status' => 'present',
                        ],
                    ],
                    'summary' => [
                        'records' => 1,
                        'late_or_lateness_records' => 1,
                    ],
                    'screens' => [
                        ['label' => "Today's attendance", 'path' => '/hr/attendance'],
                    ],
                    'tip' => 'Describe clock-in using the employee name — never an id.',
                ],
            ],
        ], ['get_employee_attendance']);

        $this->assertStringContainsString('Ada Attendance', $reply);
        $this->assertStringContainsString('2026-09-10', $reply);
        $this->assertStringNotContainsString('could not format a full answer', $reply);
        $this->assertFalse($builder->isGenericFailureReply($reply));
    }
}
