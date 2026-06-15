<?php

namespace App\Services\Accounting\Contracts;

use App\Models\AccountingExportQueue;

interface ExternalAccountingExportDriver
{
    public function exportJournal(AccountingExportQueue $item): string;
}
