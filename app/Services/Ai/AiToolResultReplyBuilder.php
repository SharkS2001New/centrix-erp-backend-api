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
        foreach (array_reverse($toolResults) as $row) {
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
                    return AiNearMissHelper::appendScreens(
                        (string) $result['message'],
                        is_array($result['screens'] ?? null) ? $result['screens'] : null,
                    );
                }

                return 'I could not load that Centrix data with your current permissions.';
            }

            $formatted = match ($name) {
                'get_employee_payroll_preview' => $this->formatPayrollPreview($result),
                'get_employee_details' => $this->formatEmployeeDetails($result),
                'get_customer_statement' => $this->formatCustomerStatement($result),
                'get_supplier_statement' => $this->formatSupplierStatement($result),
                'get_sales_by_product' => $this->formatSalesByProduct($result),
                'get_product_price_history' => $this->formatProductPriceHistory($result),
                'get_vat_collected' => $this->formatVatCollected($result),
                'get_user_details' => $this->formatUserDetails($result),
                'get_route_details' => $this->formatRouteDetails($result),
                'search_training_notes' => $this->formatTrainingNotes($result),
                'find_screen' => $this->formatFindScreen($result),
                default => null,
            };

            if (is_string($formatted) && trim($formatted) !== '') {
                return $this->appendPrimaryScreen($formatted, $result);
            }

            // Prefer an explicit user message over tip/hint (model instructions).
            if (! empty($result['message']) && is_string($result['message']) && ! $this->looksLikeModelInstruction((string) $result['message'])) {
                return $this->appendPrimaryScreen(trim((string) $result['message']), $result);
            }

            if (! empty($result['path']) && is_string($result['path'])) {
                return 'Open ['.($result['path']).']('.($result['path']).').';
            }
        }

        if ($toolsUsed !== []) {
            return 'I loaded Centrix data ('.implode(', ', array_unique($toolsUsed))
                .') but could not format a full answer. Please ask again.';
        }

        return 'I could not generate a response from Centrix data. Please try rephrasing your question.';
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
            .'copy the thinking\/approach'
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
