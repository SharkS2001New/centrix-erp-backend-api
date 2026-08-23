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
 * Gemini 2.5 / 3.x thinking models attach thoughtSignature on functionCall parts.
 * Those signatures MUST be returned unchanged on the next turn or the API returns 400.
 *
 * @see https://ai.google.dev/gemini-api/docs/function-calling
 * @see https://ai.google.dev/gemini-api/docs/thought-signatures
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

        // Prefer the exact model content from the prior turn (preserves thoughtSignature).
        $priorContent = $request['prior_model_content'] ?? null;
        if (is_array($priorContent) && ! empty($priorContent['parts'])) {
            $contents[] = [
                'role' => 'model',
                'parts' => $priorContent['parts'],
            ];
        } else {
            $modelParts = $this->rebuildModelPartsFromToolCalls($request['prior_tool_calls'] ?? []);
            if ($modelParts !== []) {
                $contents[] = ['role' => 'model', 'parts' => $modelParts];
            }
        }

        $fnParts = [];
        foreach ($request['tool_results'] ?? [] as $result) {
            $payload = $result['result'] ?? new \stdClass;
            if (is_array($payload) && $payload === []) {
                $payload = new \stdClass;
            }
            // Gemini expects functionResponse.response to be the tool result object itself.
            $fnParts[] = [
                'functionResponse' => [
                    'name' => (string) ($result['name'] ?? ''),
                    'response' => is_array($payload) ? $this->jsonSafeObject($payload) : $payload,
                ],
            ];
        }
        if ($fnParts !== []) {
            $contents[] = ['role' => 'user', 'parts' => $fnParts];
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => $this->buildGenerationConfig($request),
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
     * @return array{
     *   text: ?string,
     *   tool_calls: list<array{id: string, name: string, arguments: array<string, mixed>, thought_signature?: string}>,
     *   usage: array{input_tokens: int, output_tokens: int, total_tokens: int},
     *   model: string,
     *   model_content?: array{role?: string, parts: list<array<string, mixed>>},
     *   finish_reason?: ?string,
     *   raw?: mixed
     * }
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

        if ($this->isInvalidApiKeyResponse($response)) {
            Log::warning('Gemini auth failed', ['status' => $response->status()]);
            throw AiProviderException::unauthorized();
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

            $lower = strtolower($providerMessage);
            if ($response->status() === 404 || str_contains($lower, 'not found')) {
                throw new AiProviderException(
                    'Gemini model "'.$model.'" was not found. Set a valid model under AI settings or GEMINI_MODEL.',
                    'model_not_found',
                    404,
                    false,
                );
            }

            if (str_contains($lower, 'thought') && str_contains($lower, 'signature')) {
                throw new AiProviderException(
                    'The AI request could not continue after loading Centrix data. Please try again.',
                    'thought_signature',
                    400,
                    true,
                );
            }

            if ($providerMessage !== '') {
                $detail = strlen($providerMessage) > 200 ? substr($providerMessage, 0, 197).'…' : $providerMessage;
                throw new AiProviderException(
                    'Gemini request failed: '.$detail,
                    'provider_error',
                    $response->status(),
                    $response->status() >= 500 || $response->status() === 429,
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
     * @return array{
     *   text: ?string,
     *   tool_calls: list<array{id: string, name: string, arguments: array<string, mixed>, thought_signature?: string}>,
     *   usage: array{input_tokens: int, output_tokens: int, total_tokens: int},
     *   model: string,
     *   model_content?: array{role?: string, parts: list<array<string, mixed>>},
     *   finish_reason?: ?string,
     *   raw?: mixed
     * }
     */
    protected function parseGenerateResponse(array $json, string $model): array
    {
        $candidate = is_array($json['candidates'][0] ?? null) ? $json['candidates'][0] : [];
        $finishReason = isset($candidate['finishReason']) ? (string) $candidate['finishReason'] : null;
        $content = is_array($candidate['content'] ?? null) ? $candidate['content'] : [];
        $parts = is_array($content['parts'] ?? null) ? $content['parts'] : [];

        if ($finishReason === 'MALFORMED_FUNCTION_CALL') {
            Log::warning('Gemini malformed function call', ['model' => $model, 'raw' => $json]);
            throw new AiProviderException(
                'The AI could not format a data request correctly. Please try asking again more simply.',
                'malformed_function_call',
                502,
                true,
            );
        }

        if ($finishReason === 'MAX_TOKENS') {
            // Gemini 3.x thinking tokens count toward maxOutputTokens. Prefer returning
            // whatever visible text / tool calls we got instead of failing the whole turn.
            $previewText = '';
            $hasToolCall = false;
            foreach ($parts as $part) {
                if (! is_array($part)) {
                    continue;
                }
                if (! empty($part['thought']) && isset($part['text'])) {
                    continue;
                }
                if (isset($part['text']) && is_string($part['text']) && $part['text'] !== '') {
                    $previewText .= $part['text'];
                }
                if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                    $hasToolCall = true;
                }
            }
            if (trim($previewText) === '' && ! $hasToolCall) {
                Log::warning('Gemini hit max tokens with empty output', ['model' => $model]);
                throw new AiProviderException(
                    'The AI response was cut off before any answer was returned. Try again, or raise the model output limit.',
                    'max_tokens',
                    502,
                    true,
                );
            }
            Log::info('Gemini hit max tokens but returned partial content', [
                'model' => $model,
                'has_text' => trim($previewText) !== '',
                'has_tool_call' => $hasToolCall,
            ]);
        }

        if (in_array($finishReason, ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT'], true)) {
            throw new AiProviderException(
                'The AI could not answer that request. Please rephrase your question.',
                'blocked',
                422,
                false,
            );
        }

        $textChunks = [];
        $toolCalls = [];
        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }
            // Skip thought-summary text parts; keep signatures on functionCall parts.
            if (! empty($part['thought']) && isset($part['text'])) {
                continue;
            }
            if (isset($part['text']) && is_string($part['text']) && $part['text'] !== '') {
                $textChunks[] = $part['text'];
            }
            if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                $name = (string) ($part['functionCall']['name'] ?? '');
                $args = $part['functionCall']['args'] ?? [];
                if (! is_array($args)) {
                    $args = [];
                }
                if ($name !== '') {
                    $call = [
                        'id' => 'call_'.Str::lower(Str::random(12)),
                        'name' => $name,
                        'arguments' => $args,
                    ];
                    $signature = $part['thoughtSignature'] ?? $part['thought_signature'] ?? null;
                    if (is_string($signature) && $signature !== '') {
                        $call['thought_signature'] = $signature;
                    }
                    $toolCalls[] = $call;
                }
            }
        }

        $usageMeta = is_array($json['usageMetadata'] ?? null) ? $json['usageMetadata'] : [];
        $input = (int) ($usageMeta['promptTokenCount'] ?? 0);
        $output = (int) ($usageMeta['candidatesTokenCount'] ?? $usageMeta['responseTokenCount'] ?? 0);
        $total = (int) ($usageMeta['totalTokenCount'] ?? ($input + $output));

        $text = trim(implode('', $textChunks));

        $modelContent = null;
        if ($parts !== []) {
            $modelContent = [
                'role' => (string) ($content['role'] ?? 'model'),
                'parts' => $parts,
            ];
        }

        return [
            'text' => $text !== '' ? $text : null,
            'tool_calls' => $toolCalls,
            'usage' => [
                'input_tokens' => $input,
                'output_tokens' => $output,
                'total_tokens' => $total,
            ],
            'model' => $model,
            'model_content' => $modelContent,
            'finish_reason' => $finishReason,
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
            'generationConfig' => $this->buildGenerationConfig($request),
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
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function buildGenerationConfig(array $request): array
    {
        $maxOutput = (int) ($request['max_output_tokens'] ?? config('ai.defaults.max_output_tokens', 2048));
        // Thinking models (Gemini 3.x) spend thought tokens against this budget.
        $maxOutput = max(256, $maxOutput);

        $config = [
            'temperature' => (float) ($request['temperature'] ?? 0.2),
            'maxOutputTokens' => $maxOutput,
        ];

        $thinkingLevel = strtoupper(trim((string) ($request['thinking_level'] ?? '')));
        if ($thinkingLevel === '') {
            // Keep connectivity / short prompts cheap; chat can override.
            $thinkingLevel = $maxOutput <= 512 ? 'MINIMAL' : 'LOW';
        }
        if (in_array($thinkingLevel, ['MINIMAL', 'LOW', 'MEDIUM', 'HIGH'], true)) {
            $config['thinkingConfig'] = [
                'thinkingLevel' => $thinkingLevel,
            ];
        }

        return $config;
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
            $geminiRole = $role === 'assistant' || $role === 'model' ? 'model' : 'user';
            if ($role === 'system') {
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
     * Fallback when prior_model_content is missing (should rarely happen).
     *
     * @param  list<array{id?: string, name: string, arguments?: array<string, mixed>, thought_signature?: string}>  $toolCalls
     * @return list<array<string, mixed>>
     */
    protected function rebuildModelPartsFromToolCalls(array $toolCalls): array
    {
        $modelParts = [];
        foreach ($toolCalls as $index => $call) {
            $part = [
                'functionCall' => [
                    'name' => $call['name'],
                    'args' => (object) ($call['arguments'] ?? []),
                ],
            ];
            $signature = $call['thought_signature'] ?? null;
            if (is_string($signature) && $signature !== '') {
                // Gemini REST uses camelCase thoughtSignature.
                $part['thoughtSignature'] = $signature;
            }
            $modelParts[] = $part;
        }

        return $modelParts;
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
            $parameters = $tool['parameters'] ?? [
                'type' => 'object',
                'properties' => new \stdClass,
            ];
            if (is_array($parameters) && ($parameters['properties'] ?? null) === []) {
                $parameters['properties'] = new \stdClass;
            }
            $out[] = [
                'name' => $name,
                'description' => (string) ($tool['description'] ?? ''),
                'parameters' => $parameters,
            ];
        }

        return $out;
    }

    /**
     * Ensure associative arrays encode as JSON objects for Gemini.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|\stdClass
     */
    protected function jsonSafeObject(array $data): array|\stdClass
    {
        if ($data === []) {
            return new \stdClass;
        }

        return $data;
    }

    protected function isInvalidApiKeyResponse(\Illuminate\Http\Client\Response $response): bool
    {
        if (! in_array($response->status(), [400, 401, 403], true)) {
            return false;
        }

        $providerMessage = strtolower((string) ($response->json('error.message') ?? ''));
        if (str_contains($providerMessage, 'api key not valid')
            || str_contains($providerMessage, 'api_key_invalid')
            || str_contains($providerMessage, 'api key expired')) {
            return true;
        }

        foreach ($response->json('error.details') ?? [] as $detail) {
            if (! is_array($detail)) {
                continue;
            }
            $reason = strtoupper((string) ($detail['reason'] ?? ''));
            if ($reason === 'API_KEY_INVALID') {
                return true;
            }
        }

        return false;
    }
}
