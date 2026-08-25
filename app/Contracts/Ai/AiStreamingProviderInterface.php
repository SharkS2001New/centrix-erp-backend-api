<?php

namespace App\Contracts\Ai;

/**
 * Optional streaming for OpenAI-compatible providers (DeepSeek, Groq, OpenAI, …).
 *
 * @phpstan-type AiStreamEvent array{
 *   type: 'delta'|'usage'|'complete',
 *   content?: string,
 *   usage?: array{input_tokens: int, output_tokens: int, total_tokens: int},
 *   text?: ?string,
 *   tool_calls?: list<array{id: string, name: string, arguments: array<string, mixed>}>,
 *   model?: string,
 * }
 */
interface AiStreamingProviderInterface
{
    /**
     * Stream a chat completion. Yields delta chunks; final yield has type complete.
     *
     * @param  array<string, mixed>  $request
     * @return \Generator<int, array<string, mixed>>
     */
    public function streamChat(array $request): \Generator;

    /**
     * Stream the follow-up turn after tool execution.
     *
     * @param  array<string, mixed>  $request
     * @return \Generator<int, array<string, mixed>>
     */
    public function streamContinueWithToolResults(array $request): \Generator;
}
