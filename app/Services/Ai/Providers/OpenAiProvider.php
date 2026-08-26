<?php

namespace App\Services\Ai\Providers;

use App\Contracts\Ai\AiProviderInterface;
use App\Contracts\Ai\AiStreamingProviderInterface;
use App\Exceptions\Ai\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OpenAI Chat Completions with tools — kept so AI_PROVIDER can switch without rewriting callers.
 */
class OpenAiProvider implements AiProviderInterface, AiStreamingProviderInterface
{
    public function __construct(
        protected string $apiKey,
        protected string $model,
        protected string $baseUrl = 'https://api.openai.com/v1',
        protected int $timeoutSeconds = 60,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function chat(array $request): array
    {
        return $this->request($this->buildChatPayload($request, false));
    }

    public function continueWithToolResults(array $request): array
    {
        return $this->request($this->buildContinuePayload($request, false));
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function buildChatPayload(array $request, bool $stream = true): array
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

        $payload = [
            'model' => $request['model'] ?? $this->model,
            'messages' => $messages,
            'temperature' => (float) ($request['temperature'] ?? 0.2),
            'max_tokens' => (int) ($request['max_output_tokens'] ?? config('ai.defaults.max_output_tokens', 2048)),
        ];
        if ($stream) {
            $payload['stream'] = true;
            if ($this->supportsStreamUsage()) {
                $payload['stream_options'] = ['include_usage' => true];
            }
        }
        if ($this->usesGroqEndpoint()) {
            $payload['max_completion_tokens'] = $payload['max_tokens'];
        }

        $tools = $this->formatTools($request['tools'] ?? []);
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        return $payload;
    }

    public function streamChat(array $request): \Generator
    {
        yield from $this->streamRequest($this->buildChatPayload($request, true));
    }

    public function streamContinueWithToolResults(array $request): \Generator
    {
        yield from $this->streamRequest($this->buildContinuePayload($request, true));
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function buildContinuePayload(array $request, bool $stream = true): array
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

        $payload = [
            'model' => $request['model'] ?? $this->model,
            'messages' => $messages,
            'temperature' => (float) ($request['temperature'] ?? 0.2),
            'max_tokens' => (int) ($request['max_output_tokens'] ?? config('ai.defaults.max_output_tokens', 2048)),
        ];
        if ($stream) {
            $payload['stream'] = true;
            if ($this->supportsStreamUsage()) {
                $payload['stream_options'] = ['include_usage' => true];
            }
        }
        if ($this->usesGroqEndpoint()) {
            $payload['max_completion_tokens'] = $payload['max_tokens'];
        }
        $tools = $this->formatTools($request['tools'] ?? []);
        if ($tools !== []) {
            $payload['tools'] = $tools;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return \Generator<int, array<string, mixed>>
     */
    protected function streamRequest(array $payload): \Generator
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeoutSeconds)
                ->withOptions(['stream' => true])
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', $payload);
        } catch (ConnectionException $e) {
            Log::warning('OpenAI stream connection failed', ['message' => $e->getMessage()]);
            throw AiProviderException::timeout();
        }

        if ($response->status() === 429) {
            throw AiProviderException::rateLimited();
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw AiProviderException::unauthorized();
        }
        if ($response->status() >= 500) {
            throw AiProviderException::unavailable();
        }
        if (! $response->successful()) {
            throw AiProviderException::unavailable();
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $text = '';
        $toolCalls = [];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0];
        $model = (string) ($payload['model'] ?? $this->model);
        $finishReason = null;

        while (! $body->eof()) {
            $buffer .= $body->read(2048);
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $newline), "\r");
                $buffer = substr($buffer, $newline + 1);
                if ($line === '' || ! str_starts_with($line, 'data: ')) {
                    continue;
                }
                $data = trim(substr($line, 6));
                if ($data === '[DONE]') {
                    break 2;
                }
                $json = json_decode($data, true);
                if (! is_array($json)) {
                    continue;
                }

                if (! empty($json['model'])) {
                    $model = (string) $json['model'];
                }

                if (is_array($json['usage'] ?? null)) {
                    $usage['input_tokens'] = (int) ($json['usage']['prompt_tokens'] ?? $usage['input_tokens']);
                    $usage['output_tokens'] = (int) ($json['usage']['completion_tokens'] ?? $usage['output_tokens']);
                    $usage['total_tokens'] = (int) ($json['usage']['total_tokens'] ?? $usage['total_tokens']);
                }

                $choice = $json['choices'][0] ?? null;
                if (! is_array($choice)) {
                    continue;
                }

                if (! empty($choice['finish_reason'])) {
                    $finishReason = (string) $choice['finish_reason'];
                }

                $delta = $choice['delta'] ?? [];
                if (! is_array($delta)) {
                    continue;
                }

                $content = (string) ($delta['content'] ?? '');
                if ($content !== '') {
                    $text .= $content;
                    yield ['type' => 'delta', 'content' => $content];
                }

                foreach ($delta['tool_calls'] ?? [] as $toolDelta) {
                    if (! is_array($toolDelta)) {
                        continue;
                    }
                    $index = (int) ($toolDelta['index'] ?? 0);
                    if (! isset($toolCalls[$index])) {
                        $toolCalls[$index] = [
                            'id' => (string) ($toolDelta['id'] ?? ('call_'.Str::lower(Str::random(12)))),
                            'name' => (string) ($toolDelta['function']['name'] ?? ''),
                            'arguments' => (string) ($toolDelta['function']['arguments'] ?? ''),
                        ];
                        continue;
                    }
                    if (! empty($toolDelta['id'])) {
                        $toolCalls[$index]['id'] = (string) $toolDelta['id'];
                    }
                    if (! empty($toolDelta['function']['name'])) {
                        $toolCalls[$index]['name'] = (string) $toolDelta['function']['name'];
                    }
                    if (isset($toolDelta['function']['arguments'])) {
                        $toolCalls[$index]['arguments'] .= (string) $toolDelta['function']['arguments'];
                    }
                }
            }
        }

        ksort($toolCalls);
        $parsedToolCalls = [];
        foreach ($toolCalls as $call) {
            $name = (string) ($call['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $args = json_decode((string) ($call['arguments'] ?? '{}'), true);
            if (! is_array($args)) {
                $args = [];
            }
            $parsedToolCalls[] = [
                'id' => (string) ($call['id'] ?? ''),
                'name' => $name,
                'arguments' => $args,
            ];
        }

        yield [
            'type' => 'complete',
            'text' => trim($text) !== '' ? trim($text) : null,
            'tool_calls' => $parsedToolCalls,
            'usage' => $usage,
            'model' => $model,
            'finish_reason' => $finishReason,
        ];
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
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', $payload);
        } catch (ConnectionException $e) {
            Log::warning('OpenAI connection failed', ['message' => $e->getMessage()]);
            throw AiProviderException::timeout();
        }

        if ($response->status() === 429) {
            throw AiProviderException::rateLimited();
        }
        if (in_array($response->status(), [401, 403], true)) {
            $body = $response->json();
            $providerMessage = is_array($body)
                ? trim((string) ($body['error']['message'] ?? $body['message'] ?? ''))
                : '';
            if ($providerMessage !== '') {
                $hint = '';
                if (
                    str_starts_with($this->apiKey, 'gsk_')
                    && ! str_contains(strtolower($this->baseUrl), 'groq.com')
                ) {
                    $hint = ' For Groq, set base URL to https://api.groq.com/openai/v1.';
                }
                throw new AiProviderException(
                    $providerMessage.$hint,
                    'invalid_api_key',
                    $response->status(),
                    false,
                );
            }
            throw AiProviderException::unauthorized();
        }
        if ($response->status() >= 500) {
            throw AiProviderException::unavailable();
        }
        if (! $response->successful()) {
            $body = $response->json();
            $providerMessage = is_array($body)
                ? trim((string) ($body['error']['message'] ?? $body['message'] ?? ''))
                : '';
            Log::warning('OpenAI request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            if ($providerMessage !== '') {
                throw new AiProviderException(
                    $providerMessage,
                    $response->status() === 400 ? 'invalid_request' : 'unavailable',
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
        $finishReason = isset($json['choices'][0]['finish_reason'])
            ? (string) $json['choices'][0]['finish_reason']
            : null;

        return [
            'text' => $text !== '' ? $text : null,
            'tool_calls' => $toolCalls,
            'finish_reason' => $finishReason,
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

    protected function usesGroqEndpoint(): bool
    {
        return str_contains(strtolower($this->baseUrl), 'groq.com');
    }

    protected function supportsStreamUsage(): bool
    {
        return str_contains(strtolower($this->baseUrl), 'openai.com');
    }
}
