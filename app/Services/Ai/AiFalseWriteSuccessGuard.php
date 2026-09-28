<?php

namespace App\Services\Ai;

/**
 * Blocks model hallucinations that claim a create/save already succeeded
 * when Centrix has not executed the write (no action_result / document id).
 *
 * Deliberately narrow: how-to answers often say “order that was created”
 * or “after the order is saved” and must NOT be rewritten into a create/confirm prompt.
 */
class AiFalseWriteSuccessGuard
{
    public function looksLikeFalseWriteSuccess(string $reply): bool
    {
        // First-person / team claims of having written something.
        if (preg_match(
            '/\b(?:i(?:\'ve| have)?|we(?:\'ve| have)?)\b.{0,60}\b(?:created|saved|posted|raised|drafted|generated|submitted)\b/i',
            $reply,
        )) {
            return true;
        }

        // Explicit document success (LPO / product / supplier / customer) — not bare “order”.
        if (preg_match(
            '/\b(?:lpo|purchase\s+order|product|supplier|customer)\b.{0,60}\b(?:created|saved|posted|raised|drafted|generated)\b/i',
            $reply,
        )) {
            return true;
        }

        if (preg_match(
            '/\b(?:created|saved|posted|raised|drafted)\b.{0,40}\b(?:successfully|lpo|purchase\s+order)\b/i',
            $reply,
        )) {
            return true;
        }

        // “Updated/prepared draft LPO” sounds finished even when nothing was POSTed.
        if (preg_match(
            '/\b(?:updated|prepared|saved|created)\s+(?:a\s+|the\s+)?draft\s+(?:lpo|purchase\s+order)\b/i',
            $reply,
        )) {
            return true;
        }

        return false;
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

        // How-to / FAQ answers must not be replaced with a create prompt.
        if ($this->looksLikeHowToGuidance($reply)) {
            return $result;
        }

        $type = (string) ($pending['type'] ?? '');
        $replacement = match ($type) {
            'create_lpo' => 'Nothing has been saved yet. Share the supplier and line items (or a sales order to copy from), then reply **confirm** so I can create the LPO in Centrix.',
            'create_product', 'update_product' => 'Nothing has been saved yet. Share any missing product details, then reply **confirm** so I can save it in Centrix.',
            'create_supplier', 'create_customer' => 'Nothing has been saved yet. Share any missing details, then reply **confirm** so I can create it in Centrix.',
            default => 'Nothing has been saved yet. Share any missing details, then reply **confirm** so I can create it in Centrix.',
        };

        $result['reply'] = $replacement;
        $result['message'] = $replacement;
        $result['false_success_scrubbed'] = true;

        return $result;
    }

    protected function looksLikeHowToGuidance(string $reply): bool
    {
        return (bool) preg_match(
            '/\b(open|go to|navigate|path|\/sales\/|how to|steps?|cancel (this |the )?order|credit note|held order|void)\b/i',
            $reply,
        );
    }
}
