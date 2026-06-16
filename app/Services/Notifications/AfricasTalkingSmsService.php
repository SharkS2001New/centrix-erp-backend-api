<?php

namespace App\Services\Notifications;

use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AfricasTalkingSmsService
{
    public function send(Organization $organization, string $phone, string $message): bool
    {
        $settings = NotificationSettingsResolver::forOrganization($organization);
        if (empty($settings['sms_enabled'])) {
            return false;
        }

        $username = $settings['africas_talking_username'] ?? '';
        $apiKey = $settings['africas_talking_api_key'] ?? '';
        $senderId = $settings['africas_talking_sender_id'] ?? '';
        if ($username === '' || $apiKey === '' || $senderId === '') {
            Log::warning('Africa\'s Talking SMS skipped — incomplete configuration', [
                'organization_id' => $organization->id,
            ]);

            return false;
        }

        $to = $this->normalizePhone($phone);
        if ($to === '') {
            return false;
        }

        try {
            $response = Http::withHeaders([
                'apiKey' => $apiKey,
                'Accept' => 'application/json',
            ])->asForm()->post('https://api.africastalking.com/version1/messaging', [
                'username' => $username,
                'to' => $to,
                'message' => $message,
                'from' => $senderId,
            ]);

            if (! $response->successful()) {
                Log::warning('Africa\'s Talking SMS failed', [
                    'organization_id' => $organization->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Africa\'s Talking SMS exception', [
                'organization_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '0')) {
            return '+254'.substr($digits, 1);
        }
        if (str_starts_with($digits, '254')) {
            return '+'.$digits;
        }

        return '+'.$digits;
    }
}
