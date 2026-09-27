<?php

namespace App\Services\Purchasing;

/**
 * Shared open / print paths for LPO documents in AI chat replies.
 *
 * Print uses the same LPO page print template (frontend professional A4 /
 * org document settings) — not a separate Dompdf layout.
 */
class LpoAiDocumentLinks
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forLpo(int|string $lpoNo): array
    {
        $no = (int) $lpoNo;
        if ($no <= 0) {
            return [];
        }

        return [
            [
                'label' => 'Open LPO',
                'kind' => 'open',
                'path' => '/lpo/'.$no,
                'lpo_no' => $no,
            ],
            [
                // Same print pipeline as Purchasing → LPO → Print (Save as PDF from the dialog).
                'label' => 'Print / Save PDF',
                'kind' => 'print',
                'path' => '/lpo/'.$no.'/print',
                'lpo_no' => $no,
                'use_lpo_print' => true,
            ],
            [
                'label' => 'Receive goods',
                'kind' => 'receive',
                'path' => '/lpo/'.$no.'/receive',
                'lpo_no' => $no,
            ],
        ];
    }
}
