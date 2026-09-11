<?php

namespace App\Jobs;

use App\Services\Sales\CreditNoteService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * After return-approve HTTP response: attempt KRA credit note so the till is not
 * blocked on Centrix KRA Agent / Comstore latency (mirrors checkout soft-fail UX).
 *
 * Not queued — afterResponse() runs in-process once the response is sent.
 * Daytime cron still retries anything left pending.
 */
class FinalizeReturnKraCreditJob
{
    use Dispatchable;

    public function __construct(public int $creditNoteId) {}

    public function handle(CreditNoteService $credits): void
    {
        try {
            $credits->attemptKraForCreditNote($this->creditNoteId);
        } catch (\Throwable $e) {
            Log::warning('Background return KRA credit failed', [
                'credit_note_id' => $this->creditNoteId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
