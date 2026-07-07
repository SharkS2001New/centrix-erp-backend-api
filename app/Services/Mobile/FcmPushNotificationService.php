<?php

namespace App\Services\Mobile;

use App\Models\InAppNotification;
use App\Models\User;
use App\Models\UserDeviceToken;
use App\Services\Mobile\FcmPushSettingsResolver;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmPushNotificationService
{
    public function __construct(
        protected UserDeviceTokenService $deviceTokens,
    ) {}

    public function notifyInAppNotification(InAppNotification $notification, User $recipient): void
    {
        $settings = $this->pushSettings();
        if (! $settings['enabled']) {
            return;
        }

        if ((int) $notification->organization_id !== (int) $recipient->organization_id) {
            Log::warning('FCM push skipped: notification organization does not match recipient.', [
                'notification_id' => $notification->id,
                'notification_org' => $notification->organization_id,
                'recipient_org' => $recipient->organization_id,
            ]);

            return;
        }

        $appChannel = $this->appChannelForNotificationType((string) $notification->type);
        if ($appChannel === null) {
            return;
        }

        $tokens = $this->deviceTokens->tokensForUser($recipient, $appChannel);
        if ($tokens === []) {
            return;
        }

        if ($settings['ignore_local_tokens']) {
            $tokens = array_values(array_filter(
                $tokens,
                fn (string $token) => ! str_starts_with($token, 'mgr-local-')
                    && ! str_starts_with($token, 'mob-local-'),
            ));
        }

        if ($tokens === []) {
            return;
        }

        $title = (string) $notification->title;
        $body = (string) $notification->message;
        $data = [
            'type' => $this->pushDataTypeForNotification($notification),
            'notification_id' => (string) $notification->id,
            'organization_id' => (string) $notification->organization_id,
            'action_request_id' => $notification->action_request_id
                ? (string) $notification->action_request_id
                : null,
        ];

        foreach ($tokens as $token) {
            $this->sendToToken($token, $title, $body, $data);
        }
    }

    /** @return array<string, mixed> */
    public function diagnose(): array
    {
        $settings = $this->pushSettings();
        $credentialsPath = $settings['fcm_credentials_path'];
        $projectId = $settings['fcm_project_id'];
        $credentialsExist = (bool) ($settings['credentials_file_exists'] ?? false);
        $oauthOk = false;
        $oauthError = null;

        if ($settings['enabled'] && $credentialsExist) {
            try {
                $oauthOk = $this->accessToken() !== null;
                if (! $oauthOk) {
                    $oauthError = 'OAuth token request failed — check storage/logs/laravel.log';
                }
            } catch (\Throwable $e) {
                $oauthError = $e->getMessage();
            }
        }

        return [
            'enabled' => (bool) $settings['enabled'],
            'project_id' => is_string($projectId) ? $projectId : null,
            'project_id_configured' => is_string($projectId) && $projectId !== '',
            'credentials_path' => is_string($credentialsPath) ? $credentialsPath : null,
            'credentials_file_exists' => $credentialsExist,
            'ignore_local_tokens' => (bool) $settings['ignore_local_tokens'],
            'oauth_token_ok' => $oauthOk,
            'oauth_error' => $oauthError,
            'ready' => $settings['enabled']
                && is_string($projectId) && $projectId !== ''
                && $credentialsExist
                && $oauthOk,
            'configuration_source' => FcmPushSettingsResolver::describe()['env_fallback_active'] ? 'mixed' : 'platform',
            'apps' => [
                UserDeviceToken::CHANNEL_MANAGER => 'Centrix Manager (pending approvals)',
                UserDeviceToken::CHANNEL_MOBILE_SALES => 'Centrix Mobile (discount approval outcomes)',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function sendTest(
        string $token,
        string $title = 'Centrix push test',
        string $body = 'Push notifications are working.',
        string $dataType = 'approval',
    ): array {
        $settings = $this->pushSettings();
        if (! $settings['enabled']) {
            return ['ok' => false, 'message' => 'Push notifications are disabled.'];
        }

        return $this->sendToToken($token, $title, $body, ['type' => $dataType]);
    }

    /** @param  array<string, string|null>  $data
     * @return array<string, mixed>
     */
    public function sendToToken(string $token, string $title, string $body, array $data = []): array
    {
        $accessToken = $this->accessToken();
        if ($accessToken === null) {
            return ['ok' => false, 'message' => 'FCM OAuth token unavailable.'];
        }

        $projectId = $this->pushSettings()['fcm_project_id'];
        if (! is_string($projectId) || $projectId === '') {
            Log::warning('FCM push skipped: Firebase project ID is not configured.');

            return ['ok' => false, 'message' => 'Firebase project ID is not configured.'];
        }

        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => array_map(
                    fn ($value) => $value === null ? '' : (string) $value,
                    $data,
                ),
            ],
        ];

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", $payload);

        if (! $response->successful()) {
            Log::warning('FCM delivery failed.', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return [
                'ok' => false,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        }

        return ['ok' => true, 'status' => $response->status()];
    }

    protected function appChannelForNotificationType(string $type): ?string
    {
        return match ($type) {
            'approval' => UserDeviceToken::CHANNEL_MANAGER,
            'approval_outcome' => UserDeviceToken::CHANNEL_MOBILE_SALES,
            default => null,
        };
    }

    protected function pushDataTypeForNotification(InAppNotification $notification): string
    {
        return match ((string) $notification->type) {
            'approval_outcome' => 'approval_outcome',
            default => 'approval',
        };
    }

    protected function accessToken(): ?string
    {
        $path = $this->pushSettings()['fcm_credentials_path'];
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            Log::warning('FCM push skipped: credentials file is missing.');

            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json)) {
            return null;
        }

        $clientEmail = (string) ($json['client_email'] ?? '');
        $privateKey = (string) ($json['private_key'] ?? '');
        if ($clientEmail === '' || $privateKey === '') {
            return null;
        }

        $now = time();
        $jwt = JWT::encode([
            'iss' => $clientEmail,
            'sub' => $clientEmail,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        ], $privateKey, 'RS256');

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (! $response->successful()) {
            Log::warning('FCM OAuth failed.', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return null;
        }

        return $response->json('access_token');
    }

    /** @return array<string, mixed> */
    protected function pushSettings(): array
    {
        return FcmPushSettingsResolver::resolve();
    }
}
