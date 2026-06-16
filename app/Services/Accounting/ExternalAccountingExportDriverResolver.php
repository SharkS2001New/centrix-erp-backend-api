<?php

namespace App\Services\Accounting;

use App\Services\Accounting\Contracts\ExternalAccountingExportDriver;
use InvalidArgumentException;

class ExternalAccountingExportDriverResolver
{
    /** @var array<string, class-string<ExternalAccountingExportDriver>> */
    protected array $drivers = [
        'quickbooks' => QuickBooksExportDriver::class,
    ];

    public function resolve(?string $provider): ExternalAccountingExportDriver
    {
        $provider = strtolower((string) ($provider ?: 'quickbooks'));

        if (! in_array($provider, ['quickbooks'], true)) {
            throw new InvalidArgumentException("Unsupported accounting export provider: {$provider}. Only QuickBooks Online is supported.");
        }

        $class = $this->drivers[$provider];

        return app($class);
    }
}
