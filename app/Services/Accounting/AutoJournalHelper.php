<?php

namespace App\Services\Accounting;

use App\Models\AccountingExportQueue;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Erp\CapabilityGate;

class AutoJournalHelper
{
    public function __construct(
        protected JournalPostingService $posting,
        protected JournalExportService $exports,
        protected AccountingSettingsResolver $settings,
    ) {}

    public function settingEnabled(CapabilityGate $gate, string $key, bool $default = true): bool
    {
        $settings = $gate->moduleSettings('accounting') ?? [];

        return (bool) ($settings[$key] ?? $default);
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    public function postOrQueue(
        CapabilityGate $gate,
        User $user,
        int $orgId,
        string $entryNumber,
        string $entryDate,
        array $lines,
        ?string $description,
        ?int $branchId,
        string $referenceType,
        int $referenceId,
    ): JournalEntry|AccountingExportQueue|null {
        if (! $gate->enabled('accounting') || $lines === []) {
            return null;
        }

        $financeSettings = $this->settings->fromFinanceSettings($gate->moduleSettings('finance'));
        if ($financeSettings->usesExternalLedger()) {
            return $this->exports->queueGeneric(
                orgId: $orgId,
                gate: $gate,
                entryNumber: $entryNumber,
                entryDate: $entryDate,
                referenceType: $referenceType,
                referenceId: $referenceId,
                description: $description,
                lines: $lines,
            );
        }

        return $this->posting->createPosted(
            orgId: $orgId,
            user: $user,
            entryNumber: $entryNumber,
            entryDate: $entryDate,
            lines: $lines,
            description: $description,
            branchId: $branchId,
            referenceType: $referenceType,
            referenceId: $referenceId,
        );
    }

    public function accountCodeForPaymentMethod(string $methodCode, ?array $codes = null): string
    {
        $codes ??= $this->posting->defaultAccountCodes();
        $methodCode = strtoupper($methodCode);
        $map = config('erp.module_settings_defaults.accounting.payment_method_accounts', []);

        return $map[$methodCode]
            ?? match ($methodCode) {
                'CASH' => $codes['cash'] ?? '1000',
                'MPESA', 'CARD', 'BANK', 'TRANSFER' => $codes['bank'] ?? '1100',
                'VOUCHER', 'POINTS' => $codes['cash'] ?? '1000',
                default => $codes['cash'] ?? '1000',
            };
    }
}
