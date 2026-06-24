<?php

namespace App\Services\Background;

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class ReportExportService
{
    private const PDF_MAX_ROWS = 2500;

    private const FILE_PROGRESS_CHUNK = 250;
    /**
     * @param  array<string, mixed>  $meta
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $footerRow
     * @return array{disk_path: string, filename: string, mime_type: string, row_count: int}
     */
    public function generate(
        string $format,
        string $basename,
        array $meta,
        array $columns,
        array $rows,
        ?array $footerRow = null,
        int $organizationId = 0,
        string $taskId = '',
        ?callable $onProgress = null,
    ): array {
        $format = strtolower($format);
        $safeBase = $this->sanitizeFilename($basename ?: 'report');
        $directory = 'exports/'.$organizationId;
        $fullDirectory = storage_path('app/'.$directory);
        if (! is_dir($fullDirectory)) {
            mkdir($fullDirectory, 0755, true);
        }

        return match ($format) {
            'csv' => $this->writeCsv($directory, $safeBase, $taskId, $meta, $columns, $rows, $onProgress),
            'pdf', 'print' => $this->writePdf($directory, $safeBase, $taskId, $meta, $columns, $rows, $footerRow, $onProgress),
            default => $this->writeXlsx($directory, $safeBase, $taskId, $meta, $columns, $rows, $footerRow, $onProgress),
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @return array{disk_path: string, filename: string, mime_type: string, row_count: int}
     */
    protected function writeCsv(
        string $directory,
        string $basename,
        string $taskId,
        array $meta,
        array $columns,
        array $rows,
        ?callable $onProgress = null,
    ): array {
        $filename = $basename.'-'.$taskId.'.csv';
        $diskPath = $directory.'/'.$filename;
        $absolute = storage_path('app/'.$diskPath);

        $handle = fopen($absolute, 'w');
        if ($handle === false) {
            throw new RuntimeException('Could not create CSV export file.');
        }

        foreach ($this->metaLines($meta) as $line) {
            fputcsv($handle, [$line]);
        }
        fputcsv($handle, []);
        fputcsv($handle, array_map(fn (array $col) => $col['label'], $columns));
        $total = count($rows);
        foreach ($rows as $index => $row) {
            fputcsv($handle, array_map(fn (array $col) => $this->cellValue($row, $col), $columns));
            if ($onProgress !== null && $total > 0 && ($index + 1) % self::FILE_PROGRESS_CHUNK === 0) {
                $onProgress(88 + (int) floor((($index + 1) / $total) * 9), 'Writing CSV…');
            }
        }
        fclose($handle);

        return [
            'disk_path' => $diskPath,
            'filename' => $basename.'.csv',
            'mime_type' => 'text/csv',
            'row_count' => count($rows),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $footerRow
     * @return array{disk_path: string, filename: string, mime_type: string, row_count: int}
     */
    protected function writeXlsx(
        string $directory,
        string $basename,
        string $taskId,
        array $meta,
        array $columns,
        array $rows,
        ?array $footerRow,
        ?callable $onProgress = null,
    ): array {
        $filename = $basename.'-'.$taskId.'.xlsx';
        $diskPath = $directory.'/'.$filename;
        $absolute = storage_path('app/'.$diskPath);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($meta['title'] ?? 'Report', 0, 31));

        $rowIndex = 1;
        foreach ($this->metaLines($meta) as $line) {
            $sheet->setCellValue('A'.$rowIndex, $line);
            $rowIndex++;
        }
        $rowIndex++;

        $sheet->fromArray(array_map(fn (array $col) => $col['label'], $columns), null, 'A'.$rowIndex);
        $rowIndex++;

        $total = count($rows);
        foreach ($rows as $index => $row) {
            $sheet->fromArray(
                array_map(fn (array $col) => $this->cellValue($row, $col), $columns),
                null,
                'A'.$rowIndex,
            );
            $rowIndex++;
            if ($onProgress !== null && $total > 0 && ($index + 1) % self::FILE_PROGRESS_CHUNK === 0) {
                $onProgress(88 + (int) floor((($index + 1) / $total) * 9), 'Writing Excel…');
            }
        }

        if (is_array($footerRow) && $footerRow !== []) {
            $sheet->fromArray(
                array_map(fn (array $col) => (string) ($footerRow[$col['key']] ?? ''), $columns),
                null,
                'A'.$rowIndex,
            );
        }

        (new Xlsx($spreadsheet))->save($absolute);

        return [
            'disk_path' => $diskPath,
            'filename' => $basename.'.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'row_count' => count($rows),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $footerRow
     * @return array{disk_path: string, filename: string, mime_type: string, row_count: int}
     */
    protected function writePdf(
        string $directory,
        string $basename,
        string $taskId,
        array $meta,
        array $columns,
        array $rows,
        ?array $footerRow,
        ?callable $onProgress = null,
    ): array {
        $filename = $basename.'-'.$taskId.'.pdf';
        $diskPath = $directory.'/'.$filename;
        $absolute = storage_path('app/'.$diskPath);

        $exportRows = $rows;
        $pdfTruncated = false;
        if (count($exportRows) > self::PDF_MAX_ROWS) {
            $exportRows = array_slice($exportRows, 0, self::PDF_MAX_ROWS);
            $pdfTruncated = true;
            $meta = array_merge($meta, [
                'extra_lines' => array_merge(
                    $meta['extra_lines'] ?? [],
                    ['PDF limited to first '.self::PDF_MAX_ROWS.' rows. Use Excel or CSV for the full export.'],
                ),
            ]);
        }

        if ($onProgress !== null) {
            $onProgress(89, 'Building PDF layout…');
        }
        $html = $this->buildPrintHtml($meta, $columns, $exportRows, $footerRow, $onProgress);

        if ($onProgress !== null) {
            $onProgress(96, 'Rendering PDF…');
        }

        @ini_set('memory_limit', '512M');

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        file_put_contents($absolute, $dompdf->output());

        return [
            'disk_path' => $diskPath,
            'filename' => $basename.'.pdf',
            'mime_type' => 'application/pdf',
            'row_count' => count($exportRows),
            'pdf_truncated' => $pdfTruncated,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<string>
     */
    protected function metaLines(array $meta): array
    {
        $lines = [];
        if (! empty($meta['organization_name'])) {
            $lines[] = (string) $meta['organization_name'];
        }
        if (! empty($meta['title'])) {
            $lines[] = (string) $meta['title'];
        }
        if (! empty($meta['subtitle'])) {
            $lines[] = (string) $meta['subtitle'];
        }
        if (! empty($meta['from_date']) || ! empty($meta['to_date'])) {
            $lines[] = 'Period: '.($meta['from_date'] ?: '—').' – '.($meta['to_date'] ?: '—');
        }
        if (! empty($meta['branch_name'])) {
            $lines[] = 'Branch: '.$meta['branch_name'];
        }
        foreach ($meta['extra_lines'] ?? [] as $line) {
            if (is_string($line) && $line !== '') {
                $lines[] = $line;
            }
        }
        if (! empty($meta['printed_at'])) {
            $lines[] = 'Printed: '.$meta['printed_at'];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{key: string, label: string, align?: string}  $column
     */
    protected function cellValue(array $row, array $column): string
    {
        $value = $row[$column['key']] ?? '';
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value) ?: '';
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $footerRow
     */
    protected function buildPrintHtml(
        array $meta,
        array $columns,
        array $rows,
        ?array $footerRow,
        ?callable $onProgress = null,
    ): string {
        $escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $metaHtml = '';
        foreach ($this->metaLines($meta) as $index => $line) {
            $metaHtml .= $index === 0
                ? '<h1>'.$escape($line).'</h1>'
                : '<p>'.$escape($line).'</p>';
        }

        $head = '';
        foreach ($columns as $column) {
            $class = ($column['align'] ?? '') === 'right' ? ' class="num"' : '';
            $head .= '<th'.$class.'>'.$escape($column['label']).'</th>';
        }

        $body = '';
        $total = count($rows);
        foreach ($rows as $index => $row) {
            $body .= '<tr>';
            foreach ($columns as $column) {
                $class = ($column['align'] ?? '') === 'right' ? ' class="num"' : '';
                $body .= '<td'.$class.'>'.$escape($this->cellValue($row, $column)).'</td>';
            }
            $body .= '</tr>';
            if ($onProgress !== null && $total > 0 && ($index + 1) % self::FILE_PROGRESS_CHUNK === 0) {
                $onProgress(90 + (int) floor((($index + 1) / $total) * 5), 'Building PDF rows…');
            }
        }

        $foot = '';
        if (is_array($footerRow) && $footerRow !== []) {
            $foot .= '<tfoot><tr>';
            foreach ($columns as $index => $column) {
                $class = ($column['align'] ?? '') === 'right' ? ' class="num"' : '';
                $value = $footerRow[$column['key']] ?? ($index === 0 ? 'Totals' : '');
                $foot .= '<td'.$class.'>'.$escape($value).'</td>';
            }
            $foot .= '</tr></tfoot>';
        }

        $title = $escape($meta['title'] ?? 'Report');

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'.$title.'</title>
<style>
body { font-family: DejaVu Sans, sans-serif; padding: 24px; color: #111; font-size: 11px; }
.meta { margin-bottom: 20px; }
.meta h1 { font-size: 18px; margin: 0 0 4px; }
.meta p { margin: 2px 0; font-size: 12px; color: #475569; }
table { width: 100%; border-collapse: collapse; }
th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
th { background: #f8fafc; }
td.num, th.num { text-align: right; }
tfoot td { font-weight: 600; background: #f8fafc; }
</style></head><body>
<div class="meta">'.$metaHtml.'</div>
<table><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody>'.$foot.'</table>
</body></html>';
    }

    protected function sanitizeFilename(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $value) ?? 'report');

        return trim($slug, '-') ?: 'report';
    }
}
