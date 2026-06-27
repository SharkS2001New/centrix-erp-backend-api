<?php

namespace App\Services\Sales;

class ReceiptPaymentDetailsResolver
{
  /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'title' => 'Payment details',
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

        $lines = [];
        foreach ($details['lines'] ?? [] as $line) {
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
            if (count($lines) >= 12) {
                break;
            }
        }

        if ($lines === [] && trim((string) ($details['note'] ?? '')) === '') {
            return null;
        }

        $title = trim((string) ($details['title'] ?? 'Payment details'));

        return [
            'title' => $title !== '' ? mb_substr($title, 0, 80) : 'Payment details',
            'lines' => $lines,
            'note' => mb_substr(trim((string) ($details['note'] ?? '')), 0, 300),
        ];
    }

    /** @return array<string, string> */
    public static function validationRules(string $prefix = 'receipt_payment_details'): array
    {
        return [
            $prefix => 'nullable|array',
            "{$prefix}.title" => 'nullable|string|max:80',
            "{$prefix}.note" => 'nullable|string|max:300',
            "{$prefix}.lines" => 'nullable|array|max:12',
            "{$prefix}.lines.*.label" => 'required_with:'.$prefix.'.lines|nullable|string|max:60',
            "{$prefix}.lines.*.value" => 'nullable|string|max:120',
        ];
    }
}
