<?php

namespace App\Contracts\Ai;

/**
 * Pluggable LLM provider (Gemini, OpenAI, Groq via OpenAI-compatible base URL, …).
 *
 * Providers must never receive database credentials or execute SQL.
 * Centrix tools run in Laravel; the provider only sees structured tool results.
 */
interface AiProviderInterface
{
    public function name(): string;

    /**
     * Run a chat turn with optional function/tool calling.
     *
     * @param  array{
     *   system: string,
     *   messages: list<array{role: string, content: string}>,
     *   tools?: list<array<string, mixed>>,
     *   model?: string,
     *   max_output_tokens?: int,
     *   temperature?: float,
     * }  $request
     * @return array{
     *   text: ?string,
     *   tool_calls: list<array{id: string, name: string, arguments: array<string, mixed>, thought_signature?: string}>,
     *   usage: array{input_tokens: int, output_tokens: int, total_tokens: int},
     *   model: string,
     *   model_content?: array{role?: string, parts: list<array<string, mixed>>},
     *   raw?: mixed,
     * }
     */
    public function chat(array $request): array;

    /**
     * Continue after Centrix executed tool calls.
     *
     * @param  array{
     *   system: string,
     *   messages: list<array{role: string, content: string}>,
     *   tools?: list<array<string, mixed>>,
     *   prior_tool_calls: list<array{id: string, name: string, arguments: array<string, mixed>, thought_signature?: string}>,
     *   prior_model_content?: array{role?: string, parts: list<array<string, mixed>>}|null,
     *   tool_results: list<array{id: string, name: string, result: array<string, mixed>}>,
     *   model?: string,
     *   max_output_tokens?: int,
     *   temperature?: float,
     * }  $request
     * @return array{
     *   text: ?string,
     *   tool_calls: list<array{id: string, name: string, arguments: array<string, mixed>, thought_signature?: string}>,
     *   usage: array{input_tokens: int, output_tokens: int, total_tokens: int},
     *   model: string,
     *   model_content?: array{role?: string, parts: list<array<string, mixed>>},
     *   raw?: mixed,
     * }
     */
    public function continueWithToolResults(array $request): array;
}
