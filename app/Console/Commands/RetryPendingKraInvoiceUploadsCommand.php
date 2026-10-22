<?php

namespace App\Console\Commands;

use App\Services\Kra\KraInvoiceUploadRetryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RetryPendingKraInvoiceUploadsCommand extends Command
{
    protected $signature = 'erp:retry-pending-kra-invoice-uploads
                            {--organization_id= : Limit to one organization}
                            {--limit=50 : Max signed documents to process}';

    protected $description = 'Retry queuing signed KRA invoices and credit notes for online eTIMS upload';

    public function handle(KraInvoiceUploadRetryService $uploads): int
    {
        $organizationId = $this->option('organization_id')
            ? (int) $this->option('organization_id')
            : null;
        $stats = $uploads->retryPending(max(1, (int) $this->option('limit')), $organizationId);

        $this->info(sprintf(
            'KRA eTIMS upload retry: attempted=%d queued=%d still_pending=%d skipped=%d',
            $stats['attempted'],
            $stats['queued'],
            $stats['still_pending'],
            $stats['skipped'],
        ));
        Log::info('erp:retry-pending-kra-invoice-uploads', $stats);

        return self::SUCCESS;
    }
}