<?php

namespace App\Services\Accounting;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;

class AccountingSettingsResolver
{
    /** @param  array<string, mixed>|null  $finance */
    public function fromFinanceSettings(?array $finance): self
    {
        $this->finance = is_array($finance) ? $finance : [];

        return $this;
    }

    public static function forGate(CapabilityGate $gate): self
    {
        return (new self)->fromFinanceSettings($gate->moduleSettings('finance'));
    }

    public static function forOrganization(Organization $organization): self
    {
        return self::forGate(app(\App\Services\Erp\CapabilityGate::class)->forOrganization($organization));
    }

    /** @var array<string, mixed> */
    protected array $finance = [];

    public function mode(): string
    {
        return ($this->finance['accounting_mode'] ?? 'native') === 'external' ? 'external' : 'native';
    }

    public function usesNativeLedger(): bool
    {
        return $this->mode() === 'native';
    }

    public function usesExternalLedger(): bool
    {
        return $this->mode() === 'external';
    }

    public function provider(): ?string
    {
        $provider = $this->finance['accounting_provider'] ?? null;

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    public function syncDirection(): string
    {
        $direction = $this->finance['accounting_sync_direction'] ?? 'export';

        return in_array($direction, ['export', 'import', 'bidirectional'], true) ? $direction : 'export';
    }

    public function exportsEnabled(): bool
    {
        return $this->usesExternalLedger()
            && in_array($this->syncDirection(), ['export', 'bidirectional'], true);
    }
}
