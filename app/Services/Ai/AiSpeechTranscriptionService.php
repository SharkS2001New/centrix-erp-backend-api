<?php

namespace App\Services\Ai;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Speech-to-text for Centrix voice talk.
 * Tries OpenAI-compatible Whisper (incl. Groq), then Gemini multimodal audio.
 */
class AiSpeechTranscriptionService
{
    /**
     * @return array{text: string, provider: string, model: string}
     */
    public function transcribeUploadedAudio($user, UploadedFile $audio): array
    {
        $runtime = AiSettingsResolver::resolveRuntime($user);
        if (! $runtime) {
            throw ValidationException::withMessages([
                'audio' => ['AI is not configured for this organization.'],
            ]);
        }

        $path = $audio->getRealPath();
        if (! $path || ! is_readable($path)) {
            throw ValidationException::withMessages([
                'audio' => ['Could not read the recorded audio.'],
            ]);
        }

        $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes) < 64) {
            throw ValidationException::withMessages([
                'audio' => ['Recording was empty. Tap Talk and speak again.'],
            ]);
        }

        $filename = $audio->getClientOriginalName() ?: 'voice.webm';
        $mime = $this->normalizeAudioMime((string) ($audio->getMimeType() ?? ''), $filename);
        $errors = [];
        $chatHost = $this->hostLabel((string) ($runtime['base_url'] ?? ''));
        $chatIsWhisperIncapable = ! $this->hostSupportsWhisper((string) ($runtime['base_url'] ?? ''));
        $geminiRateLimited = false;

        // 1) Optional dedicated Whisper key (best when platform chat is DeepSeek / chat-only).
        $dedicated = $this->resolveDedicatedTranscriptionCredentials();
        if ($dedicated) {
            try {
                return $this->transcribeViaOpenAiCompatible($dedicated, $bytes, $filename, $mime);
            } catch (ValidationException $e) {
                $errors[] = $this->firstValidationMessage($e);
            }
        }

        // 2) Gemini first when chat provider cannot do Whisper (e.g. DeepSeek).
        // DeepSeek is chat-only — voice STT is a separate Gemini/Whisper path.
        if ($chatIsWhisperIncapable || strtolower((string) ($runtime['provider'] ?? '')) === 'gemini') {
            $geminiEarly = $this->resolveGeminiCredentials($runtime);
            if ($geminiEarly) {
                try {
                    return $this->transcribeViaGemini($geminiEarly, $bytes, $mime);
                } catch (ValidationException $e) {
                    $msg = $this->firstValidationMessage($e);
                    $errors[] = $msg;
                    if ($this->isRateLimitMessage($msg)) {
                        $geminiRateLimited = true;
                    }
                }
            }
        }

        // 3) OpenAI / Groq Whisper only when the host actually supports /audio/transcriptions.
        $openai = $this->resolveOpenAiCompatibleCredentials($runtime);
        if ($openai) {
            try {
                return $this->transcribeViaOpenAiCompatible($openai, $bytes, $filename, $mime);
            } catch (ValidationException $e) {
                $errors[] = $this->firstValidationMessage($e);
            }
        }

        // 4) Gemini fallback (skip if we already hit Gemini rate limits above).
        $gemini = $this->resolveGeminiCredentials($runtime);
        if ($gemini && ! $geminiRateLimited) {
            try {
                return $this->transcribeViaGemini($gemini, $bytes, $mime);
            } catch (ValidationException $e) {
                $msg = $this->firstValidationMessage($e);
                $errors[] = $msg;
                if ($this->isRateLimitMessage($msg)) {
                    $geminiRateLimited = true;
                }
            }
        }

        $detail = collect($errors)->filter()->unique()->take(2)->implode(' ');
        Log::warning('AI voice transcription exhausted providers', [
            'runtime_provider' => $runtime['provider'] ?? null,
            'runtime_base_url' => $runtime['base_url'] ?? null,
            'chat_is_whisper_incapable' => $chatIsWhisperIncapable,
            'had_dedicated' => (bool) $dedicated,
            'had_openai' => (bool) $openai,
            'had_gemini' => (bool) $gemini,
            'gemini_rate_limited' => $geminiRateLimited,
            'errors' => $errors,
            'mime' => $mime,
            'bytes' => strlen($bytes),
        ]);

        if ($geminiRateLimited && $chatIsWhisperIncapable) {
            throw ValidationException::withMessages([
                'audio' => [
                    'Voice transcription uses Gemini (or a Whisper key), not your chat model ('.$chatHost.'). '
                        .'Gemini is temporarily rate-limited. Wait a minute and try again, or set AI_TRANSCRIPTION_API_KEY '
                        .'(OpenAI/Groq Whisper) on the platform.',
                ],
            ]);
        }

        if ($chatIsWhisperIncapable) {
            throw ValidationException::withMessages([
                'audio' => [
                    $detail !== ''
                        ? $detail
                        : 'Voice needs a speech API. '.$chatHost.' (e.g. DeepSeek) is chat-only and cannot transcribe audio. '
                            .'Add a Gemini key or set AI_TRANSCRIPTION_API_KEY (OpenAI/Groq Whisper) on the platform, or type your question.',
                ],
            ]);
        }

        throw ValidationException::withMessages([
            'audio' => [
                $detail !== ''
                    ? $detail
                    : 'Voice transcription is not available for this organization\'s AI setup. Ask an admin to configure OpenAI/Groq Whisper or Gemini, or type your question.',
            ],
        ]);
    }

    /**
     * @param  array{api_key: string, base_url: string, model?: string}  $openai
     * @return array{text: string, provider: string, model: string}
     */
    protected function transcribeViaOpenAiCompatible(array $openai, string $bytes, string $filename, string $mime): array
    {
        $baseUrl = rtrim((string) $openai['base_url'], '/');
        $model = $this->whisperModelForBaseUrl($baseUrl, (string) ($openai['model'] ?? ''));

        try {
            $response = Http::withToken($openai['api_key'])
                ->timeout(60)
                ->attach('file', $bytes, $filename)
                ->post($baseUrl.'/audio/transcriptions', [
                    'model' => $model,
                    'language' => 'en',
                    'response_format' => 'json',
                ]);
        } catch (\Throwable $e) {
            Log::warning('AI Whisper connection failed', [
                'message' => $e->getMessage(),
                'base_url' => $baseUrl,
                'model' => $model,
            ]);
            throw ValidationException::withMessages([
                'audio' => ['Could not reach the speech service ('.$baseUrl.').'],
            ]);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw ValidationException::withMessages([
                'audio' => ['Speech credentials were rejected for '.$this->hostLabel($baseUrl).'.'],
            ]);
        }

        if ($response->status() === 429) {
            throw ValidationException::withMessages([
                'audio' => ['Speech service is rate-limited. Wait a moment and try again.'],
            ]);
        }

        if (! $response->successful()) {
            $apiMsg = $this->extractProviderError($response->json(), (string) $response->body());
            Log::warning('AI Whisper HTTP error', [
                'status' => $response->status(),
                'base_url' => $baseUrl,
                'model' => $model,
                'body' => substr((string) $response->body(), 0, 500),
            ]);
            throw ValidationException::withMessages([
                'audio' => [
                    $apiMsg !== ''
                        ? 'Speech service error: '.$apiMsg
                        : 'Speech transcription failed (HTTP '.$response->status().').',
                ],
            ]);
        }

        $text = trim((string) ($response->json('text') ?? ''));
        if ($text === '') {
            throw ValidationException::withMessages([
                'audio' => ['No speech was detected. Try again closer to the mic.'],
            ]);
        }

        return [
            'text' => $text,
            'provider' => 'openai',
            'model' => $model,
        ];
    }

    /**
     * @param  array{api_key: string, base_url: string, model: string}  $gemini
     * @return array{text: string, provider: string, model: string}
     */
    protected function transcribeViaGemini(array $gemini, string $bytes, string $mime): array
    {
        $model = AiSettingsResolver::normalizeGeminiModel((string) ($gemini['model'] ?? ''));
        $baseUrl = rtrim((string) $gemini['base_url'], '/');
        if ($baseUrl === '') {
            $baseUrl = rtrim((string) config('ai.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        }

        $url = $baseUrl.'/models/'.rawurlencode($model).':generateContent';
        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    [
                        'text' => 'Transcribe this voice recording exactly. Return only the spoken words in English (or the language spoken). No quotes, labels, or commentary.',
                    ],
                    [
                        'inline_data' => [
                            'mime_type' => $mime,
                            'data' => base64_encode($bytes),
                        ],
                    ],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'maxOutputTokens' => 512,
            ],
        ];

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $gemini['api_key'],
            ])
                ->timeout(60)
                ->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning('AI Gemini transcription connection failed', ['message' => $e->getMessage()]);
            throw ValidationException::withMessages([
                'audio' => ['Could not reach Gemini speech transcription.'],
            ]);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw ValidationException::withMessages([
                'audio' => ['Gemini credentials were rejected for voice transcription.'],
            ]);
        }

        if ($response->status() === 429) {
            throw ValidationException::withMessages([
                'audio' => ['Speech transcription provider is rate-limited. Wait a moment and try again.'],
            ]);
        }

        if (! $response->successful()) {
            $apiMsg = $this->extractProviderError($response->json(), (string) $response->body());
            Log::warning('AI Gemini transcription HTTP error', [
                'status' => $response->status(),
                'model' => $model,
                'body' => substr((string) $response->body(), 0, 500),
            ]);
            throw ValidationException::withMessages([
                'audio' => [
                    $apiMsg !== ''
                        ? 'Gemini speech error: '.$apiMsg
                        : 'Gemini speech transcription failed (HTTP '.$response->status().').',
                ],
            ]);
        }

        $text = $this->extractGeminiText($response->json());
        if ($text === '') {
            throw ValidationException::withMessages([
                'audio' => ['No speech was detected. Try again closer to the mic.'],
            ]);
        }

        return [
            'text' => $text,
            'provider' => 'gemini',
            'model' => $model,
        ];
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @return array{api_key: string, base_url: string, model?: string}|null
     */
    protected function resolveOpenAiCompatibleCredentials(array $runtime): ?array
    {
        $provider = strtolower(trim((string) ($runtime['provider'] ?? '')));
        $apiKey = trim((string) ($runtime['api_key'] ?? ''));
        $baseUrl = rtrim((string) ($runtime['base_url'] ?? ''), '/');

        if ($provider === 'openai' && $apiKey !== '' && $this->hostSupportsWhisper($baseUrl)) {
            return AiSettingsResolver::resolveOpenAiCompatibleConfig($apiKey, '', $baseUrl);
        }

        $platform = AiSettingsResolver::resolvePlatformOpenAiCredentials();
        if ($platform && ! empty($platform['api_key'])) {
            $platformBase = (string) ($platform['base_url'] ?? '');
            if ($this->hostSupportsWhisper($platformBase)) {
                return AiSettingsResolver::resolveOpenAiCompatibleConfig(
                    (string) $platform['api_key'],
                    '',
                    $platformBase,
                );
            }
        }

        return null;
    }

    /**
     * Optional Whisper-only credentials (separate from DeepSeek/chat keys).
     *
     * @return array{api_key: string, base_url: string, model?: string}|null
     */
    protected function resolveDedicatedTranscriptionCredentials(): ?array
    {
        $apiKey = trim((string) config('ai.transcription.api_key', ''));
        if ($apiKey === '') {
            return null;
        }

        $baseUrl = rtrim((string) config('ai.transcription.base_url', 'https://api.openai.com/v1'), '/');
        $model = trim((string) config('ai.transcription.model', config('ai.transcription_model', '')));

        return AiSettingsResolver::resolveOpenAiCompatibleConfig($apiKey, $model, $baseUrl);
    }

    /**
     * DeepSeek and many OpenAI-compatible chat APIs do not implement /audio/transcriptions.
     */
    protected function hostSupportsWhisper(string $baseUrl): bool
    {
        $host = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl));
        if ($host === '') {
            return false;
        }

        foreach ([
            'deepseek.com',
            'together.xyz',
            'together.aio',
            'fireworks.ai',
            'mistral.ai',
            'anthropic.com',
            'ollama',
            'localhost',
            '127.0.0.1',
        ] as $blocked) {
            if (str_contains($host, $blocked)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @return array{api_key: string, base_url: string, model: string}|null
     */
    protected function resolveGeminiCredentials(array $runtime): ?array
    {
        $provider = strtolower(trim((string) ($runtime['provider'] ?? '')));
        $apiKey = trim((string) ($runtime['api_key'] ?? ''));
        $baseUrl = rtrim((string) ($runtime['base_url'] ?? ''), '/');
        $model = trim((string) ($runtime['model'] ?? ''));

        if ($provider === 'gemini' && $apiKey !== '') {
            return [
                'api_key' => $apiKey,
                'base_url' => $baseUrl !== '' ? $baseUrl : rtrim((string) config('ai.gemini.base_url'), '/'),
                'model' => $model !== '' ? $model : (string) config('ai.gemini.model', 'gemini-2.0-flash'),
            ];
        }

        $platform = AiSettingsResolver::resolvePlatformGeminiCredentials();
        if ($platform && ! empty($platform['api_key'])) {
            return [
                'api_key' => (string) $platform['api_key'],
                'base_url' => (string) ($platform['base_url'] ?? ''),
                'model' => (string) ($platform['model'] ?? config('ai.gemini.model', 'gemini-2.0-flash')),
            ];
        }

        return null;
    }

    protected function whisperModelForBaseUrl(string $baseUrl, string $preferred = ''): string
    {
        $configured = trim((string) config('ai.transcription.model', config('ai.transcription_model', '')));
        if ($configured !== '') {
            return $configured;
        }

        $host = strtolower(parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl);
        if (str_contains($host, 'groq.com')) {
            return 'whisper-large-v3-turbo';
        }
        if (str_contains($host, 'openrouter.ai')) {
            return 'openai/whisper-1';
        }

        $preferred = trim($preferred);
        if ($preferred !== '' && (str_contains(strtolower($preferred), 'whisper') || str_starts_with($preferred, 'openai/'))) {
            return $preferred;
        }

        return 'whisper-1';
    }

    protected function normalizeAudioMime(string $mime, string $filename): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0] ?? ''));
        if ($mime !== '' && (str_starts_with($mime, 'audio/') || $mime === 'video/webm')) {
            return $mime === 'video/webm' ? 'audio/webm' : $mime;
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'wav' => 'audio/wav',
            'mp3' => 'audio/mpeg',
            'm4a', 'mp4' => 'audio/mp4',
            'ogg' => 'audio/ogg',
            default => 'audio/webm',
        };
    }

    /**
     * @param  mixed  $json
     */
    protected function extractGeminiText(mixed $json): string
    {
        if (! is_array($json)) {
            return '';
        }
        $parts = $json['candidates'][0]['content']['parts'] ?? [];
        if (! is_array($parts)) {
            return '';
        }
        $chunks = [];
        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text'])) {
                $chunks[] = (string) $part['text'];
            }
        }

        return trim(implode(' ', $chunks));
    }

    /**
     * @param  mixed  $json
     */
    protected function extractProviderError(mixed $json, string $rawBody): string
    {
        if (is_array($json)) {
            $msg = $json['error']['message']
                ?? $json['error']['status']
                ?? $json['message']
                ?? null;
            if (is_string($msg) && trim($msg) !== '') {
                return trim(mb_substr($msg, 0, 180));
            }
        }

        $raw = trim(strip_tags($rawBody));

        return $raw !== '' ? trim(mb_substr($raw, 0, 180)) : '';
    }

    protected function firstValidationMessage(ValidationException $e): string
    {
        $messages = $e->errors()['audio'] ?? [];

        return is_array($messages) ? trim((string) ($messages[0] ?? '')) : '';
    }

    protected function isRateLimitMessage(string $message): bool
    {
        return str_contains(strtolower($message), 'rate-limited')
            || str_contains(strtolower($message), 'rate limited')
            || str_contains(strtolower($message), 'resource_exhausted');
    }

    protected function hostLabel(string $baseUrl): string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'the speech provider';
    }
}
