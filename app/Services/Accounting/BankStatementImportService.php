<?php

namespace App\Services\Accounting;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Parse bank statement files (CSV / PDF / OFX) into normalized lines and prove
 * opening + activity = closing before the user commits the import.
 */
class BankStatementImportService
{
    public function __construct(
        protected BankReconciliationService $reconciliations,
    ) {}

    /**
     * @return array{
     *   format: string,
     *   bank: string|null,
     *   filename: string|null,
     *   stored_path: string|null,
     *   opening_balance: float|null,
     *   closing_balance: float|null,
     *   period_start: string|null,
     *   period_end: string|null,
     *   lines: list<array{line_date: string, description: string, reference: string, amount: float}>,
     *   line_count: int,
     *   activity_sum: float,
     *   ties_out: bool|null,
     *   difference: float|null,
     *   warnings: list<string>
     * }
     */
    public function previewFromUpload(
        ?UploadedFile $file = null,
        ?string $csv = null,
        ?string $formatHint = null,
        ?int $organizationId = null,
        bool $storeFile = false,
    ): array {
        $filename = $file?->getClientOriginalName();
        $format = $this->resolveFormat($file, $csv, $formatHint);
        $raw = null;
        $storedPath = null;

        if ($file !== null) {
            $raw = (string) file_get_contents($file->getRealPath());
            if ($storeFile && $organizationId) {
                $storedPath = $this->storeUpload($organizationId, $file);
            }
        } elseif ($csv !== null && trim($csv) !== '') {
            $raw = $csv;
            $format = 'csv';
        } else {
            throw new InvalidArgumentException('Upload a bank statement file or paste CSV text.');
        }

        $parsed = match ($format) {
            'csv' => $this->parseCsv($raw),
            'ofx', 'qfx' => $this->parseOfx($raw),
            'pdf' => $this->parsePdf($raw),
            default => throw new InvalidArgumentException("Unsupported statement format: {$format}"),
        };

        $parsed['format'] = $format;
        $parsed['filename'] = $filename;
        $parsed['stored_path'] = $storedPath;

        return $this->withTieOut($parsed);
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return list<array{line_date: string, description: string, reference: string, amount: float}>
     */
    public function linesFromPreview(array $preview, bool $requireTieOut = true): array
    {
        $lines = $preview['lines'] ?? [];
        if (! is_array($lines) || $lines === []) {
            throw new InvalidArgumentException('No statement lines were extracted from the file.');
        }

        if ($requireTieOut && array_key_exists('ties_out', $preview) && $preview['ties_out'] === false) {
            $diff = number_format((float) ($preview['difference'] ?? 0), 2);
            throw new InvalidArgumentException(
                "Statement extract does not tie out (difference {$diff}). "
                .'Review the preview, fix rows, or confirm import of an unbalanced extract.',
            );
        }

        return array_values(array_map(function ($line) {
            return [
                'line_date' => (string) ($line['line_date'] ?? $line['date'] ?? ''),
                'description' => (string) ($line['description'] ?? ''),
                'reference' => (string) ($line['reference'] ?? ''),
                'amount' => (float) ($line['amount'] ?? 0),
            ];
        }, $lines));
    }

    protected function resolveFormat(?UploadedFile $file, ?string $csv, ?string $hint): string
    {
        $hint = strtolower(trim((string) $hint));
        if (in_array($hint, ['csv', 'pdf', 'ofx', 'qfx'], true)) {
            return $hint === 'qfx' ? 'ofx' : $hint;
        }

        if ($file) {
            $ext = strtolower((string) $file->getClientOriginalExtension());
            $mime = strtolower((string) $file->getMimeType());
            if (in_array($ext, ['ofx', 'qfx'], true) || str_contains($mime, 'ofx')) {
                return 'ofx';
            }
            if ($ext === 'pdf' || str_contains($mime, 'pdf')) {
                return 'pdf';
            }
            if (in_array($ext, ['csv', 'txt'], true) || str_contains($mime, 'csv') || str_contains($mime, 'text')) {
                return 'csv';
            }
        }

        if ($csv !== null && trim($csv) !== '') {
            return 'csv';
        }

        throw new InvalidArgumentException('Could not detect statement format. Use CSV, PDF, OFX, or QFX.');
    }

    protected function storeUpload(int $organizationId, UploadedFile $file): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file->getClientOriginalName()) ?: 'statement';
        $path = sprintf(
            'bank-statements/%d/%s_%s',
            $organizationId,
            now()->format('YmdHis'),
            $safe,
        );
        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()) ?: '');

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseCsv(string $csv): array
    {
        $lines = $this->reconciliations->parseCsvRows($csv);
        $this->reconciliations->assertCsvParsed($csv, $lines);

        return [
            'bank' => null,
            'opening_balance' => null,
            'closing_balance' => null,
            'period_start' => $lines[0]['line_date'] ?? null,
            'period_end' => $lines[array_key_last($lines)]['line_date'] ?? null,
            'lines' => $lines,
            'warnings' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function parseOfx(string $raw): array
    {
        $warnings = [];
        $bank = null;
        if (preg_match('/<(?:ORG|FI)>([^<\n]+)/i', $raw, $m)) {
            $bank = trim($m[1]);
        }

        $opening = $this->ofxBalance($raw, 'LEDGERBAL') ?? $this->ofxBalance($raw, 'AVAILBAL');
        $closing = $this->ofxBalance($raw, 'LEDGERBAL');

        $dtStart = $this->ofxDate(preg_match('/<DTSTART>(\d{8})/i', $raw, $m) ? $m[1] : null);
        $dtEnd = $this->ofxDate(preg_match('/<DTEND>(\d{8})/i', $raw, $m) ? $m[1] : null);

        $lines = [];
        if (preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $raw, $matches)) {
            foreach ($matches[1] as $block) {
                $amount = $this->parseLooseAmount(
                    preg_match('/<TRNAMT>([^<\n]+)/i', $block, $m) ? $m[1] : '',
                );
                $date = $this->ofxDate(
                    preg_match('/<DTPOSTED>(\d{8})/i', $block, $m) ? $m[1] : null,
                );
                if ($amount === null || $date === null) {
                    continue;
                }
                $memo = trim(
                    (preg_match('/<NAME>([^<\n]+)/i', $block, $m) ? $m[1] : '')
                    .' '
                    .(preg_match('/<MEMO>([^<\n]+)/i', $block, $m) ? $m[1] : ''),
                );
                $fitid = preg_match('/<FITID>([^<\n]+)/i', $block, $m) ? trim($m[1]) : '';
                $checknum = preg_match('/<CHECKNUM>([^<\n]+)/i', $block, $m) ? trim($m[1]) : '';

                $lines[] = [
                    'line_date' => $date,
                    'description' => $memo !== '' ? $memo : 'OFX transaction',
                    'reference' => $checknum !== '' ? $checknum : $fitid,
                    'amount' => $amount,
                ];
            }
        }

        if ($lines === []) {
            throw new InvalidArgumentException('No transactions found in the OFX/QFX file.');
        }

        return [
            'bank' => $bank,
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'period_start' => $dtStart ?? ($lines[0]['line_date'] ?? null),
            'period_end' => $dtEnd ?? ($lines[array_key_last($lines)]['line_date'] ?? null),
            'lines' => $lines,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function parsePdf(string $binary): array
    {
        try {
            $parser = new PdfParser;
            $pdf = $parser->parseContent($binary);
            $text = trim((string) $pdf->getText());
        } catch (\Throwable $e) {
            throw new InvalidArgumentException(
                'Could not read this PDF. Use a digital (text) bank statement, or export CSV/OFX from your bank. '
                .'(Scanned image PDFs are not supported yet.)',
            );
        }

        if ($text === '' || mb_strlen(preg_replace('/\s+/', '', $text) ?? '') < 20) {
            throw new InvalidArgumentException(
                'This PDF has no readable text layer (likely a scan). Export CSV/OFX from your bank, or convert the statement to CSV.',
            );
        }

        return $this->parseBankStatementText($text);
    }

    /**
     * Generic + Kenyan-bank heuristics over extracted statement text.
     *
     * @return array<string, mixed>
     */
    public function parseBankStatementText(string $text): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $bank = $this->fingerprintBank($normalized);
        $opening = $this->extractLabeledBalance($normalized, [
            'opening balance', 'opening bal', 'balance brought forward', 'b/f', 'brought forward',
            'previous balance', 'opening ledger balance',
        ]);
        $closing = $this->extractLabeledBalance($normalized, [
            'closing balance', 'closing bal', 'balance carried forward', 'c/f', 'carried forward',
            'ending balance', 'closing ledger balance', 'available balance',
        ]);

        $period = $this->extractPeriod($normalized);
        $lines = $this->extractTransactionLines($normalized);

        $warnings = [];
        if ($lines === []) {
            throw new InvalidArgumentException(
                'Could not extract transactions from this PDF. Try CSV/OFX from your bank, or a clearer digital statement.',
            );
        }
        if ($opening === null || $closing === null) {
            $warnings[] = 'Opening/closing balance could not be read from the PDF — tie-out may be unavailable.';
        }

        return [
            'bank' => $bank,
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'period_start' => $period['start'] ?? ($lines[0]['line_date'] ?? null),
            'period_end' => $period['end'] ?? ($lines[array_key_last($lines)]['line_date'] ?? null),
            'lines' => $lines,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    protected function withTieOut(array $parsed): array
    {
        $lines = $parsed['lines'] ?? [];
        $sum = 0.0;
        foreach ($lines as $line) {
            $sum += (float) ($line['amount'] ?? 0);
        }
        $sum = round($sum, 2);

        $opening = isset($parsed['opening_balance']) && $parsed['opening_balance'] !== null
            ? round((float) $parsed['opening_balance'], 2)
            : null;
        $closing = isset($parsed['closing_balance']) && $parsed['closing_balance'] !== null
            ? round((float) $parsed['closing_balance'], 2)
            : null;

        $tiesOut = null;
        $difference = null;
        if ($opening !== null && $closing !== null) {
            $expected = round($opening + $sum, 2);
            $difference = round($closing - $expected, 2);
            $tiesOut = abs($difference) < 0.02;
        }

        $parsed['line_count'] = count($lines);
        $parsed['activity_sum'] = $sum;
        $parsed['ties_out'] = $tiesOut;
        $parsed['difference'] = $difference;
        $parsed['opening_balance'] = $opening;
        $parsed['closing_balance'] = $closing;

        return $parsed;
    }

    protected function fingerprintBank(string $text): ?string
    {
        $upper = strtoupper($text);
        $banks = [
            'EQUITY BANK' => 'Equity',
            'EQUITY' => 'Equity',
            'KENYA COMMERCIAL BANK' => 'KCB',
            'KCB BANK' => 'KCB',
            'KCB' => 'KCB',
            'CO-OPERATIVE BANK' => 'Co-op',
            'COOPERATIVE BANK' => 'Co-op',
            'CO-OP BANK' => 'Co-op',
            'ABSA BANK' => 'Absa',
            'BARCLAYS' => 'Absa',
            'STANBIC' => 'Stanbic',
            'STANDARD CHARTERED' => 'StanChart',
            'NCBA' => 'NCBA',
            'I&M BANK' => 'I&M',
        ];
        foreach ($banks as $needle => $label) {
            if (str_contains($upper, $needle)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $labels
     */
    protected function extractLabeledBalance(string $text, array $labels): ?float
    {
        foreach ($labels as $label) {
            $quoted = preg_quote($label, '/');
            if (preg_match(
                '/'.$quoted.'\s*[:\-]?\s*(?:KES|KSH|USD|EUR)?\s*([(\-]?\d[\d,]*(?:\.\d{1,2})?\)?)/i',
                $text,
                $m,
            )) {
                $amount = $this->parseLooseAmount($m[1]);
                if ($amount !== null) {
                    return $amount;
                }
            }
        }

        return null;
    }

    /**
     * @return array{start: ?string, end: ?string}
     */
    protected function extractPeriod(string $text): array
    {
        if (preg_match(
            '/(?:statement\s+period|period|from)\s*[:\-]?\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\d{4}-\d{2}-\d{2})\s*(?:to|-|–)\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\d{4}-\d{2}-\d{2})/i',
            $text,
            $m,
        )) {
            return [
                'start' => $this->normalizeLooseDate($m[1]),
                'end' => $this->normalizeLooseDate($m[2]),
            ];
        }

        return ['start' => null, 'end' => null];
    }

    /**
     * @return list<array{line_date: string, description: string, reference: string, amount: float}>
     */
    protected function extractTransactionLines(string $text): array
    {
        $lines = [];
        $rows = preg_split('/\n+/', $text) ?: [];

        foreach ($rows as $row) {
            $row = trim(preg_replace('/\s+/', ' ', $row) ?? '');
            if ($row === '' || mb_strlen($row) < 8) {
                continue;
            }
            if ($this->looksLikeNoiseRow($row)) {
                continue;
            }

            $parsed = $this->parseTransactionRow($row);
            if ($parsed !== null) {
                $lines[] = $parsed;
            }
        }

        return $this->dedupeLines($lines);
    }

    protected function looksLikeNoiseRow(string $row): bool
    {
        $lower = strtolower($row);
        foreach ([
            'opening balance', 'closing balance', 'page ', 'statement period',
            'account number', 'customer name', 'total credits', 'total debits',
            'brought forward', 'carried forward', 'available balance',
            'transaction date', 'value date', 'narration', 'particulars',
        ] as $noise) {
            if (str_starts_with($lower, $noise) || $lower === $noise) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{line_date: string, description: string, reference: string, amount: float}|null
     */
    protected function parseTransactionRow(string $row): ?array
    {
        // Date at start: 01/06/2026, 01-06-2026, 2026-06-01
        if (! preg_match(
            '/^(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\d{4}-\d{2}-\d{2})\b(.*)$/u',
            $row,
            $m,
        )) {
            return null;
        }

        $date = $this->normalizeLooseDate($m[1]);
        if ($date === null) {
            return null;
        }

        $rest = trim($m[2]);

        // Explicit DR/CR markers (common on Kenyan statements)
        if (preg_match(
            '/^(.*?)\s+([(\-]?\d[\d,]*(?:\.\d{1,2})?\)?)\s*(DR|CR|Debit|Credit)\b/i',
            $rest,
            $amt,
        )) {
            $amount = $this->parseLooseAmount($amt[2]);
            if ($amount === null) {
                return null;
            }
            $dir = strtoupper($amt[3]);
            if (str_starts_with($dir, 'D')) {
                $amount = -abs($amount);
            } else {
                $amount = abs($amount);
            }
            [$description, $reference] = $this->splitDescriptionReference(trim($amt[1]));

            return [
                'line_date' => $date,
                'description' => $description,
                'reference' => $reference,
                'amount' => $amount,
            ];
        }

        // Trailing money-in / money-out columns: desc 1,234.00  500.00
        if (preg_match(
            '/^(.*?)\s+([(\-]?\d[\d,]*(?:\.\d{1,2})?\)?)\s+([(\-]?\d[\d,]*(?:\.\d{1,2})?\)?)$/u',
            $rest,
            $cols,
        )) {
            $left = $this->parseLooseAmount($cols[2]);
            $right = $this->parseLooseAmount($cols[3]);
            if ($left === null || $right === null) {
                return null;
            }
            // Prefer non-zero column: first = credit/in, second = debit/out (common layout)
            $amount = null;
            if (abs($left) > 0.0001 && abs($right) < 0.0001) {
                $amount = abs($left);
            } elseif (abs($right) > 0.0001 && abs($left) < 0.0001) {
                $amount = -abs($right);
            } elseif (abs($left) > 0.0001 && abs($right) > 0.0001) {
                // Running balance present — treat last as balance, previous as amount unknown sign
                // Fall through to single-amount parser on left-most money token in desc.
                $rest = trim($cols[1].' '.$cols[2]);
            } else {
                return null;
            }

            if ($amount !== null) {
                [$description, $reference] = $this->splitDescriptionReference(trim($cols[1]));

                return [
                    'line_date' => $date,
                    'description' => $description,
                    'reference' => $reference,
                    'amount' => $amount,
                ];
            }
        }

        // Single trailing amount
        if (preg_match('/^(.*?)\s+([(\-]?\d[\d,]*(?:\.\d{1,2})?\)?)$/u', $rest, $amt)) {
            $amount = $this->parseLooseAmount($amt[2]);
            if ($amount === null || abs($amount) < 0.0001) {
                return null;
            }
            $desc = trim($amt[1]);
            // Heuristic: withdrawals often contain withdraw/charge/fee/transfer out
            $lower = strtolower($desc);
            $negativeHints = ['withdraw', 'charge', 'fee', 'transfer to', 'payment to', 'purchase', 'atm'];
            foreach ($negativeHints as $hint) {
                if (str_contains($lower, $hint)) {
                    $amount = -abs($amount);
                    break;
                }
            }
            [$description, $reference] = $this->splitDescriptionReference($desc);

            return [
                'line_date' => $date,
                'description' => $description,
                'reference' => $reference,
                'amount' => $amount,
            ];
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitDescriptionReference(string $desc): array
    {
        $reference = '';
        if (preg_match('/\b([A-Z]{2,}\d{4,}|\d{6,})\b/', $desc, $m)) {
            $reference = $m[1];
        }

        return [$desc !== '' ? $desc : 'Bank transaction', $reference];
    }

    /**
     * @param  list<array{line_date: string, description: string, reference: string, amount: float}>  $lines
     * @return list<array{line_date: string, description: string, reference: string, amount: float}>
     */
    protected function dedupeLines(array $lines): array
    {
        $seen = [];
        $out = [];
        foreach ($lines as $line) {
            $key = $line['line_date'].'|'.$line['amount'].'|'.mb_strtolower($line['description']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $line;
        }

        return $out;
    }

    protected function ofxBalance(string $raw, string $tag): ?float
    {
        if (preg_match('/<'.$tag.'>.*?<BALAMT>([^<\n]+)/is', $raw, $m)) {
            return $this->parseLooseAmount($m[1]);
        }

        return null;
    }

    protected function ofxDate(?string $yyyymmdd): ?string
    {
        if ($yyyymmdd === null || ! preg_match('/^\d{8}/', $yyyymmdd)) {
            return null;
        }

        return substr($yyyymmdd, 0, 4).'-'.substr($yyyymmdd, 4, 2).'-'.substr($yyyymmdd, 6, 2);
    }

    protected function normalizeLooseDate(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/', $value, $m)) {
            $year = (int) $m[3];
            if ($year < 100) {
                $year += 2000;
            }

            return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
        }
        $ts = strtotime($value);

        return $ts ? date('Y-m-d', $ts) : null;
    }

    protected function parseLooseAmount(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $negative = false;
        if (preg_match('/^\(([\d,\.\s]+)\)$/', $value, $m)) {
            $value = $m[1];
            $negative = true;
        }
        $value = preg_replace('/[^\d.\-]/', '', str_replace([',', ' '], '', $value)) ?? '';
        if ($value === '' || $value === '-' || $value === '.') {
            return null;
        }
        $amount = round((float) $value, 2);

        return $negative ? -abs($amount) : $amount;
    }
}
