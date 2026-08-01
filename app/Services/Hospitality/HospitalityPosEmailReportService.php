<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityCheck;
use App\Models\Organization;
use App\Services\Notifications\OrganizationMailSender;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Configurable Hotel POS maths emails:
 * - Hourly: receipts in that hour + running cashier totals for the day up to that hour
 * - Daily: full day EOD-style maths per cashier
 * - On settle: single receipt + that cashier's day-to-date totals
 */
class HospitalityPosEmailReportService
{
    public function __construct(
        protected OrganizationMailSender $mail,
    ) {}

    /**
     * @return array{orgs_checked: int, hourly_sent: int, daily_sent: int, errors: int, skipped: int}
     */
    public function runDue(?Carbon $now = null): array
    {
        $now = $now?->copy() ?? now();
        $stats = [
            'orgs_checked' => 0,
            'hourly_sent' => 0,
            'daily_sent' => 0,
            'errors' => 0,
            'skipped' => 0,
        ];

        Organization::query()
            ->whereNotNull('module_settings')
            ->orderBy('id')
            ->chunkById(50, function ($orgs) use (&$stats, $now) {
                foreach ($orgs as $org) {
                    $stats['orgs_checked']++;
                    $cfg = HospitalityPosSettings::forOrganization($org)['pos_email_reports'];
                    if (! $cfg['enabled'] || $cfg['recipients'] === []) {
                        $stats['skipped']++;
                        continue;
                    }
                    if (! $this->mail->canSendForOrganization($org)) {
                        $stats['skipped']++;
                        continue;
                    }

                    try {
                        if ($cfg['send_hourly'] && (int) $now->format('i') === 0) {
                            // At HH:00 send the previous hour (e.g. 15:00 → 14:00–14:59).
                            $hourEnd = $now->copy()->startOfHour()->subSecond();
                            $hourStart = $hourEnd->copy()->startOfHour();
                            $cacheKey = $this->cacheKey($org, 'hourly', $hourStart->format('Y-m-d-H'));
                            if (! Cache::has($cacheKey)) {
                                $sent = $this->sendHourly($org, $cfg['recipients'], $hourStart, $hourEnd);
                                if ($sent) {
                                    Cache::put($cacheKey, true, now()->addDays(2));
                                    $stats['hourly_sent']++;
                                }
                            }
                        }

                        if ($cfg['send_daily'] && $now->format('H:i') === $cfg['daily_at']) {
                            $day = $now->toDateString();
                            $cacheKey = $this->cacheKey($org, 'daily', $day);
                            if (! Cache::has($cacheKey)) {
                                $dayStart = $now->copy()->startOfDay();
                                $dayEnd = $now->copy();
                                $sent = $this->sendDaily($org, $cfg['recipients'], $dayStart, $dayEnd);
                                if ($sent) {
                                    Cache::put($cacheKey, true, now()->addDays(2));
                                    $stats['daily_sent']++;
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        Log::warning('Hospitality POS email report failed', [
                            'organization_id' => $org->id,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $stats;
    }

    /**
     * Fire-and-forget after a check is fully paid (does not throw).
     */
    public function notifySettleIfEnabled(Organization $org, HospitalityCheck $check): void
    {
        try {
            $cfg = HospitalityPosSettings::forOrganization($org)['pos_email_reports'];
            if (! $cfg['enabled'] || ! $cfg['send_on_settle'] || $cfg['recipients'] === []) {
                return;
            }
            if (! $this->mail->canSendForOrganization($org)) {
                return;
            }
            $check->loadMissing(['lines', 'payments', 'outlet']);
            $this->sendSettle($org, $cfg['recipients'], $check);
        } catch (\Throwable $e) {
            Log::warning('Hospitality settle email failed', [
                'organization_id' => $org->id,
                'check_id' => $check->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  list<string>  $recipients
     */
    public function sendHourly(Organization $org, array $recipients, Carbon $hourStart, Carbon $hourEnd): bool
    {
        $receipts = $this->checksInRange($org, $hourStart, $hourEnd);
        $dayStart = $hourStart->copy()->startOfDay();
        $running = $this->cashierTotals($org, $dayStart, $hourEnd);

        $subject = sprintf(
            '%s — Hotel POS hourly %s–%s',
            $org->org_name ?? $org->company_code,
            $hourStart->format('H:i'),
            $hourEnd->format('H:i'),
        );

        $body = $this->formatHeader($org, 'Hourly Hotel POS maths', $hourStart, $hourEnd);
        $body .= $this->formatReceiptsSection($receipts, 'Receipts sold this hour');
        $body .= "\n".$this->formatCashierTotalsSection(
            $running,
            'Running totals by cashier (today through '.$hourEnd->format('H:i').')',
        );
        $body .= $this->formatFooter();

        return $this->deliver($org, $recipients, $subject, $body);
    }

    /**
     * @param  list<string>  $recipients
     */
    public function sendDaily(Organization $org, array $recipients, Carbon $from, Carbon $to): bool
    {
        $receipts = $this->checksInRange($org, $from, $to);
        $running = $this->cashierTotals($org, $from, $to);

        $subject = sprintf(
            '%s — Hotel POS daily maths %s',
            $org->org_name ?? $org->company_code,
            $from->toDateString(),
        );

        $body = $this->formatHeader($org, 'Daily Hotel POS maths', $from, $to);
        $body .= $this->formatReceiptsSection($receipts, 'All receipts today');
        $body .= "\n".$this->formatCashierTotalsSection($running, 'Totals by cashier (full day to date)');
        $body .= $this->formatFooter();

        return $this->deliver($org, $recipients, $subject, $body);
    }

    /**
     * @param  list<string>  $recipients
     */
    public function sendSettle(Organization $org, array $recipients, HospitalityCheck $check): bool
    {
        $closedAt = $check->closed_at ? Carbon::parse($check->closed_at) : now();
        $dayStart = $closedAt->copy()->startOfDay();
        $cashierId = (int) ($check->closed_by ?: $check->opened_by ?: 0);
        $running = $this->cashierTotals($org, $dayStart, $closedAt);
        $cashierRow = $running[$cashierId] ?? null;

        $subject = sprintf(
            '%s — Receipt %s settled',
            $org->org_name ?? $org->company_code,
            $check->check_number,
        );

        $body = $this->formatHeader($org, 'Receipt settled', $closedAt, $closedAt);
        $body .= $this->formatSingleReceipt($check);
        $body .= "\n";
        if ($cashierRow) {
            $body .= $this->formatCashierTotalsSection(
                [$cashierId => $cashierRow],
                'Cashier day-to-date totals (up to this receipt)',
            );
        }
        $body .= $this->formatFooter();

        return $this->deliver($org, $recipients, $subject, $body);
    }

    /**
     * @param  list<string>  $recipients
     */
    protected function deliver(Organization $org, array $recipients, string $subject, string $body): bool
    {
        $any = false;
        foreach ($recipients as $email) {
            if ($this->mail->sendRaw($org, $email, $subject, $body)) {
                $any = true;
            }
        }

        return $any;
    }

    protected function cacheKey(Organization $org, string $kind, string $slot): string
    {
        return "hosp_pos_email:{$org->id}:{$kind}:{$slot}";
    }

    /**
     * @return list<HospitalityCheck>
     */
    protected function checksInRange(Organization $org, Carbon $from, Carbon $to): array
    {
        return HospitalityCheck::query()
            ->with(['lines', 'payments', 'outlet'])
            ->where('organization_id', $org->id)
            ->whereIn('status', ['paid', 'settled', 'partially_paid'])
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$from, $to])
            ->orderBy('closed_at')
            ->get()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function cashierTotals(Organization $org, Carbon $from, Carbon $to): array
    {
        $checks = HospitalityCheck::query()
            ->where('organization_id', $org->id)
            ->whereIn('status', ['paid', 'settled', 'partially_paid'])
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$from, $to])
            ->get();

        $byCashier = [];
        foreach ($checks as $check) {
            $cid = (int) ($check->closed_by ?: $check->opened_by ?: 0);
            if (! isset($byCashier[$cid])) {
                $byCashier[$cid] = [
                    'cashier_id' => $cid ?: null,
                    'cashier_name' => 'Unassigned',
                    'checks' => 0,
                    'gross_sales' => 0.0,
                    'vat_total' => 0.0,
                    'amount_paid' => 0.0,
                    'cash' => 0.0,
                    'mpesa' => 0.0,
                    'card_bank' => 0.0,
                    'room_charge' => 0.0,
                    'other' => 0.0,
                ];
            }
            $byCashier[$cid]['checks']++;
            $byCashier[$cid]['gross_sales'] += (float) $check->total;
            $byCashier[$cid]['vat_total'] += (float) $check->vat_total;
            $byCashier[$cid]['amount_paid'] += (float) $check->amount_paid;
        }

        $userIds = array_filter(array_keys($byCashier));
        $names = $userIds
            ? DB::table('users')->whereIn('id', $userIds)->pluck('full_name', 'id')
            : collect();

        $payments = DB::table('hospitality_check_payments as p')
            ->join('hospitality_checks as c', 'c.id', '=', 'p.check_id')
            ->where('c.organization_id', $org->id)
            ->whereIn('c.status', ['paid', 'settled', 'partially_paid'])
            ->whereNotNull('c.closed_at')
            ->whereBetween('c.closed_at', [$from, $to])
            ->select('c.closed_by', 'c.opened_by', 'p.method_code', DB::raw('SUM(p.amount) as amt'))
            ->groupBy('c.closed_by', 'c.opened_by', 'p.method_code')
            ->get();

        foreach ($payments as $p) {
            $cid = (int) ($p->closed_by ?: $p->opened_by ?: 0);
            if (! isset($byCashier[$cid])) {
                continue;
            }
            $amt = (float) $p->amt;
            $code = strtoupper((string) $p->method_code);
            if ($code === 'CASH') {
                $byCashier[$cid]['cash'] += $amt;
            } elseif ($code === 'MPESA') {
                $byCashier[$cid]['mpesa'] += $amt;
            } elseif ($code === 'ROOM') {
                $byCashier[$cid]['room_charge'] += $amt;
            } elseif (in_array($code, ['CARD', 'EQUITY', 'KCB', 'BANK', 'OTHER', 'CHEQUE'], true)) {
                $byCashier[$cid]['card_bank'] += $amt;
            } else {
                $byCashier[$cid]['other'] += $amt;
            }
        }

        foreach ($byCashier as $cid => &$row) {
            $row['cashier_name'] = $cid
                ? (string) ($names[$cid] ?? ('User #'.$cid))
                : 'Unassigned';
            foreach (['gross_sales', 'vat_total', 'amount_paid', 'cash', 'mpesa', 'card_bank', 'room_charge', 'other'] as $k) {
                $row[$k] = round($row[$k], 2);
            }
        }
        unset($row);

        uasort($byCashier, fn ($a, $b) => $b['gross_sales'] <=> $a['gross_sales']);

        return $byCashier;
    }

    protected function formatHeader(Organization $org, string $title, Carbon $from, Carbon $to): string
    {
        $lines = [
            $title,
            str_repeat('=', max(24, strlen($title))),
            'Organization: '.($org->org_name ?? $org->company_code),
            'Period: '.$from->format('Y-m-d H:i').' → '.$to->format('Y-m-d H:i'),
            'Generated: '.now()->format('Y-m-d H:i:s'),
            '',
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  list<HospitalityCheck>  $checks
     */
    protected function formatReceiptsSection(array $checks, string $heading): string
    {
        $out = $heading."\n".str_repeat('-', strlen($heading))."\n";
        if ($checks === []) {
            return $out."(No receipts in this period)\n";
        }

        $userIds = [];
        foreach ($checks as $c) {
            $uid = (int) ($c->closed_by ?: $c->opened_by ?: 0);
            if ($uid) {
                $userIds[$uid] = $uid;
            }
        }
        $names = $userIds
            ? DB::table('users')->whereIn('id', array_values($userIds))->pluck('full_name', 'id')
            : collect();

        $grand = 0.0;
        foreach ($checks as $check) {
            $out .= $this->formatSingleReceipt($check, $names)."\n";
            $grand += (float) $check->total;
        }
        $out .= sprintf("Hour / period receipt count: %d\nGross for period: %s\n", count($checks), $this->money($grand));

        return $out;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>|null  $names
     */
    protected function formatSingleReceipt(HospitalityCheck $check, $names = null): string
    {
        $cid = (int) ($check->closed_by ?: $check->opened_by ?: 0);
        $cashier = 'Unassigned';
        if ($cid) {
            $cashier = $names
                ? (string) ($names[$cid] ?? ('User #'.$cid))
                : (string) (DB::table('users')->where('id', $cid)->value('full_name') ?? ('User #'.$cid));
        }
        $outlet = $check->outlet?->name ?? $check->outlet?->code ?? '—';
        $closed = $check->closed_at ? Carbon::parse($check->closed_at)->format('H:i:s') : '—';

        $lines = [];
        $lines[] = sprintf(
            'Receipt %s | %s | Cashier: %s | Outlet: %s | Status: %s',
            $check->check_number,
            $closed,
            $cashier,
            $outlet,
            $check->status,
        );
        $lines[] = sprintf(
            '  Total: %s  VAT: %s  Paid: %s',
            $this->money((float) $check->total),
            $this->money((float) $check->vat_total),
            $this->money((float) $check->amount_paid),
        );

        foreach ($check->lines ?? [] as $line) {
            $lines[] = sprintf(
                '  - %s x %s @ %s = %s',
                $line->description ?? $line->product_code ?? 'Item',
                $this->qty((float) $line->qty),
                $this->money((float) $line->unit_price),
                $this->money((float) $line->line_total),
            );
        }

        $tenders = [];
        foreach ($check->payments ?? [] as $pay) {
            $tenders[] = strtoupper((string) $pay->method_code).' '.$this->money((float) $pay->amount);
        }
        if ($tenders !== []) {
            $lines[] = '  Tender: '.implode(' · ', $tenders);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function formatCashierTotalsSection(array $rows, string $heading): string
    {
        $out = $heading."\n".str_repeat('-', strlen($heading))."\n";
        if ($rows === []) {
            return $out."(No cashier totals)\n";
        }

        $sumGross = 0.0;
        $sumPaid = 0.0;
        foreach ($rows as $row) {
            $sumGross += (float) $row['gross_sales'];
            $sumPaid += (float) $row['amount_paid'];
            $out .= sprintf(
                "%s — %d checks | Gross %s | Collected %s\n  Cash %s | M-Pesa %s | Card/Bank %s | Room %s | Other %s\n",
                $row['cashier_name'],
                $row['checks'],
                $this->money((float) $row['gross_sales']),
                $this->money((float) $row['amount_paid']),
                $this->money((float) $row['cash']),
                $this->money((float) $row['mpesa']),
                $this->money((float) $row['card_bank']),
                $this->money((float) $row['room_charge']),
                $this->money((float) $row['other']),
            );
        }
        $out .= sprintf(
            "ALL CASHIERS — Gross %s | Collected %s\n",
            $this->money($sumGross),
            $this->money($sumPaid),
        );

        return $out;
    }

    protected function formatFooter(): string
    {
        return "\n—\nCentrix Hotel & Bar POS maths email\nConfigure recipients under Hospitality → Settings.\n";
    }

    protected function money(float $n): string
    {
        return number_format($n, 2, '.', ',');
    }

    protected function qty(float $n): string
    {
        if (abs($n - round($n)) < 0.0001) {
            return (string) (int) round($n);
        }

        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }
}
