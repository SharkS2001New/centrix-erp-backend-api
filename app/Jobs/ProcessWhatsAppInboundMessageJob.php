<?php

namespace App\Jobs;

use App\Services\WhatsApp\WhatsAppBotHandler;
use App\Services\WhatsApp\WhatsAppConfigResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppInboundMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * @param  array{
     *   type?: string,
     *   text?: string|null,
     *   image_id?: string|null,
     *   image_mime?: string|null,
     *   latitude?: float|null,
     *   longitude?: float|null,
     *   location_name?: string|null,
     *   location_address?: string|null
     * }  $inbound
     */
    public function __construct(
        public string $phoneNumberId,
        public string $fromPhone,
        public array $inbound = [],
        public ?string $providerMessageId = null,
    ) {}

    public function handle(WhatsAppConfigResolver $configResolver, WhatsAppBotHandler $botHandler): void
    {
        $config = $configResolver->resolveForPhoneNumberId($this->phoneNumberId);
        if ($config === null) {
            Log::warning('whatsapp.unconfigured_phone', ['phone_number_id' => $this->phoneNumberId]);

            return;
        }

        try {
            $botHandler->handleInbound(
                $config,
                $this->fromPhone,
                $this->inbound,
                $this->providerMessageId,
            );
        } catch (\Throwable $e) {
            report($e);
            Log::error('whatsapp.handler_failed', [
                'from' => $this->fromPhone,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
