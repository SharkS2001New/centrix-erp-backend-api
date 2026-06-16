<?php

namespace App\Services\Accounting;

use App\Models\AccountingExportQueue;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\User;
use App\Services\Erp\CapabilityGate;

class SaleJournalService
{
    public function __construct(
        protected JournalPostingService $posting,
        protected SaleJournalBuilder $builder,
        protected JournalExportService $exports,
        protected AccountingSettingsResolver $settings,
    ) {}

    public function postIfEnabled(Sale $sale, User $user, CapabilityGate $gate): JournalEntry|AccountingExportQueue|null
    {
        if (! $gate->enabled('accounting')) {
            return null;
        }

        $accounting = $gate->moduleSettings('accounting') ?? [];
        if (! ($accounting['auto_post_sales'] ?? true)) {
            return null;
        }

        $financeSettings = $this->settings->fromFinanceSettings($gate->moduleSettings('finance'));
        if ($financeSettings->usesExternalLedger()) {
            return $this->exports->queueSale($sale, $user, $gate);
        }

        $lines = $this->builder->buildLines($sale, $gate);
        if ($lines === null || $lines === []) {
            return null;
        }

        return $this->posting->createPosted(
            orgId: (int) $sale->organization_id,
            user: $user,
            entryNumber: 'SALE-'.$sale->order_num,
            entryDate: now()->toDateString(),
            lines: $lines,
            description: 'Auto journal for sale #'.$sale->order_num,
            branchId: $sale->branch_id,
            referenceType: 'sale',
            referenceId: $sale->id,
        );
    }
}
