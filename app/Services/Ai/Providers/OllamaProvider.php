<?php

namespace App\Services\Ai\Providers;

use App\Contracts\Ai\AiProviderInterface;
use App\Exceptions\Ai\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ollama via its OpenAI-compatible Chat Completions API (/v1/chat/completions).
 * Completely free when self-hosted — no cloud API key required.
 */
class OllamaProvider implements AiProviderInterface
{
    public function __construct(
        protected string $apiKey,
        protected string $model,
        protected string $baseUrl = 'http://127.0.0.1:11434/v1',
        protected int $timeoutSeconds = 120,
    ) {
        $this->baseUrl = self::normalizeBaseUrl($this->baseUrl);
        if ($this->apiKey === '') {
            $this->apiKey = 'ollama';
        }
    }

    public function name(): string
    {
        return 'ollama';
    }

    public static function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return 'http://127.0.0.1:11434/v1';
        }
        // Accept host-only (http://ollama:11434) or already /v1.
        if (! str_ends_with($baseUrl, '/v1')) {
            $baseUrl .= '/v1';
        }

        return $baseUrl;
    }

    public function chat(array $request): array
    {
        $messages = [
            ['role' => 'system', 'content' => (string) ($request['system'] ?? '')],
        ];
        foreach ($request['messages'] ?? [] as $message) {
            $role = (string) ($message['role'] ?? '');
            $content = (string) ($message['content'] ?? '');
            if ($role === '' || $content === '' || $role === 'system') {
                continue;
            }
            $messages[] = ['role' => $role === 'model' ? 'assistant' : $role, 'content' => $content];
        }

        return $this->request($this->buildPayload($request, $messages));
    }

    public function continueWithToolResults(array $request): array
    {
        $messages = [
            ['role' => 'system', 'content' => (string) ($request['system'] ?? '')],
        ];
        foreach ($request['messages'] ?? [] as $message) {
            $role = (string) ($message['role'] ?? '');
            $content = (string) ($message['content'] ?? '');
            if ($role === '' || $content === '' || $role === 'system') {
                continue;
            }
            $messages[] = ['role' => $role === 'model' ? 'assistant' : $role, 'content' => $content];
        }

        $assistantToolCalls = [];
        foreach ($request['prior_tool_calls'] ?? [] as $call) {
            $assistantToolCalls[] = [
                'id' => $call['id'],
                'type' => 'function',
                'function' => [
                    'name' => $call['name'],
                    'arguments' => json_encode($call['arguments'] ?? [], JSON_THROW_ON_ERROR),
                ],
            ];
        }
        if ($assistantToolCalls !== []) {
            $messages[] = [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => $assistantToolCalls,
            ];
        }

        foreach ($request['tool_results'] ?? [] as $result) {
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $result['id'],
                'content' => json_encode($result['result'] ?? [], JSON_THROW_ON_ERROR),
            ];
        }

        return $this->request($this->buildPayload($request, $messages));
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  list<array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    protected function buildPayload(array $request, array $messages): array
    {
        $maxTokens = (int) ($request['max_output_tokens'] ?? config('ai.ollama.max_output_tokens', 256));
        $payload = [
            'model' => $request['model'] ?? $this->model,
            'messages' => $messages,
            'temperature' => (float) ($request['temperature'] ?? 0.2),
            'max_tokens' => max(32, $maxTokens),
            'stream' => false,
            'keep_alive' => (string) config('ai.ollama.keep_alive', '30m'),
            'options' => [
                'num_ctx' => max(512, (int) config('ai.ollama.num_ctx', 2048)),
                'num_predict' => max(32, $maxTokens),
            ],
        ];

        $tools = $this->formatTools($request['tools'] ?? []);
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{text: ?string, tool_calls: list<array{id: string, name: string, arguments: array<string, mixed>}>, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}, model: string}
     */
    protected function request(array $payload): array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->post($this->baseUrl.'/chat/completions', $payload);
        } catch (ConnectionException $e) {
            Log::warning('Ollama connection failed', [
                'message' => $e->getMessage(),
                'base_url' => $this->baseUrl,
            ]);
            throw new AiProviderException(
                'Cannot reach Ollama at '.$this->baseUrl.'. Is the Ollama pod/service running, and has the model been pulled?',
                'unavailable',
                503,
                true,
            );
        }

        if ($response->status() === 404) {
            $body = (string) $response->body();
            Log::warning('Ollama model missing or bad URL', ['body' => $body, 'model' => $payload['model'] ?? null]);
            throw new AiProviderException(
                'Ollama model not found. Pull it first (e.g. ollama pull '.($payload['model'] ?? $this->model).').',
                'unavailable',
            );
        }
        if ($response->status() >= 500) {
            throw AiProviderException::unavailable();
        }
        if (! $response->successful()) {
            Log::warning('Ollama request failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw AiProviderException::unavailable();
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw AiProviderException::malformed();
        }

        $message = $json['choices'][0]['message'] ?? [];
        $text = trim((string) ($message['content'] ?? ''));
        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $call) {
            if (! is_array($call)) {
                continue;
            }
            $name = (string) ($call['function']['name'] ?? '');
            $argsRaw = (string) ($call['function']['arguments'] ?? '{}');
            $args = json_decode($argsRaw, true);
            if (! is_array($args)) {
                $args = [];
            }
            if ($name !== '') {
                $toolCalls[] = [
                    'id' => (string) ($call['id'] ?? ('call_'.Str::lower(Str::random(12)))),
                    'name' => $name,
                    'arguments' => $args,
                ];
            }
        }

        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $input = (int) ($usage['prompt_tokens'] ?? 0);
        $output = (int) ($usage['completion_tokens'] ?? 0);

        return [
            'text' => $text !== '' ? $text : null,
            'tool_calls' => $toolCalls,
            'usage' => [
                'input_tokens' => $input,
                'output_tokens' => $output,
                'total_tokens' => (int) ($usage['total_tokens'] ?? ($input + $output)),
            ],
            'model' => (string) ($payload['model'] ?? $this->model),
            'raw' => $json,
        ];
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
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => (string) ($tool['description'] ?? ''),
                    'parameters' => $tool['parameters'] ?? [
                        'type' => 'object',
                        'properties' => new \stdClass,
                    ],
                ],
            ];
        }

        return $out;
    }

    /**
     * List models installed on the Ollama host (GET /api/tags).
     *
     * @return list<array{name: string, size: int|null, modified_at: string|null}>
     *
     * @throws AiProviderException
     */
    public static function listModels(string $baseUrl, int $timeoutSeconds = 15): array
    {
        $root = preg_replace('#/v1$#', '', self::normalizeBaseUrl($baseUrl)) ?: 'http://127.0.0.1:11434';
        $timeout = max(2, min(60, $timeoutSeconds));

        try {
            $response = Http::timeout($timeout)->acceptJson()->get($root.'/api/tags');
        } catch (ConnectionException) {
            throw new AiProviderException(
                'Cannot reach Ollama at the configured base URL.',
                'connection_failed',
            );
        } catch (\Throwable) {
            throw new AiProviderException(
                'Failed to list Ollama models.',
                'request_failed',
            );
        }

        if (! $response->successful()) {
            throw new AiProviderException(
                'Ollama did not return a model list (HTTP '.$response->status().').',
                'http_error',
            );
        }

        $models = [];
        foreach ($response->json('models') ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $models[] = [
                'name' => $name,
                'size' => isset($row['size']) ? (int) $row['size'] : null,
                'modified_at' => isset($row['modified_at']) ? (string) $row['modified_at'] : null,
            ];
        }

        usort($models, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $models;
    }
}
