<?php

namespace App\Services\Ai;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Speech-to-text via OpenAI-compatible /audio/transcriptions (Whisper).
 * Used when the browser Web Speech API fails (common "network" errors).
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

        $openai = $this->resolveOpenAiCompatibleCredentials($runtime);
        if (! $openai) {
            throw ValidationException::withMessages([
                'audio' => ['Voice transcription needs an OpenAI-compatible API key (Whisper). Ask an admin to configure AI credentials.'],
            ]);
        }

        $path = $audio->getRealPath();
        if (! $path || ! is_readable($path)) {
            throw ValidationException::withMessages([
                'audio' => ['Could not read the recorded audio.'],
            ]);
        }

        $filename = $audio->getClientOriginalName() ?: 'voice.webm';
        $mime = $audio->getMimeType() ?: 'audio/webm';
        $model = (string) config('ai.transcription_model', 'whisper-1');

        try {
            $response = Http::withToken($openai['api_key'])
                ->timeout(60)
                ->attach('file', file_get_contents($path), $filename)
                ->post(rtrim($openai['base_url'], '/').'/audio/transcriptions', [
                    'model' => $model,
                    'language' => 'en',
                    'response_format' => 'json',
                ]);
        } catch (\Throwable $e) {
            Log::warning('AI voice transcription request failed', ['message' => $e->getMessage()]);
            throw ValidationException::withMessages([
                'audio' => ['Could not reach the speech transcription service. Try again or type your question.'],
            ]);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw ValidationException::withMessages([
                'audio' => ['Speech transcription credentials were rejected. Check AI API settings.'],
            ]);
        }

        if ($response->status() === 429) {
            throw ValidationException::withMessages([
                'audio' => ['Speech transcription is rate-limited. Wait a moment and try again.'],
            ]);
        }

        if (! $response->successful()) {
            Log::warning('AI voice transcription HTTP error', [
                'status' => $response->status(),
                'body' => substr((string) $response->body(), 0, 400),
            ]);
            throw ValidationException::withMessages([
                'audio' => ['Speech transcription failed. Try again or type your question.'],
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
     * @param  array<string, mixed>  $runtime
     * @return array{api_key: string, base_url: string}|null
     */
    protected function resolveOpenAiCompatibleCredentials(array $runtime): ?array
    {
        $provider = strtolower(trim((string) ($runtime['provider'] ?? '')));
        $apiKey = trim((string) ($runtime['api_key'] ?? ''));
        $baseUrl = rtrim((string) ($runtime['base_url'] ?? ''), '/');

        if ($provider === 'openai' && $apiKey !== '') {
            return AiSettingsResolver::resolveOpenAiCompatibleConfig($apiKey, '', $baseUrl);
        }

        // Org on Gemini: still try platform OpenAI key for Whisper-only.
        $platform = AiSettingsResolver::resolvePlatformOpenAiCredentials();
        if ($platform && ! empty($platform['api_key'])) {
            return AiSettingsResolver::resolveOpenAiCompatibleConfig(
                (string) $platform['api_key'],
                '',
                (string) ($platform['base_url'] ?? ''),
            );
        }

        return null;
    }
}
