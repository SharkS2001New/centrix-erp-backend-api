<?php

namespace App\Services\Background;

use Dompdf\Dompdf;
use Dompdf\Options;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;

class ReportExportService
{
    private const FILE_PROGRESS_CHUNK = 250;

    public function __construct(
        protected ReportBrandingService $branding,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  array<string, mixed>|null  $footerRow
     * @param  callable(callable(list<array<string, mixed>>): void): void  $streamSource
     * @return array{disk_path: string, filename: string, mime_type: string, row_count: int, truncated?: bool, pdf_truncated?: bool}
     */
    public function generateStreaming(
        string $format,
        string $basename,
        array $meta,
        array $columns,
        ?array $footerRow,
        int $organizationId,
        string $taskId,
        callable $streamSource,
        ?callable $onProgress = null,
    ): array {
        $format = strtolower($format);
        if ($organizationId > 0) {
            $meta = $this->branding->enrichMeta($meta, $organizationId);
        }
        $safeBase = $this->sanitizeFilename($basename ?: 'report');
        $exportRoot = trim((string) config('background.export_directory', 'private/exports'), '/');
        $directory = $exportRoot.'/'.$organizationId;
        $fullDirectory = storage_path('app/'.$directory);
        if (! is_dir($fullDirectory)) {
            mkdir($fullDirectory, 0755, true);
        }

        return match ($format) {
            'csv' => $this->streamCsv($directory, $safeBase, $taskId, $meta, $columns, $streamSource, $onProgress),
            'pdf', 'print' => $this->streamPdf($directory, $safeBase, $taskId, $meta, $columns, $footerRow, $streamSource, $onProgress),
            default => $this->streamXlsx($directory, $safeBase, $taskId, $meta, $columns, $footerRow, $streamSource, $onProgress),
        };
    }

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
        return $this->generateStreaming(
            $format,
            $basename,
            $meta,
            $columns,
            $footerRow,
            $organizationId,
            $taskId,
            static function (callable $onBatch) use ($rows): void {
                if ($rows !== []) {
                    $onBatch($rows);
                }
            },
            $onProgress,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  callable(callable(list<array<string, mixed>>): void): void  $streamSource
     * @return array{disk_path: string, filename: string, mime_type: string, row_count: int, truncated?: bool}
     */
    protected function streamCsv(
        string $directory,
        string $basename,
        string $taskId,
        array $meta,
        array $columns,
        callable $streamSource,
        ?callable $onProgress = null,
    ): array {
        $filename = $basename.'-'.$taskId.'.csv';
        $diskPath = $directory.'/'.$filename;
        $absolute = storage_path('app/'.$diskPath);

        $writer = new CsvWriter;
        $writer->openToFile($absolute);

        foreach ($this->metaLines($meta) as $line) {
            $writer->addRow(Row::fromValues([$line]));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(array_map(fn (array $col) => $col['label'], $columns)));

        $rowCount = 0;
        $streamSource(function (array $batch) use ($writer, $columns, &$rowCount, $onProgress): void {
            foreach ($batch as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $writer->addRow(Row::fromValues(
                    array_map(fn (array $col) => $this->cellValue($row, $col), $columns),
                ));
                $rowCount++;
                if ($onProgress !== null && $rowCount % self::FILE_PROGRESS_CHUNK === 0) {
                    $onProgress(90, 'Writing CSV…');
                }
            }
        });

        $writer->close();

        return [
            'disk_path' => $diskPath,
            'filename' => $basename.'.csv',
            'mime_type' => 'text/csv',
            'row_count' => $rowCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  array<string, mixed>|null  $footerRow
     * @param  callable(callable(list<array<string, mixed>>): void): void  $streamSource
     * @return array{disk_path: string, filename: string, mime_type: string, row_count: int, truncated?: bool}
     */
    protected function streamXlsx(
        string $directory,
        string $basename,
        string $taskId,
        array $meta,
        array $columns,
        ?array $footerRow,
        callable $streamSource,
        ?callable $onProgress = null,
    ): array {
        $filename = $basename.'-'.$taskId.'.xlsx';
        $diskPath = $directory.'/'.$filename;
        $absolute = storage_path('app/'.$diskPath);

        $writer = new XlsxWriter;
        $writer->openToFile($absolute);

        foreach ($this->metaLines($meta) as $line) {
            $writer->addRow(Row::fromValues([$line]));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(array_map(fn (array $col) => $col['label'], $columns)));

        $rowCount = 0;
        $streamSource(function (array $batch) use ($writer, $columns, &$rowCount, $onProgress): void {
            foreach ($batch as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $writer->addRow(Row::fromValues(
                    array_map(fn (array $col) => $this->cellValue($row, $col), $columns),
                ));
                $rowCount++;
                if ($onProgress !== null && $rowCount % self::FILE_PROGRESS_CHUNK === 0) {
                    $onProgress(90, 'Writing Excel…');
                }
            }
        });

        if (is_array($footerRow) && $footerRow !== []) {
            $writer->addRow(Row::fromValues(
                array_map(
                    fn (array $col, int $index) => (string) ($footerRow[$col['key']] ?? ($index === 0 ? 'Totals' : '')),
                    $columns,
                    array_keys($columns),
                ),
            ));
        }

        $writer->close();

        return [
            'disk_path' => $diskPath,
            'filename' => $basename.'.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'row_count' => $rowCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  array<string, mixed>|null  $footerRow
     * @param  callable(callable(list<array<string, mixed>>): void): void  $streamSource
     * @return array{disk_path: string, filename: string, mime_type: string, row_count: int, pdf_truncated?: bool, truncated?: bool}
     */
    protected function streamPdf(
        string $directory,
        string $basename,
        string $taskId,
        array $meta,
        array $columns,
        ?array $footerRow,
        callable $streamSource,
        ?callable $onProgress = null,
    ): array {
        $pdfMaxRows = (int) config('background.pdf_max_rows', 2500);
        $filename = $basename.'-'.$taskId.'.pdf';
        $diskPath = $directory.'/'.$filename;
        $absolute = storage_path('app/'.$diskPath);

        $pdfRows = [];
        $rowCount = 0;
        $pdfTruncated = false;
        $sourceTruncated = false;

        $streamSource(function (array $batch) use (&$pdfRows, &$rowCount, &$pdfTruncated, &$sourceTruncated, $pdfMaxRows): void {
            foreach ($batch as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rowCount++;
                if (count($pdfRows) < $pdfMaxRows) {
                    $pdfRows[] = $row;
                } else {
                    $pdfTruncated = true;
                }
            }
        });

        if ($pdfTruncated) {
            $meta = array_merge($meta, [
                'extra_lines' => array_merge(
                    $meta['extra_lines'] ?? [],
                    ['PDF limited to first '.$pdfMaxRows.' rows. Use Excel or CSV for the full export.'],
                ),
            ]);
        }

        if ($onProgress !== null) {
            $onProgress(89, 'Building PDF layout…');
        }

        @ini_set('memory_limit', '768M');

        $html = $this->buildPrintHtml($meta, $columns, $pdfRows, $footerRow, $onProgress);

        if ($onProgress !== null) {
            $onProgress(96, 'Rendering PDF…');
        }

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
            'row_count' => min($rowCount, $pdfMaxRows),
            'pdf_truncated' => $pdfTruncated,
            'truncated' => $sourceTruncated || $pdfTruncated,
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
     * Report title block for PDF/HTML — org name lives in the branded header when enabled.
     *
     * @param  array<string, mixed>  $meta
     * @return list<string>
     */
    protected function reportDetailMetaLines(array $meta): array
    {
        $lines = $this->metaLines($meta);
        $branding = is_array($meta['branding'] ?? null) ? $meta['branding'] : null;
        if ($branding !== null && ($branding['show_header'] ?? false) && $lines !== []) {
            array_shift($lines);
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
        $branding = is_array($meta['branding'] ?? null) ? $meta['branding'] : [];
        $styles = $this->branding->documentStyles();
        $orgHeaderHtml = $this->branding->buildOrgHeaderHtml($branding);
        $watermarkHtml = $this->branding->buildWatermarkHtml($branding);
        $footerText = trim((string) ($branding['document_footer_text'] ?? ''));

        $metaHtml = '';
        foreach ($this->reportDetailMetaLines($meta) as $index => $line) {
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

        $footerHtml = $footerText !== ''
            ? '<div class="doc-footer">'.$escape($footerText).'</div>'
            : '';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'.$title.'</title>
<style>
'.$styles['base'].'
'.$styles['watermark'].'
</style></head><body>
'.$watermarkHtml.'
'.$orgHeaderHtml.'
<div class="meta">'.$metaHtml.'</div>
<table><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody>'.$foot.'</table>
'.$footerHtml.'
</body></html>';
    }

    protected function sanitizeFilename(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $value) ?? 'report');

        return trim($slug, '-') ?: 'report';
    }
}
