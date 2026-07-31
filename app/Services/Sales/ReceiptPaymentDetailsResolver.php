<?php

namespace App\Services\Sales;

class ReceiptPaymentDetailsResolver
{
    public const MAX_BLOCKS = 6;

    public const MAX_LINES_PER_BLOCK = 10;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'title' => 'Payment details',
            'blocks' => [],
            'lines' => [],
            'note' => '',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @return array<string, mixed>|null
     */
    public static function normalize(?array $details): ?array
    {
        if ($details === null) {
            return null;
        }

        $blocks = self::normalizeBlocks($details);
        $lines = [];
        foreach ($blocks as $block) {
            foreach ($block['lines'] as $line) {
                $lines[] = $line;
            }
        }

        if ($lines === [] && trim((string) ($details['note'] ?? '')) === '') {
            return null;
        }

        $title = trim((string) ($details['title'] ?? 'Payment details'));

        return [
            'title' => $title !== '' ? mb_substr($title, 0, 80) : 'Payment details',
            'blocks' => $blocks,
            // Flat lines kept for older print/clients that only read .lines
            'lines' => $lines,
            'note' => mb_substr(trim((string) ($details['note'] ?? '')), 0, 300),
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return list<array{title: string, lines: list<array{label: string, value: string}>}>
     */
    protected static function normalizeBlocks(array $details): array
    {
        $rawBlocks = $details['blocks'] ?? null;
        if (is_array($rawBlocks) && $rawBlocks !== []) {
            $blocks = [];
            foreach ($rawBlocks as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $normalized = self::normalizeBlock($block);
                if ($normalized === null) {
                    continue;
                }
                $blocks[] = $normalized;
                if (count($blocks) >= self::MAX_BLOCKS) {
                    break;
                }
            }

            return $blocks;
        }

        // Legacy single-block { lines: [...] }
        $legacy = self::normalizeBlock([
            'title' => '',
            'lines' => $details['lines'] ?? [],
        ]);

        return $legacy ? [$legacy] : [];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array{title: string, lines: list<array{label: string, value: string}>}|null
     */
    protected static function normalizeBlock(array $block): ?array
    {
        $lines = [];
        foreach ($block['lines'] ?? [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $label = trim((string) ($line['label'] ?? ''));
            $value = trim((string) ($line['value'] ?? ''));
            if ($label === '' && $value === '') {
                continue;
            }
            $lines[] = [
                'label' => mb_substr($label, 0, 60),
                'value' => mb_substr($value, 0, 120),
            ];
            if (count($lines) >= self::MAX_LINES_PER_BLOCK) {
                break;
            }
        }

        $title = mb_substr(trim((string) ($block['title'] ?? '')), 0, 80);
        if ($lines === [] && $title === '') {
            return null;
        }

        return [
            'title' => $title,
            'lines' => $lines,
        ];
    }

    /** @return array<string, string> */
    public static function validationRules(string $prefix = 'receipt_payment_details'): array
    {
        return [
            $prefix => 'nullable|array',
            "{$prefix}.title" => 'nullable|string|max:80',
            "{$prefix}.note" => 'nullable|string|max:300',
            "{$prefix}.lines" => 'nullable|array|max:60',
            "{$prefix}.lines.*.label" => 'nullable|string|max:60',
            "{$prefix}.lines.*.value" => 'nullable|string|max:120',
            "{$prefix}.blocks" => 'nullable|array|max:'.self::MAX_BLOCKS,
            "{$prefix}.blocks.*.title" => 'nullable|string|max:80',
            "{$prefix}.blocks.*.lines" => 'nullable|array|max:'.self::MAX_LINES_PER_BLOCK,
            "{$prefix}.blocks.*.lines.*.label" => 'nullable|string|max:60',
            "{$prefix}.blocks.*.lines.*.value" => 'nullable|string|max:120',
        ];
    }
}
