<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppBotHandler;
use App\Services\WhatsApp\WhatsAppConfigResolver;
use App\Services\WhatsApp\WhatsAppSettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        protected WhatsAppConfigResolver $configResolver,
        protected WhatsAppBotHandler $botHandler,
    ) {}

    /** Meta webhook verification (GET). */
    public function verify(Request $request)
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode'));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        $expected = WhatsAppSettingsResolver::platformVerifyToken();

        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /** Meta WhatsApp Cloud API inbound messages (POST). */
    public function handle(Request $request)
    {
        $payload = $request->all();
        if (! is_array($payload)) {
            return response()->json(['ok' => true]);
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
                $config = $this->configResolver->resolveForPhoneNumberId($phoneNumberId);
                if (! $config) {
                    Log::warning('whatsapp.unconfigured_phone', ['phone_number_id' => $phoneNumberId]);

                    continue;
                }

                foreach ($value['messages'] ?? [] as $message) {
                    if (($message['type'] ?? '') !== 'text') {
                        continue;
                    }

                    $from = (string) ($message['from'] ?? '');
                    $text = (string) ($message['text']['body'] ?? '');
                    $messageId = (string) ($message['id'] ?? '');

                    if ($from === '' || trim($text) === '') {
                        continue;
                    }

                    try {
                        $this->botHandler->handleInbound($config, $from, $text, $messageId ?: null);
                    } catch (\Throwable $e) {
                        report($e);
                        Log::error('whatsapp.handler_failed', [
                            'from' => $from,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return response()->json(['ok' => true]);
    }
}
