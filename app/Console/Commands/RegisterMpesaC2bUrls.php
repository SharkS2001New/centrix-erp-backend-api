<?php

namespace App\Console\Commands;

use App\Services\MpesaService;
use Illuminate\Console\Command;

class RegisterMpesaC2bUrls extends Command
{
    protected $signature = 'mpesa:register-c2b-urls';

    protected $description = 'Register M-Pesa C2B confirmation/validation URLs with Safaricom (direct till/paybill payments)';

    public function handle(MpesaService $mpesaService): int
    {
        try {
            $response = $mpesaService->registerUrls();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('M-Pesa C2B URLs registered.');
        $this->line('Confirmation: ' . $mpesaService->resolvedConfirmationUrl());
        $this->line('Validation: ' . config('mpesa.validation_url'));
        $this->line('Shortcode: ' . config('mpesa.child_storecode'));
        $this->line(json_encode($response, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
