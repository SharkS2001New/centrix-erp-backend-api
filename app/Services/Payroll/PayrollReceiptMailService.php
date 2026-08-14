<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Services\Erp\GeneralSettingsResolver;
use App\Services\Notifications\OrganizationMailSender;
use Dompdf\Dompdf;
use Dompdf\Options;

class PayrollReceiptMailService
{
    public function __construct(
        protected OrganizationMailSender $mail,
    ) {}

    public function employeeEmail(Employee $employee): ?string
    {
        foreach ([$employee->email, $employee->personal_email] as $candidate) {
            $email = trim((string) $candidate);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return null;
    }

    /**
     * @return array{sent: bool, to: ?string, skipped_reason: ?string}
     */
    public function emailLine(PayrollRun $run, PayrollLine $line, Organization $organization, ?string $toOverride = null): array
    {
        $line->loadMissing('employee');
        $employee = $line->employee;
        if (! $employee) {
            return ['sent' => false, 'to' => null, 'skipped_reason' => 'Employee missing'];
        }

        $to = $toOverride ?: $this->employeeEmail($employee);
        if (! $to) {
            return ['sent' => false, 'to' => null, 'skipped_reason' => 'No employee email'];
        }

        if (! $this->mail->canSendForOrganization($organization)) {
            return ['sent' => false, 'to' => $to, 'skipped_reason' => 'Organization email is not configured'];
        }

        $period = $run->payPeriod;
        $periodLabel = $period?->period_code
            ?: trim(($period?->period_start?->format('d M Y') ?? '').' – '.($period?->period_end?->format('d M Y') ?? ''));
        $name = trim((string) ($employee->full_name ?: trim(($employee->first_name ?? '').' '.($employee->last_name ?? ''))));
        $orgName = trim((string) ($organization->org_name ?? 'Organization'));
        $net = number_format((float) $line->net_pay, 2);

        $subject = "Salary Payment Receipt — {$periodLabel}";
        $body = "Dear {$name},\n\nPlease find attached your salary payment receipt for {$periodLabel}.\n\nNet pay: KES {$net}\n\nRegards,\n{$orgName}";

        $pdf = $this->buildPdfBinary($run, $line, $organization);
        $filename = $this->attachmentFilename($run, $line, $employee);

        $ok = $this->mail->sendRaw(
            $organization,
            $to,
            $subject,
            $body,
            false,
            [[
                'data' => $pdf,
                'name' => $filename,
                'mime' => 'application/pdf',
            ]],
        );

        return [
            'sent' => $ok,
            'to' => $to,
            'skipped_reason' => $ok ? null : 'Email could not be sent',
        ];
    }

    /**
     * @return array{sent: int, skipped: int, failed: int, details: list<array{employee: string, to: ?string, status: string, reason: ?string}>}
     */
    public function emailRun(PayrollRun $run, Organization $organization): array
    {
        $run->loadMissing(['payPeriod', 'lines.employee']);
        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $details = [];

        foreach ($run->lines as $line) {
            $employee = $line->employee;
            $name = $employee
                ? trim((string) ($employee->full_name ?: $employee->employee_code ?: 'Employee'))
                : 'Employee';
            $result = $this->emailLine($run, $line, $organization);
            if ($result['sent']) {
                $sent++;
                $details[] = [
                    'employee' => $name,
                    'to' => $result['to'],
                    'status' => 'sent',
                    'reason' => null,
                ];
            } elseif (($result['skipped_reason'] ?? '') === 'No employee email' || ($result['skipped_reason'] ?? '') === 'Employee missing') {
                $skipped++;
                $details[] = [
                    'employee' => $name,
                    'to' => $result['to'],
                    'status' => 'skipped',
                    'reason' => $result['skipped_reason'],
                ];
            } else {
                $failed++;
                $details[] = [
                    'employee' => $name,
                    'to' => $result['to'],
                    'status' => 'failed',
                    'reason' => $result['skipped_reason'],
                ];
            }
        }

        return compact('sent', 'skipped', 'failed', 'details');
    }

    public function attachmentFilename(PayrollRun $run, PayrollLine $line, Employee $employee): string
    {
        $period = $run->payPeriod?->period_code ?: ('run-'.$run->id);
        $code = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($employee->employee_code ?: $employee->id)) ?: 'employee';

        return "payroll-receipt-{$period}-{$code}.pdf";
    }

    public function buildPdfBinary(PayrollRun $run, PayrollLine $line, Organization $organization): string
    {
        $html = $this->buildHtml($run, $line, $organization);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function buildHtml(PayrollRun $run, PayrollLine $line, Organization $organization): string
    {
        $line->loadMissing('employee');
        $employee = $line->employee;
        $period = $run->payPeriod;
        $orgName = e((string) ($organization->org_name ?? 'Organization'));
        $general = GeneralSettingsResolver::forOrganization($organization);
        $footer = trim((string) ($general['print_footer_payroll_receipt'] ?? $general['document_footer_text'] ?? ''));
        $footerHtml = '';
        if ($footer !== '') {
            $footerLines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $footer) ?: [])));
            if ($footerLines !== []) {
                $footerHtml = '<div style="margin-top:14px;padding-top:10px;border-top:1px solid #cbd5e1;font-size:10px;color:#64748b;text-align:center;">'
                    .implode('<br>', array_map(static fn ($line) => e($line), $footerLines))
                    .'</div>';
            }
        }
        $periodLabel = e((string) (
            $period?->period_code
            ?: trim(($period?->period_start?->format('d M Y') ?? '').' – '.($period?->period_end?->format('d M Y') ?? ''))
        ));
        $name = e((string) (
            $employee?->full_name
            ?: trim(($employee?->first_name ?? '').' '.($employee?->last_name ?? ''))
            ?: 'Employee'
        ));
        $code = e((string) ($employee?->employee_code ?? ''));
        $meta = is_array($line->statutory_meta) ? $line->statutory_meta : [];
        $payroll = is_array($meta['payroll'] ?? null) ? $meta['payroll'] : [];

        $rows = [
            ['Gross / contract pay', $meta['statutory_gross'] ?? $payroll['contract_gross_for_statutory'] ?? $line->gross_pay],
            ['Payable amount', $meta['period_gross'] ?? $line->gross_pay],
        ];

        $lateMinutes = (int) ($payroll['attendance']['late_minutes_total'] ?? 0);
        if ($lateMinutes > 0) {
            $clockIn = (int) ($payroll['attendance']['clock_in_late_minutes_total'] ?? 0);
            $lunch = (int) ($payroll['attendance']['lunch_late_minutes_total'] ?? 0);
            $note = 'Lateness ('.$lateMinutes.' min)';
            if ($clockIn > 0 && $lunch > 0) {
                $note = 'Lateness ('.$lateMinutes.' min: '.$clockIn.' clock-in + '.$lunch.' lunch)';
            } elseif ($lunch > 0 && $clockIn <= 0) {
                $note = 'Lateness from lunch ('.$lateMinutes.' min)';
            }
            $rows[] = [$note, null, 'note'];
        }

        $rows[] = ['NSSF (member)', $line->nssf];
        $rows[] = ['SHIF', $line->shif];
        $rows[] = ['Housing levy', $line->housing_levy];
        $rows[] = ['PAYE', $line->paye];

        $detail = is_array($payroll['deductions_detail'] ?? null) ? $payroll['deductions_detail'] : [];
        $hasDetail = false;
        foreach ($detail as $item) {
            $amount = (float) ($item['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $hasDetail = true;
            $rows[] = [(string) ($item['name'] ?? 'Deduction'), $amount];
        }
        if (! $hasDetail && (float) $line->other_deductions > 0) {
            $rows[] = ['Other deductions', $line->other_deductions];
        }

        $rows[] = ['Total deductions', $line->deductions];
        $rows[] = ['Net pay', $line->net_pay];

        $rowHtml = '';
        foreach ($rows as $row) {
            $label = e((string) $row[0]);
            if (($row[2] ?? null) === 'note') {
                $rowHtml .= '<tr><td style="padding:4px 0;color:#64748b;" colspan="2">'.$label.'</td></tr>';

                continue;
            }
            $amt = $this->money($row[1] ?? 0);
            $bold = str_contains(strtolower((string) $row[0]), 'net') || str_contains(strtolower((string) $row[0]), 'total');
            $weight = $bold ? 'font-weight:700;' : '';
            $rowHtml .= '<tr>'
                .'<td style="padding:5px 0;color:#334155;'.$weight.'">'.$label.'</td>'
                .'<td style="padding:5px 0;text-align:right;'.$weight.'">KES '.$amt.'</td>'
                .'</tr>';
        }

        $paidNote = '';
        if ($run->paid_at) {
            $paidNote = '<p style="margin-top:14px;font-size:11px;color:#0f766e;">Paid '
                .e($run->paid_at->format('d M Y'))
                .($run->payment_reference ? ' · Ref '.e((string) $run->payment_reference) : '')
                .'</p>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans,sans-serif;color:#0f172a;font-size:12px;margin:24px;}
            h1{font-size:16px;margin:0 0 4px;}
            .muted{color:#64748b;font-size:11px;}
            table{width:100%;border-collapse:collapse;margin-top:8px;}
            .box{border:1px solid #cbd5e1;border-radius:8px;padding:16px;max-width:420px;}
        </style></head><body>
            <div class="box">
                <div class="muted" style="text-transform:uppercase;letter-spacing:.06em;">'.$orgName.'</div>
                <h1>Salary Payment Receipt</h1>
                <p class="muted" style="margin:0 0 12px;">'.$periodLabel.'</p>
                <p style="margin:0;font-weight:700;">'.$name.'</p>
                '.($code !== '' ? '<p class="muted" style="margin:2px 0 12px;">#'.$code.'</p>' : '<div style="height:12px;"></div>').'
                <table>'.$rowHtml.'</table>
                '.$paidNote.'
                '.$footerHtml.'
            </div>
        </body></html>';
    }

    protected function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', ',');
    }
}
