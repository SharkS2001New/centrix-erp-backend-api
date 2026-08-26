<?php

namespace App\Services\Purchasing;

/**
 * Shared download / print / open paths for LPO documents in AI chat replies.
 */
class LpoAiDocumentLinks
{
    /**
     * @return list<array{label: string, kind: string, path?: string, api_path?: string, download?: bool}>
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
            ],
            [
                'label' => 'Print LPO',
                'kind' => 'print',
                'path' => '/lpo/'.$no.'/print',
            ],
            [
                'label' => 'Download PDF',
                'kind' => 'pdf',
                'api_path' => '/lpo-mst/'.$no.'/pdf',
                'filename' => 'LPO-'.$no.'.pdf',
                'download' => true,
            ],
            [
                'label' => 'Receive goods',
                'kind' => 'receive',
                'path' => '/lpo/'.$no.'/receive',
            ],
        ];
    }
}
