<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppInboundMessageJob;
use App\Services\WhatsApp\WhatsAppSettingsResolver;
use App\Services\WhatsApp\WhatsAppWebhookSignatureValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        protected WhatsAppWebhookSignatureValidator $signatureValidator,
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
        if (! $this->signatureValidator->isValid($request)) {
            Log::warning('whatsapp.invalid_signature', [
                'configured' => $this->signatureValidator->isConfigured(),
            ]);

            return response('Invalid signature', 403);
        }

        $payload = $request->all();
        if (! is_array($payload)) {
            return response()->json(['ok' => true]);
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
                if ($phoneNumberId === '') {
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

                    ProcessWhatsAppInboundMessageJob::dispatch(
                        $phoneNumberId,
                        $from,
                        $text,
                        $messageId !== '' ? $messageId : null,
                    );
                }
            }
        }

        return response()->json(['ok' => true]);
    }
}
