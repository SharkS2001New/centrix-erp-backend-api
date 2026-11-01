<?php

namespace App\Services\Platform;

use App\Services\Ai\AiSettingsResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MarketingImageGenerationService
{
    /**
     * Generate and store an image using the platform OpenAI credentials.
     *
     * @return string Public-disk relative path
     */
    public function generatePng(string $prompt, string $directory, ?string $model = null): string
    {
        $credentials = AiSettingsResolver::resolvePlatformOpenAiCredentials();
        if (! $credentials) {
            throw new \RuntimeException(
                'Image generation requires an OpenAI API key. Add one under Platform → AI training → Credentials.',
            );
        }

        try {
            $response = Http::acceptJson()
                ->withToken($credentials['api_key'])
                ->timeout(max(120, (int) config('ai.request_timeout', 60)))
                ->post(rtrim($credentials['base_url'], '/').'/images/generations', [
                    'model' => $model ?: config('marketing_portfolio.image_model', 'gpt-image-1'),
                    'prompt' => $prompt,
                    'size' => '1536x1024',
                    'quality' => 'medium',
                    'output_format' => 'png',
                    'n' => 1,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Marketing image generation request failed', [
                'directory' => $directory,
                'message' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Image generation request failed. Check the OpenAI API connection and try again.',
                0,
                $e,
            );
        }

        if (! $response->successful()) {
            Log::warning('Marketing image generation provider returned an error', [
                'directory' => $directory,
                'status' => $response->status(),
            ]);

            throw new \RuntimeException('Image generation provider returned HTTP '.$response->status().'.');
        }

        $encoded = $response->json('data.0.b64_json');
        $image = is_string($encoded) ? base64_decode($encoded, true) : false;
        $imageInfo = is_string($image) ? @getimagesizefromstring($image) : false;
        if (! is_string($image)
            || strlen($image) > 20 * 1024 * 1024
            || ! is_array($imageInfo)
            || ($imageInfo['mime'] ?? null) !== 'image/png') {
            throw new \RuntimeException('Image provider did not return a valid PNG image.');
        }

        $directory = trim($directory, '/');
        $path = $directory.'/'.Str::uuid().'.png';
        if (! Storage::disk('public')->put($path, $image)) {
            throw new \RuntimeException('Generated image could not be saved to storage.');
        }

        return $path;
    }
}
