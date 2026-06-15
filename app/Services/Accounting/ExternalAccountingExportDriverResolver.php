<?php

namespace App\Services\Accounting;

use App\Services\Accounting\Contracts\ExternalAccountingExportDriver;
use InvalidArgumentException;

class ExternalAccountingExportDriverResolver
{
    /** @var array<string, class-string<ExternalAccountingExportDriver>> */
    protected array $drivers = [
        'quickbooks' => QuickBooksExportDriver::class,
        'xero' => XeroExportDriver::class,
        'sage' => SageExportDriver::class,
    ];

    public function resolve(?string $provider): ExternalAccountingExportDriver
    {
        $provider = strtolower((string) ($provider ?: 'quickbooks'));
        $class = $this->drivers[$provider] ?? null;

        if (! $class) {
            throw new InvalidArgumentException("Unsupported accounting export provider: {$provider}");
        }

        return app($class);
    }
}
