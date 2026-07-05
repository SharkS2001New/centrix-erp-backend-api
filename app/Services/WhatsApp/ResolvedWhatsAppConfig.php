<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappConfig;

class ResolvedWhatsAppConfig
{
    public function __construct(
        public readonly int $organizationId,
        public readonly ?int $branchId,
        public readonly int $botUserId,
        public readonly ?string $phoneNumberId,
        public readonly string $accessToken,
        public readonly string $webhookVerifyToken,
        public readonly string $graphApiVersion,
    ) {}

    public static function fromModel(WhatsappConfig $model): self
    {
        return new self(
            organizationId: (int) $model->organization_id,
            branchId: $model->branch_id ? (int) $model->branch_id : null,
            botUserId: (int) $model->bot_user_id,
            phoneNumberId: $model->phone_number_id,
            accessToken: (string) $model->access_token,
            webhookVerifyToken: (string) ($model->webhook_verify_token ?? config('whatsapp.verify_token')),
            graphApiVersion: (string) ($model->graph_api_version ?? config('whatsapp.graph_api_version', 'v21.0')),
        );
    }
}
