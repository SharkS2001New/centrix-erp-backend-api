<?php

namespace App\Services\Ai;

/**
 * Build a user-facing reply from tool JSON when the model returns empty text
 * (or only echoes internal tip/hint instructions).
 */
class AiToolResultReplyBuilder
{
    /**
     * @param  list<array{id?: string, name?: string, result?: array<string, mixed>}>  $toolResults
     * @param  list<string>  $toolsUsed
     */
    public function build(array $toolResults, array $toolsUsed = []): string
    {
        $parts = [];

        foreach ($toolResults as $row) {
            $name = (string) ($row['name'] ?? '');
            $result = is_array($row['result'] ?? null) ? $row['result'] : [];
            if ($result === []) {
                continue;
            }

            if (! empty($result['near_miss']) && ! empty($result['message'])) {
                return AiNearMissHelper::appendScreens(
                    (string) $result['message'],
                    is_array($result['screens'] ?? null) ? $result['screens'] : null,
                );
            }

            if (! empty($result['error'])) {
                if (! empty($result['message'])) {
                    $parts[] = AiNearMissHelper::appendScreens(
                        (string) $result['message'],
                        is_array($result['screens'] ?? null) ? $result['screens'] : null,
                    );

                    continue;
                }
                $parts[] = 'I could not load that Centrix data with your current permissions.';

                continue;
            }

            $formatted = match ($name) {
                'get_employee_payroll_preview' => $this->formatPayrollPreview($result),
                'get_employee_details' => $this->formatEmployeeDetails($result),
                'get_customer_statement' => $this->formatCustomerStatement($result),
                'get_supplier_statement' => $this->formatSupplierStatement($result),
                'get_sales_by_product' => $this->formatSalesByProduct($result),
                'get_sales_by_cashier' => $this->formatSalesByCashier($result),
                'get_till_health' => $this->formatTillHealth($result),
                'get_route_orders' => $this->formatRouteOrders($result),
                'get_expense_summary' => $this->formatExpenseSummary($result),
                'get_customer_returns' => $this->formatCustomerReturns($result),
                'get_product_price_history' => $this->formatProductPriceHistory($result),
                'get_vat_collected' => $this->formatVatCollected($result),
                'get_user_details' => $this->formatUserDetails($result),
                'get_route_details' => $this->formatRouteDetails($result),
                'search_training_notes' => $this->formatTrainingNotes($result),
                'find_screen' => $this->formatFindScreen($result),
                'get_lpo_details' => $this->formatLpoDetails($result),
                default => null,
            };

            if (is_string($formatted) && trim($formatted) !== '') {
                $parts[] = $this->appendPrimaryScreen($formatted, $result);

                continue;
            }

            // Prefer an explicit user message over tip/hint (model instructions).
            if (! empty($result['message']) && is_string($result['message']) && ! $this->looksLikeModelInstruction((string) $result['message'])) {
                $parts[] = $this->appendPrimaryScreen(trim((string) $result['message']), $result);

                continue;
            }

            if (! empty($result['path']) && is_string($result['path'])) {
                $parts[] = 'Open ['.($result['path']).']('.($result['path']).').';
            }
        }

        $parts = array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
        if ($parts !== []) {
            return implode("\n\n", $parts);
        }

        if ($toolsUsed !== []) {
            return 'I loaded Centrix data ('.implode(', ', array_unique($toolsUsed))
                .') but could not format a full answer. Please ask again.';
        }

        return 'I could not generate a response from Centrix data. Please try rephrasing your question.';
    }

    /** True when the built reply is the last-resort generic failure (not real data). */
    public function isGenericFailureReply(string $reply): bool
    {
        return str_contains($reply, 'could not format a full answer')
            || str_contains($reply, 'could not generate a response from Centrix data');
    }

    /**
     * True when assistant text is tip/hint language meant for the model, not the user.
     */
    public function looksLikeModelInstruction(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        return (bool) preg_match(
            '/\b('
            .'do not invent|never invent|never use numeric|report these centrix|'
            .'sample_answer_style|usage\s*=\s*exemplar|write a fresh answer|'
            .'do not paste|do NOT paste|treat each note as an exemplar|'
            .'answer with the (customer|employee|supplier|user)|'
            .'name the person; never|finalize at \/hr\/payroll|'
            .'copy the thinking\/approach|'
            .'not per salesperson|organization level \(by category\)|aren\'?t attributed|'
            .'no .*expenses.*figure|expenses like utilities aren\'?t'
            .')\b/i',
            $trimmed,
        );
    }

    /**
     * True when the model echoed a tool tip/hint verbatim (or tip + screen link).
     *
     * @param  list<array{id?: string, name?: string, result?: array<string, mixed>}>  $toolResults
     */
    public function looksLikeEchoedToolTip(string $text, array $toolResults): bool
    {
        $normalized = $this->normalizeForCompare($text);
        if ($normalized === '') {
            return false;
        }

        foreach ($toolResults as $row) {
            $result = is_array($row['result'] ?? null) ? $row['result'] : [];
            foreach (['tip', 'hint'] as $key) {
                $tip = trim((string) ($result[$key] ?? ''));
                if ($tip === '') {
                    continue;
                }
                $tipNorm = $this->normalizeForCompare($tip);
                if ($tipNorm !== '' && (str_contains($normalized, $tipNorm) || str_contains($tipNorm, $normalized))) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function normalizeForCompare(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/\[[^\]]*\]\([^)]+\)/', '', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function formatPayrollPreview(array $result): ?string
    {
        if (empty($result['preview']) && empty($result['totals']) && empty($result['earnings'])) {
            return null;
        }

        $employee = is_array($result['employee'] ?? null) ? $result['employee'] : [];
        $name = trim((string) ($employee['name'] ?? ''));
        if ($name === '') {
            $name = 'This employee';
        }

        $period = is_array($result['period'] ?? null) ? $result['period'] : [];
        $from = (string) ($period['from_date'] ?? '');
        $to = (string) ($period['to_date'] ?? '');
        $periodLabel = ($from !== '' && $to !== '') ? "{$from} – {$to}" : 'this period';

        $shift = is_array($result['shift'] ?? null) ? $result['shift'] : [];
        $contract = is_array($result['contract'] ?? null) ? $result['contract'] : [];
        $earnings = is_array($result['earnings'] ?? null) ? $result['earnings'] : [];
        $statutory = is_array($result['statutory'] ?? null) ? $result['statutory'] : [];
        $totals = is_array($result['totals'] ?? null) ? $result['totals'] : [];

        $paysSha = (bool) ($contract['pays_sha'] ?? $statutory['pays_sha'] ?? true);
        $expectedDays = (float) ($earnings['expected_work_days'] ?? 0);
        $paidDays = (float) ($earnings['paid_work_days'] ?? 0);
        $ratio = $expectedDays > 0.009 ? round(($paidDays / $expectedDays) * 100, 1) : null;

        $lines = [
            "### Payroll preview for **{$name}**",
            '',
            "Period: **{$periodLabel}** (Centrix engine preview — finalize at [/hr/payroll](/hr/payroll)).",
            '',
        ];

        $shiftLine = $this->shiftSummaryLine($shift);
        if ($shiftLine !== null) {
            $lines[] = $shiftLine;
        }

        $basic = $contract['basic_salary'] ?? null;
        if ($basic !== null) {
            $lines[] = 'Contract basic salary: **KES '.$this->money((float) $basic).'**';
        }
        $lines[] = 'Pays SHA/SHIF: **'.($paysSha ? 'Yes' : 'No').'**'
            .($paysSha
                ? ' (SHIF deducted).'
                : ' (SHIF not deducted on this profile).');
        $lines[] = '';

        if ($expectedDays > 0 || $paidDays > 0) {
            $lines[] = sprintf(
                'Attendance: **%s** paid of **%s** expected work days%s.',
                $this->num($paidDays),
                $this->num($expectedDays),
                $ratio !== null ? " ({$ratio}% of expected)" : '',
            );
            $lines[] = '';
        }

        $lines[] = '| Item | Amount (KES) |';
        $lines[] = '| --- | ---: |';
        $lines[] = '| Period gross | '.$this->money((float) ($totals['period_gross'] ?? $earnings['period_gross'] ?? 0)).' |';
        if (isset($statutory['nssf'])) {
            $lines[] = '| NSSF | '.$this->money((float) $statutory['nssf']).' |';
        }
        if (isset($statutory['shif'])) {
            $lines[] = '| SHIF/SHA | '.$this->money((float) $statutory['shif']).' |';
        }
        if (isset($statutory['housing_levy'])) {
            $lines[] = '| Housing levy | '.$this->money((float) $statutory['housing_levy']).' |';
        }
        if (isset($statutory['paye'])) {
            $lines[] = '| PAYE | '.$this->money((float) $statutory['paye']).' |';
        }
        $lines[] = '| Total deductions | '.$this->money((float) ($totals['total_deductions'] ?? 0)).' |';
        $lines[] = '| **Net pay** | **'.$this->money((float) ($totals['net_pay'] ?? 0)).'** |';
        $lines[] = '';
        $lines[] = 'These are Centrix payroll-engine figures for this period — not a hand-calculated 22-day or 8-hour estimate.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $shift
     */
    protected function shiftSummaryLine(array $shift): ?string
    {
        $byDay = is_array($shift['schedule_by_day'] ?? null) ? $shift['schedule_by_day'] : [];
        if ($byDay !== []) {
            $parts = [];
            foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
                $row = $byDay[$day] ?? $byDay[ucfirst($day)] ?? null;
                if (! is_array($row)) {
                    continue;
                }
                $start = $row['start'] ?? $row['start_time'] ?? null;
                $end = $row['end'] ?? $row['end_time'] ?? null;
                if ($start && $end) {
                    $parts[] = strtoupper($day).' '.$start.'–'.$end;
                }
            }
            if ($parts !== []) {
                return 'Shift schedule: '.implode(', ', array_slice($parts, 0, 7)).'.';
            }
        }

        $start = $shift['start_time'] ?? null;
        $end = $shift['end_time'] ?? null;
        $label = trim((string) ($shift['name'] ?? $shift['shift_name'] ?? ''));
        if ($start && $end) {
            return 'Shift'.($label !== '' ? " ({$label})" : '').": {$start}–{$end}.";
        }
        if ($label !== '') {
            return "Shift: {$label}.";
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function formatEmployeeDetails(array $result): ?string
    {
        $employee = is_array($result['employee'] ?? null) ? $result['employee'] : $result;
        $name = trim((string) ($employee['full_name'] ?? $employee['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $pay = is_array($result['pay'] ?? null) ? $result['pay'] : [];
        $basic = $pay['basic_salary'] ?? $pay['base_salary'] ?? $employee['basic_salary'] ?? $employee['base_salary'] ?? null;
        $username = trim((string) ($employee['username'] ?? ''));
        $role = trim((string) ($employee['job_title'] ?? $employee['role'] ?? $result['job_title'] ?? ''));

        $lines = ["### **{$name}**".($username !== '' ? " ({$username})" : '')];
        if ($role !== '') {
            $lines[] = "Role: {$role}";
        }
        if ($basic !== null) {
            $lines[] = 'Basic salary: **KES '.$this->money((float) $basic).'**';
        }
        if (! empty($result['assigned_routes']) && is_array($result['assigned_routes'])) {
            $routeNames = [];
            foreach ($result['assigned_routes'] as $r) {
                if (is_array($r) && ! empty($r['route_name'])) {
                    $routeNames[] = (string) $r['route_name'];
                }
            }
            if ($routeNames !== []) {
                $lines[] = 'Assigned routes: '.implode(', ', $routeNames);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function formatCustomerStatement(array $result): ?string
    {
        $customer = is_array($result['customer'] ?? null) ? $result['customer'] : [];
        $name = trim((string) ($customer['customer_name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $period = is_array($result['period'] ?? null) ? $result['period'] : [];
        $periodLabel = (string) ($period['label'] ?? trim(($period['from_date'] ?? '').' – '.($period['to_date'] ?? '')));

        $lines = [
            "### Statement for **{$name}**",
            '',
            'Current balance due: **KES '.$this->money((float) ($summary['current_balance_due'] ?? $customer['current_balance'] ?? 0)).'**',
        ];
        if ($periodLabel !== '' && $periodLabel !== ' – ') {
            $lines[] = "Period: {$periodLabel}";
            $lines[] = 'Purchases in period: **KES '.$this->money((float) ($summary['period_purchases_total'] ?? 0)).'**';
            $lines[] = 'Payments in period: **KES '.$this->money((float) ($summary['period_payments_total'] ?? 0)).'**';
        }

        $products = is_array($result['purchases_by_product'] ?? null) ? $result['purchases_by_product'] : [];
        if ($products !== []) {
            $lines[] = '';
            $lines[] = '| Product | Qty | Amount (KES) |';
            $lines[] = '| --- | --- | ---: |';
            foreach (array_slice($products, 0, 25) as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $lines[] = sprintf(
                    '| %s | %s | %s |',
                    $this->cell((string) ($p['product_name'] ?? '—')),
                    $this->cell((string) ($p['qty_label'] ?? $p['qty'] ?? '—')),
                    $this->money((float) ($p['amount'] ?? $p['line_total'] ?? 0)),
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function formatSupplierStatement(array $result): ?string
    {
        $supplier = is_array($result['supplier'] ?? null) ? $result['supplier'] : [];
        $name = trim((string) ($supplier['supplier_name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $lines = [
            "### Statement for **{$name}**",
            '',
            'Balance due: **KES '.$this->money((float) ($summary['balance_due'] ?? $supplier['balance_due'] ?? 0)).'**',
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function formatSalesByCashier(array $result): ?string
    {
        $cashiers = is_array($result['cashiers'] ?? null) ? $result['cashiers'] : [];
        $from = (string) ($result['from_date'] ?? '');
        $to = (string) ($result['to_date'] ?? '');
        $period = ($from !== '' && $to !== '')
            ? ($from === $to ? $from : "{$from} – {$to}")
            : '';

        if (! empty($result['error']) && ! empty($result['message'])) {
            return (string) $result['message'];
        }

        $filter = trim((string) ($result['cashier_filter'] ?? ''));
        $lines = [
            '### Sales by cashier'.($period !== '' ? " ({$period})" : ''),
            '',
        ];
        if ($filter !== '') {
            $lines[] = "Filter: {$filter}";
            $lines[] = '';
        }

        if ($cashiers === []) {
            $lines[] = 'No cashier sales recorded for this period.';

            return implode("\n", $lines);
        }

        $lines[] = '| Cashier | Username | Orders | Gross | Collected | Fully paid |';
        $lines[] = '| --- | --- | ---: | ---: | ---: | ---: |';
        $totalSales = 0.0;
        $totalCollected = 0.0;
        $totalFullyPaid = 0.0;
        $totalTx = 0;
        foreach (array_slice($cashiers, 0, 40) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sales = (float) ($row['gross_sales'] ?? 0);
            $collected = (float) ($row['amount_collected'] ?? 0);
            $fullyPaid = (float) ($row['fully_paid_sales'] ?? 0);
            $tx = (int) ($row['transactions'] ?? 0);
            $totalSales += $sales;
            $totalCollected += $collected;
            $totalFullyPaid += $fullyPaid;
            $totalTx += $tx;
            $name = trim((string) ($row['cashier_name'] ?? '—'));
            $username = trim((string) ($row['username'] ?? '—'));
            $lines[] = sprintf(
                '| %s | %s | %d | %s | %s | %s |',
                $this->cell($name !== '' ? $name : '—'),
                $this->cell($username !== '' ? $username : '—'),
                $tx,
                $this->money($sales),
                $this->money($collected),
                $this->money($fullyPaid),
            );
        }
        $lines[] = '';
        $lines[] = '**Gross (Sales by User):** KES '.$this->money($totalSales)
            .' · **Collected:** KES '.$this->money($totalCollected)
            .' · **Fully paid orders:** KES '.$this->money($totalFullyPaid)
            .' · **Orders:** '.$totalTx.'.';
        $lines[] = 'Gross includes unpaid/credit pipeline. Collected is amount paid. Fully paid is closer to till X/Z sales.';
        $matched = trim((string) ($result['matched_username'] ?? ''));
        if ($matched !== '') {
            $lines[] = 'Matched username: **'.$matched.'**.';
        }
        $scope = (string) ($result['branch_scope'] ?? '');
        if ($scope === 'askers_branch_only') {
            $lines[] = 'Branch scope: your branch only.';
        }
        $lines[] = '';
        $lines[] = 'Verify on [Sales by user](/reports/sales-by-user) for the same date, or All Orders filtered by cashier.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function formatTillHealth(array $result): ?string
    {
        if (($result['type'] ?? '') !== 'cash_till_health' && empty($result['sessions']) && empty($result['payment_mix'])) {
            return null;
        }

        $lookback = (int) ($result['lookback_days'] ?? 14);
        $mix = is_array($result['payment_mix'] ?? null) ? $result['payment_mix'] : [];
        $outliers = is_array($result['variance_outliers'] ?? null) ? $result['variance_outliers'] : [];
        $sessions = is_array($result['sessions'] ?? null) ? $result['sessions'] : [];

        $lines = [
            "### Cash & till health (last {$lookback} days)",
            '',
            '| Payment mix | Amount (KES) |',
            '| --- | ---: |',
            '| Cash | '.$this->money((float) ($mix['cash'] ?? 0)).' |',
            '| M-Pesa | '.$this->money((float) ($mix['mpesa'] ?? 0)).' |',
            '| Bank | '.$this->money((float) ($mix['bank'] ?? 0)).' |',
            '| Orders total | '.$this->money((float) ($mix['order_total'] ?? 0)).' |',
            '',
        ];

        if (! empty($result['blind_till_close'])) {
            $lines[] = 'Blind till close is **on** for this organization.';
            $lines[] = '';
        }

        if ($outliers !== []) {
            $lines[] = 'Variance outliers (|variance| ≥ KES 50):';
            $lines[] = '';
            $lines[] = '| Till | Cashier | Variance (KES) | Closed |';
            $lines[] = '| --- | --- | ---: | --- |';
            foreach (array_slice($outliers, 0, 15) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $cashier = trim((string) ($row['cashier'] ?? '—'));
                if (preg_match('/^User #\d+$/', $cashier)) {
                    $cashier = '—';
                }
                $lines[] = sprintf(
                    '| %s | %s | %s | %s |',
                    $this->cell((string) ($row['till'] ?? '—')),
                    $this->cell($cashier !== '' ? $cashier : '—'),
                    $this->money((float) ($row['variance'] ?? 0)),
                    $this->cell((string) ($row['closed_at'] ?? '—')),
                );
            }
            $lines[] = '';
        } elseif ($sessions === []) {
            $lines[] = 'No closed till sessions found in this lookback.';
            $lines[] = '';
        } else {
            $lines[] = 'No large variance outliers in the closed sessions reviewed.';
            $lines[] = '';
        }

        $lines[] = 'Open [Till management](/sales/till-management) or [Daily sales](/reports/daily-sales).';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function formatRouteOrders(array $result): ?string
    {
        if (($result['type'] ?? '') !== 'route_mobile_debrief' && ! isset($result['booked_orders'])) {
            return null;
        }

        $from = (string) ($result['from_date'] ?? '');
        $to = (string) ($result['to_date'] ?? '');
        $period = ($from !== '' && $to !== '')
            ? ($from === $to ? $from : "{$from} – {$to}")
            : '';
        $user = trim((string) ($result['filtered_user'] ?? ''));

        $lines = [
            '### Mobile / route sales'.($period !== '' ? " ({$period})" : ''),
            '',
        ];
        if ($user !== '') {
            $lines[] = "Rep: **{$user}**";
            $lines[] = '';
        }
        $lines[] = '| Metric | Value |';
        $lines[] = '| --- | ---: |';
        $lines[] = '| Orders | '.(int) ($result['orders_count'] ?? $result['booked_orders'] ?? 0).' |';
        $lines[] = '| Booked | '.(int) ($result['booked_orders'] ?? 0).' |';
        $lines[] = '| Delivered / completed | '.(int) ($result['delivered_or_completed'] ?? 0).' |';
        $lines[] = '| Gross sales (KES) | '.$this->money((float) ($result['gross_sales_total'] ?? 0)).' |';
        $unpaid = is_array($result['unpaid_on_route'] ?? null) ? $result['unpaid_on_route'] : [];
        $lines[] = '| Unpaid orders | '.(int) ($unpaid['count'] ?? 0).' |';
        $lines[] = '| Unpaid balance (KES) | '.$this->money((float) ($unpaid['balance_due'] ?? 0)).' |';

        $skus = is_array($result['top_skus'] ?? null) ? $result['top_skus'] : [];
        if ($skus !== []) {
            $lines[] = '';
            $lines[] = 'Top products:';
            $lines[] = '';
            $lines[] = '| Product | Qty | Amount (KES) |';
            $lines[] = '| --- | --- | ---: |';
            foreach (array_slice($skus, 0, 10) as $sku) {
                if (! is_array($sku)) {
                    continue;
                }
                $lines[] = sprintf(
                    '| %s | %s | %s |',
                    $this->cell((string) ($sku['product_name'] ?? '—')),
                    $this->cell((string) ($sku['qty_label'] ?? $sku['qty'] ?? '—')),
                    $this->money((float) ($sku['amount'] ?? 0)),
                );
            }
        }

        $lines[] = '';
        $lines[] = 'Open [Mobile orders](/sales/orders/queues/mobile).';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function formatExpenseSummary(array $result): ?string
    {
        if (($result['type'] ?? '') !== 'expense_summary' && ! isset($result['total_expenses'])) {
            return null;
        }

        $period = is_array($result['period'] ?? null) ? $result['period'] : [];
        $from = (string) ($period['from_date'] ?? '');
        $to = (string) ($period['to_date'] ?? '');
        $periodLabel = ($from !== '' && $to !== '')
            ? ($from === $to ? $from : "{$from} – {$to}")
            : '';
        $user = trim((string) ($result['filtered_user'] ?? ''));

        $lines = [
            '### Expenses'.($periodLabel !== '' ? " ({$periodLabel})" : ''),
            '',
        ];
        if ($user !== '') {
            $lines[] = "Recorded by: **{$user}**";
            $lines[] = '';
        }
        $lines[] = 'Total: **KES '.$this->money((float) ($result['total_expenses'] ?? 0)).'**';
        if (isset($result['previous_period_total'])) {
            $lines[] = 'Prior period: KES '.$this->money((float) $result['previous_period_total'])
                .(isset($result['change_pct']) ? ' ('.$result['change_pct'].'%)' : '');
        }

        $mobile = is_array($result['mobile_route_expenses'] ?? null) ? $result['mobile_route_expenses'] : [];
        $mobileLines = is_array($mobile['lines'] ?? null) ? $mobile['lines'] : [];
        if ($user !== '' && isset($result['combined_user_total'])) {
            $lines[] = 'Combined (accounting + mobile route): **KES '.$this->money((float) $result['combined_user_total']).'**';
        }
        if ($mobileLines !== [] || (float) ($mobile['total'] ?? 0) > 0) {
            $lines[] = 'Mobile route expenses: **KES '.$this->money((float) ($mobile['total'] ?? 0)).'** ('
                .(int) ($mobile['count'] ?? count($mobileLines)).' entries)';
        }

        $byCat = is_array($result['by_category'] ?? null) ? $result['by_category'] : [];
        if ($byCat !== []) {
            $lines[] = '';
            $lines[] = $user !== '' ? 'Accounting expenses recorded by this user:' : 'By category:';
            $lines[] = '';
            $lines[] = '| Category | Amount (KES) | Count |';
            $lines[] = '| --- | ---: | ---: |';
            foreach (array_slice($byCat, 0, 20) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $lines[] = sprintf(
                    '| %s | %s | %d |',
                    $this->cell((string) ($row['category'] ?? '—')),
                    $this->money((float) ($row['amount'] ?? 0)),
                    (int) ($row['expense_count'] ?? 0),
                );
            }
        }

        $detail = is_array($result['expense_lines'] ?? null) ? $result['expense_lines'] : [];
        if ($detail !== []) {
            $lines[] = '';
            $lines[] = 'Accounting expense lines:';
            $lines[] = '';
            $lines[] = '| Date | Category | Description | Amount (KES) |';
            $lines[] = '| --- | --- | --- | ---: |';
            foreach (array_slice($detail, 0, 25) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $lines[] = sprintf(
                    '| %s | %s | %s | %s |',
                    $this->cell((string) ($row['expense_date'] ?? '—')),
                    $this->cell((string) ($row['category'] ?? '—')),
                    $this->cell((string) ($row['description'] ?? '—')),
                    $this->money((float) ($row['amount'] ?? 0)),
                );
            }
        }

        if ($mobileLines !== []) {
            $lines[] = '';
            $lines[] = 'Mobile route expense lines:';
            $lines[] = '';
            $lines[] = '| Date | Description | Status | Amount (KES) |';
            $lines[] = '| --- | --- | --- | ---: |';
            foreach (array_slice($mobileLines, 0, 25) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $lines[] = sprintf(
                    '| %s | %s | %s | %s |',
                    $this->cell((string) ($row['expense_date'] ?? '—')),
                    $this->cell((string) ($row['description'] ?? '—')),
                    $this->cell((string) ($row['status'] ?? '—')),
                    $this->money((float) ($row['amount'] ?? 0)),
                );
            }
        }

        if ($user !== '' && $byCat === [] && $detail === [] && $mobileLines === []) {
            $lines[] = '';
            $lines[] = "No expenses found for **{$user}** in this period (checked accounting recorded_by and mobile route expenses).";
        }

        $lines[] = '';
        $lines[] = 'Open [/expenses](/expenses) or [Mobile orders](/sales/orders/queues/mobile).';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function formatCustomerReturns(array $result): ?string
    {
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $returns = is_array($result['returns'] ?? null) ? $result['returns'] : [];
        $period = is_array($result['period'] ?? null) ? $result['period'] : [];
        if ($summary === [] && $returns === []) {
            return null;
        }

        $from = (string) ($period['from_date'] ?? '');
        $to = (string) ($period['to_date'] ?? '');
        $periodLabel = ($from !== '' && $to !== '')
            ? ($from === $to ? $from : "{$from} – {$to}")
            : '';
        $user = trim((string) ($result['filtered_user'] ?? ''));

        $lines = [
            '### Customer returns'.($periodLabel !== '' ? " ({$periodLabel})" : ''),
            '',
        ];
        if ($user !== '') {
            $lines[] = "Returned by: **{$user}**";
            $lines[] = '';
        }
        $lines[] = 'Count: **'.(int) ($summary['returns_count'] ?? count($returns)).'**';
        $lines[] = 'Total amount: **KES '.$this->money((float) ($summary['total_amount'] ?? 0)).'**';

        if ($returns !== []) {
            $lines[] = '';
            $lines[] = '| Date | Return # | Status | Amount (KES) | Returned by |';
            $lines[] = '| --- | --- | --- | ---: | --- |';
            foreach (array_slice($returns, 0, 40) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $lines[] = sprintf(
                    '| %s | %s | %s | %s | %s |',
                    $this->cell((string) ($row['return_date'] ?? '—')),
                    $this->cell((string) ($row['return_no'] ?? '—')),
                    $this->cell((string) ($row['status'] ?? '—')),
                    $this->money((float) ($row['total_amount'] ?? 0)),
                    $this->cell((string) ($row['returned_by_name'] ?? '—')),
                );
            }
        } else {
            $lines[] = '';
            $lines[] = 'No returns found for this filter.';
        }

        $lines[] = '';
        $lines[] = 'Open [/sales/returns](/sales/returns).';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function formatSalesByProduct(array $result): ?string
    {
        if (! isset($result['products']) || ! is_array($result['products'])) {
            return null;
        }

        $from = (string) ($result['from_date'] ?? '');
        $to = (string) ($result['to_date'] ?? '');
        $lines = [
            '### Sales by product'.($from !== '' ? " ({$from} – {$to})" : ''),
            '',
            '| Product | Qty | Amount (KES) |',
            '| --- | --- | ---: |',
        ];
        foreach ($result['products'] as $p) {
            if (! is_array($p)) {
                continue;
            }
            $lines[] = sprintf(
                '| %s | %s | %s |',
                $this->cell((string) ($p['product_name'] ?? '—')),
                $this->cell((string) ($p['qty_label'] ?? $p['qty'] ?? '—')),
                $this->money((float) ($p['amount'] ?? 0)),
            );
        }
        $total = $result['summary']['total_amount'] ?? null;
        if ($total !== null) {
            $lines[] = '';
            $lines[] = '**Total:** KES '.$this->money((float) $total);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function formatProductPriceHistory(array $result): ?string
    {
        $product = is_array($result['product'] ?? null) ? $result['product'] : [];
        $name = trim((string) ($product['product_name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $history = is_array($result['history'] ?? null) ? $result['history'] : [];
        $lines = [
            "### Price history for **{$name}**",
            '',
        ];

        $currentUnit = $product['current_unit_price'] ?? null;
        $currentCost = $product['current_last_cost_price'] ?? null;
        if ($currentUnit !== null || $currentCost !== null) {
            $lines[] = 'Current catalog: unit **KES '.$this->money((float) ($currentUnit ?? 0)).'**, '
                .'last cost **KES '.$this->money((float) ($currentCost ?? 0)).'**.';
            $lines[] = '';
        }

        if ($history === []) {
            $lines[] = 'No formal price-change rows yet for this product in Centrix price history.';
            $lines[] = '';
            $lines[] = 'Open [/price-history](/price-history) to review the ledger, or edit the product price on the product card.';

            return implode("\n", $lines);
        }

        $lines[] = 'Formal Centrix price-change log (newest first):';
        $lines[] = '';
        $lines[] = '| Date | Unit price (KES) | Cost price (KES) | Discount % | Changed by |';
        $lines[] = '| --- | ---: | ---: | ---: | --- |';
        foreach (array_slice($history, 0, 40) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s |',
                $this->cell((string) ($row['changed_at_label'] ?? $row['changed_at'] ?? '—')),
                $this->money((float) ($row['unit_price'] ?? 0)),
                $this->money((float) ($row['cost_price'] ?? 0)),
                $this->cell((string) ($row['discount_pct'] ?? 0)),
                $this->cell((string) ($row['changed_by_name'] ?? '—')),
            );
        }
        $lines[] = '';
        $lines[] = 'Full ledger: [/price-history](/price-history).';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function formatVatCollected(array $result): ?string
    {
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        if ($summary === [] && ! isset($result['vat_collected_total'])) {
            return null;
        }
        $vat = (float) ($summary['vat_collected_total'] ?? $result['vat_collected_total'] ?? 0);
        $period = is_array($result['period'] ?? null) ? $result['period'] : [];
        $label = (string) ($period['label'] ?? trim(($period['from_date'] ?? '').' – '.($period['to_date'] ?? '')));

        $lines = ['### VAT collected'.($label !== '' && $label !== ' – ' ? " ({$label})" : '')];
        $lines[] = '';
        $lines[] = 'VAT collected: **KES '.$this->money($vat).'**';
        if (isset($summary['taxable_sales_gross'])) {
            $lines[] = 'Taxable sales (gross): **KES '.$this->money((float) $summary['taxable_sales_gross']).'**';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function formatUserDetails(array $result): ?string
    {
        $user = is_array($result['user'] ?? null) ? $result['user'] : $result;
        $name = trim((string) ($user['full_name'] ?? $user['name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $username = trim((string) ($user['username'] ?? ''));
        $lines = ["### **{$name}**".($username !== '' ? " ({$username})" : '')];
        $routes = is_array($result['assigned_routes'] ?? null) ? $result['assigned_routes'] : [];
        if ($routes !== []) {
            $names = [];
            foreach ($routes as $r) {
                if (is_array($r) && ! empty($r['route_name'])) {
                    $names[] = (string) $r['route_name'];
                }
            }
            if ($names !== []) {
                $lines[] = 'Assigned routes: '.implode(', ', $names);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function formatRouteDetails(array $result): ?string
    {
        $route = is_array($result['route'] ?? null) ? $result['route'] : $result;
        $name = trim((string) ($route['route_name'] ?? $route['name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $lines = ["### Route **{$name}**"];
        $users = is_array($result['assigned_users'] ?? null) ? $result['assigned_users'] : [];
        if ($users !== []) {
            $parts = [];
            foreach ($users as $u) {
                if (! is_array($u)) {
                    continue;
                }
                $n = trim((string) ($u['full_name'] ?? $u['name'] ?? ''));
                $un = trim((string) ($u['username'] ?? ''));
                if ($n !== '') {
                    $parts[] = $n.($un !== '' ? " ({$un})" : '');
                }
            }
            if ($parts !== []) {
                $lines[] = 'Operated by: '.implode(', ', $parts);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Training notes are exemplars — never paste sample answers as the reply.
     *
     * @param  array<string, mixed>  $result
     */
    protected function formatTrainingNotes(array $result): ?string
    {
        $notes = is_array($result['notes'] ?? null) ? $result['notes'] : [];
        if ($notes === []) {
            return (string) ($result['hint'] ?? 'No matching training notes. I can still help from Centrix screens and live tools.');
        }

        $paths = [];
        foreach ($notes as $note) {
            if (! is_array($note)) {
                continue;
            }
            $path = trim((string) ($note['path'] ?? ''));
            if ($path !== '' && str_starts_with($path, '/')) {
                $paths[$path] = true;
            }
        }

        $lines = [
            'I found related Centrix how-to notes. Here is the practical guidance (not a canned training answer):',
            '',
        ];
        foreach (array_slice($notes, 0, 3) as $note) {
            if (! is_array($note)) {
                continue;
            }
            $topic = trim((string) ($note['sample_question'] ?? $note['topic'] ?? ''));
            $path = trim((string) ($note['path'] ?? ''));
            if ($topic !== '') {
                $lines[] = '- Related topic: '.$topic.($path !== '' ? " → [{$path}]({$path})" : '');
            }
        }
        if ($paths !== []) {
            $lines[] = '';
            $lines[] = 'Useful screens: '.implode(', ', array_map(
                fn (string $p) => "[{$p}]({$p})",
                array_keys($paths),
            ));
        }
        $lines[] = '';
        $lines[] = 'Ask a specific follow-up (or name a person/product) and I will pull live Centrix data.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function formatLpoDetails(array $result): ?string
    {
        $lpoNo = (int) ($result['lpo_no'] ?? 0);
        if ($lpoNo <= 0) {
            return null;
        }

        $po = (string) ($result['po_number'] ?? $lpoNo);
        $supplier = (string) ($result['supplier_name'] ?? 'Supplier');
        $status = (string) ($result['status_name'] ?? '');
        $total = number_format((float) ($result['total_amount'] ?? 0), 2);
        $path = (string) ($result['path'] ?? '/lpo/'.$lpoNo);

        $lines = [
            "Purchase order **{$po}** for **{$supplier}**.",
            "Status: {$status}. Total: KES {$total}.",
            '',
            "Open: [{$path}]({$path})",
            "Print: [/lpo/{$lpoNo}/print](/lpo/{$lpoNo}/print)",
        ];

        $next = is_array($result['next_steps'] ?? null) ? $result['next_steps'] : [];
        if ($next !== []) {
            $lines[] = '';
            $lines[] = 'Next steps:';
            foreach (array_slice($next, 0, 4) as $step) {
                $lines[] = '- '.(string) $step;
            }
        }

        $lines[] = '';
        $lines[] = 'Use **Download PDF** in the chat panel, or ask me to submit for approval, approve, mark sent, or receive goods.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function formatFindScreen(array $result): ?string
    {
        $screens = is_array($result['screens'] ?? null) ? $result['screens'] : [];
        if ($screens === [] && ! empty($result['path'])) {
            $path = (string) $result['path'];

            return "Open [{$path}]({$path}).";
        }
        if ($screens === []) {
            return null;
        }

        $lines = ['Here is where to go in Centrix:', ''];
        foreach (array_slice($screens, 0, 5) as $screen) {
            if (! is_array($screen)) {
                continue;
            }
            $path = (string) ($screen['path'] ?? '');
            $label = (string) ($screen['label'] ?? $path);
            if ($path !== '') {
                $lines[] = "- [{$label}]({$path})";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function appendPrimaryScreen(string $text, array $result): string
    {
        if (! empty($result['screens'][0]['path'])) {
            $path = (string) $result['screens'][0]['path'];
            $label = (string) ($result['screens'][0]['label'] ?? $path);
            if (! str_contains($text, $path)) {
                return rtrim($text)."\n\n[{$label}]({$path})";
            }
        }

        return $text;
    }

    protected function money(float $amount): string
    {
        return number_format($amount, 2);
    }

    protected function num(float $value): string
    {
        if (abs($value - round($value)) < 0.001) {
            return (string) (int) round($value);
        }

        return number_format($value, 2);
    }

    protected function cell(string $value): string
    {
        return str_replace('|', '/', $value);
    }
}
