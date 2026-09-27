<?php

namespace App\Services\Ai;

/**
 * Blocks model hallucinations that claim a create/save already succeeded
 * when Centrix has not executed the write (no action_result / document id).
 */
class AiFalseWriteSuccessGuard
{
    public function looksLikeFalseWriteSuccess(string $reply): bool
    {
        return (bool) preg_match(
            '/\b('
            .'(?:i(?:\'ve| have)?|we(?:\'ve| have)?|lpo|purchase\s+order|product|supplier|customer|order)\b'
            .'.{0,80}\b(?:created|saved|posted|raised|drafted|generated|submitted)\b'
            .'|'
            .'\b(?:created|saved|posted|raised|drafted)\b.{0,40}\b(?:lpo|purchase\s+order|successfully)\b'
            .')/i',
            $reply,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $pending
     * @return array<string, mixed>
     */
    public function scrub(array $result, ?array $pending = null): array
    {
        if (! empty($result['action_result'])) {
            return $result;
        }

        $reply = (string) ($result['reply'] ?? $result['message'] ?? '');
        if ($reply === '' || ! $this->looksLikeFalseWriteSuccess($reply)) {
            return $result;
        }

        $type = (string) ($pending['type'] ?? '');
        $replacement = match ($type) {
            'create_lpo' => 'Nothing has been saved yet. Share the supplier and line items (or a sales order to copy from), then reply **confirm** so I can create the LPO in Centrix.',
            'create_product', 'update_product' => 'Nothing has been saved yet. Share any missing product details, then reply **confirm** so I can save it in Centrix.',
            'create_supplier', 'create_customer' => 'Nothing has been saved yet. Share any missing details, then reply **confirm** so I can create it in Centrix.',
            default => $type !== ''
                ? 'Nothing has been saved yet. Share any missing details, then reply **confirm** so I can create it in Centrix.'
                : 'Nothing has been saved yet. Share any missing details, then reply **confirm** so I can create it in Centrix.',
        };

        $result['reply'] = $replacement;
        $result['message'] = $replacement;
        $result['false_success_scrubbed'] = true;

        return $result;
    }
}
