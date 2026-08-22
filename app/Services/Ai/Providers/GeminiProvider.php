<?php

namespace App\Services\Ai\Providers;

use App\Contracts\Ai\AiProviderInterface;
use App\Exceptions\Ai\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Official Google Gemini generateContent API with function calling.
 *
 * @see https://ai.google.dev/gemini-api/docs/function-calling
 */
class GeminiProvider implements AiProviderInterface
{
    public function __construct(
        protected string $apiKey,
        protected string $model,
        protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
        protected int $timeoutSeconds = 60,
    ) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function chat(array $request): array
    {
        $payload = $this->buildGeneratePayload($request);

        return $this->generate($payload, $request['model'] ?? $this->model);
    }

    public function continueWithToolResults(array $request): array
    {
        $contents = $this->buildContentsFromHistory($request['messages'] ?? []);
        $modelParts = [];
        foreach ($request['prior_tool_calls'] ?? [] as $call) {
            $modelParts[] = [
                'functionCall' => [
                    'name' => $call['name'],
                    'args' => (object) ($call['arguments'] ?? []),
                ],
            ];
        }
        if ($modelParts !== []) {
            $contents[] = ['role' => 'model', 'parts' => $modelParts];
        }

        $fnParts = [];
        foreach ($request['tool_results'] ?? [] as $result) {
            $fnParts[] = [
                'functionResponse' => [
                    'name' => $result['name'],
                    'response' => [
                        'name' => $result['name'],
                        'content' => $result['result'] ?? new \stdClass,
                    ],
                ],
            ];
        }
        if ($fnParts !== []) {
            $contents[] = ['role' => 'user', 'parts' => $fnParts];
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => (float) ($request['temperature'] ?? 0.2),
                'maxOutputTokens' => (int) ($request['max_output_tokens'] ?? config('ai.defaults.max_output_tokens', 2048)),
            ],
        ];
        $system = trim((string) ($request['system'] ?? ''));
        if ($system !== '') {
            $payload['system_instruction'] = [
                'parts' => [['text' => $system]],
            ];
        }

        $tools = $this->formatTools($request['tools'] ?? []);
        if ($tools !== []) {
            $payload['tools'] = [['functionDeclarations' => $tools]];
        }

        return $this->generate($payload, $request['model'] ?? $this->model);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{text: ?string, tool_calls: list<array{id: string, name: string, arguments: array<string, mixed>}>, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}, model: string}
     */
    protected function generate(array $payload, string $model): array
    {
        $model = trim($model) !== '' ? trim($model) : $this->model;
        $url = rtrim($this->baseUrl, '/').'/models/'.rawurlencode($model).':generateContent';

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->apiKey,
            ])
                ->timeout($this->timeoutSeconds)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::warning('Gemini connection failed', ['message' => $e->getMessage()]);
            throw AiProviderException::timeout();
        }

        if ($response->status() === 429) {
            Log::warning('Gemini rate limited', ['body' => $response->body()]);
            throw AiProviderException::rateLimited();
        }

        if (in_array($response->status(), [401, 403], true)) {
            Log::warning('Gemini auth failed', ['status' => $response->status()]);
            throw AiProviderException::unauthorized();
        }

        if ($response->status() >= 500) {
            Log::warning('Gemini unavailable', ['status' => $response->status(), 'body' => $response->body()]);
            throw AiProviderException::unavailable();
        }

        if (! $response->successful()) {
            $body = $response->body();
            $providerMessage = (string) ($response->json('error.message') ?? '');
            Log::warning('Gemini request failed', [
                'status' => $response->status(),
                'body' => $body,
                'model' => $model,
            ]);

            if ($response->status() === 404 || str_contains(strtolower($providerMessage), 'not found')) {
                throw new AiProviderException(
                    'Gemini model "'.$model.'" was not found. Set a valid model (e.g. gemini-2.0-flash) under AI settings or GEMINI_MODEL.',
                    'model_not_found',
                    404,
                    false,
                );
            }

            if ($providerMessage !== '') {
                $detail = strlen($providerMessage) > 200 ? substr($providerMessage, 0, 197).'…' : $providerMessage;
                throw new AiProviderException(
                    'Gemini request failed: '.$detail,
                    'provider_error',
                    $response->status(),
                    false,
                );
            }

            throw AiProviderException::unavailable();
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw AiProviderException::malformed();
        }

        return $this->parseGenerateResponse($json, $model);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{text: ?string, tool_calls: list<array{id: string, name: string, arguments: array<string, mixed>}>, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}, model: string}
     */
    protected function parseGenerateResponse(array $json, string $model): array
    {
        $parts = $json['candidates'][0]['content']['parts'] ?? [];
        if (! is_array($parts)) {
            $parts = [];
        }

        $textChunks = [];
        $toolCalls = [];
        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }
            if (isset($part['text']) && is_string($part['text'])) {
                $textChunks[] = $part['text'];
            }
            if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                $name = (string) ($part['functionCall']['name'] ?? '');
                $args = $part['functionCall']['args'] ?? [];
                if (! is_array($args)) {
                    $args = [];
                }
                if ($name !== '') {
                    $toolCalls[] = [
                        'id' => 'call_'.Str::lower(Str::random(12)),
                        'name' => $name,
                        'arguments' => $args,
                    ];
                }
            }
        }

        $usageMeta = is_array($json['usageMetadata'] ?? null) ? $json['usageMetadata'] : [];
        $input = (int) ($usageMeta['promptTokenCount'] ?? 0);
        $output = (int) ($usageMeta['candidatesTokenCount'] ?? $usageMeta['responseTokenCount'] ?? 0);
        $total = (int) ($usageMeta['totalTokenCount'] ?? ($input + $output));

        $text = trim(implode('', $textChunks));

        return [
            'text' => $text !== '' ? $text : null,
            'tool_calls' => $toolCalls,
            'usage' => [
                'input_tokens' => $input,
                'output_tokens' => $output,
                'total_tokens' => $total,
            ],
            'model' => $model,
            'raw' => $json,
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function buildGeneratePayload(array $request): array
    {
        $system = trim((string) ($request['system'] ?? ''));
        $payload = [
            'contents' => $this->buildContentsFromHistory($request['messages'] ?? []),
            'generationConfig' => [
                'temperature' => (float) ($request['temperature'] ?? 0.2),
                'maxOutputTokens' => (int) ($request['max_output_tokens'] ?? config('ai.defaults.max_output_tokens', 2048)),
            ],
        ];

        if ($system !== '') {
            $payload['system_instruction'] = [
                'parts' => [['text' => $system]],
            ];
        }

        $tools = $this->formatTools($request['tools'] ?? []);
        if ($tools !== []) {
            $payload['tools'] = [['functionDeclarations' => $tools]];
        }

        return $payload;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return list<array{role: string, parts: list<array{text: string}>}>
     */
    protected function buildContentsFromHistory(array $messages): array
    {
        $contents = [];
        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            // Gemini uses "user" and "model" (not assistant).
            $geminiRole = $role === 'assistant' || $role === 'model' ? 'model' : 'user';
            if ($role === 'system') {
                // System messages already go in system_instruction; skip if duplicated.
                continue;
            }
            $contents[] = [
                'role' => $geminiRole,
                'parts' => [['text' => $content]],
            ];
        }

        if ($contents === []) {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => 'Hello']],
            ];
        }

        return $contents;
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     * @return list<array<string, mixed>>
     */
    protected function formatTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'description' => (string) ($tool['description'] ?? ''),
                'parameters' => $tool['parameters'] ?? [
                    'type' => 'object',
                    'properties' => new \stdClass,
                ],
            ];
        }

        return $out;
    }
}
