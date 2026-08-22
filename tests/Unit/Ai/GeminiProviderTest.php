<?php

namespace Tests\Unit\Ai;

use App\Exceptions\Ai\AiProviderException;
use App\Services\Ai\Providers\GeminiProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiProviderTest extends TestCase
{
    public function test_chat_parses_text_and_usage(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Hello Centrix']]],
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 11,
                    'candidatesTokenCount' => 4,
                    'totalTokenCount' => 15,
                ],
            ], 200),
        ]);

        $provider = new GeminiProvider('key', 'gemini-3.7-flash');
        $result = $provider->chat([
            'system' => 'You are Centrix.',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'tools' => [],
        ]);

        $this->assertSame('Hello Centrix', $result['text']);
        $this->assertSame([], $result['tool_calls']);
        $this->assertSame(11, $result['usage']['input_tokens']);
        $this->assertSame(4, $result['usage']['output_tokens']);
        $this->assertSame(15, $result['usage']['total_tokens']);
    }

    public function test_rate_limit_throws_friendly_exception(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'quota'], 429),
        ]);

        $provider = new GeminiProvider('key', 'gemini-3.7-flash');

        try {
            $provider->chat([
                'system' => 'x',
                'messages' => [['role' => 'user', 'content' => 'y']],
            ]);
            $this->fail('Expected AiProviderException');
        } catch (AiProviderException $e) {
            $this->assertSame('rate_limited', $e->codeKey);
            $this->assertStringContainsString('temporarily limited', $e->getMessage());
        }
    }

    public function test_unauthorized_throws_without_leaking_key(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'API_KEY_INVALID secret-abc'],
            ], 403),
        ]);

        $provider = new GeminiProvider('super-secret-key', 'gemini-3.7-flash');

        try {
            $provider->chat([
                'system' => 'x',
                'messages' => [['role' => 'user', 'content' => 'y']],
            ]);
            $this->fail('Expected AiProviderException');
        } catch (AiProviderException $e) {
            $this->assertSame('invalid_api_key', $e->codeKey);
            $this->assertStringNotContainsString('super-secret-key', $e->getMessage());
            $this->assertStringNotContainsString('secret-abc', $e->getMessage());
        }
    }

    public function test_timeout_maps_to_friendly_error(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: timed out');
        });

        $provider = new GeminiProvider('key', 'gemini-3.7-flash', timeoutSeconds: 1);

        $this->expectException(AiProviderException::class);
        try {
            $provider->chat([
                'system' => 'x',
                'messages' => [['role' => 'user', 'content' => 'y']],
            ]);
        } catch (AiProviderException $e) {
            $this->assertSame('timeout', $e->codeKey);
            throw $e;
        }
    }

    public function test_continue_with_tool_results_preserves_thought_signature(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([
                    'candidates' => [[
                        'content' => [
                            'role' => 'model',
                            'parts' => [[
                                'functionCall' => [
                                    'name' => 'get_sales_summary',
                                    'args' => ['date' => '2026-08-22'],
                                ],
                                'thoughtSignature' => 'sig-abc-123',
                            ]],
                        ],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
                ], 200)
                ->push([
                    'candidates' => [[
                        'content' => [
                            'role' => 'model',
                            'parts' => [['text' => 'Sales were KES 1,000.']],
                        ],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 8, 'totalTokenCount' => 28],
                ], 200),
        ]);

        $provider = new GeminiProvider('key', 'gemini-3.7-flash');
        $first = $provider->chat([
            'system' => 'You are Centrix.',
            'messages' => [['role' => 'user', 'content' => 'Sales today?']],
            'tools' => [[
                'name' => 'get_sales_summary',
                'description' => 'Sales summary',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass],
            ]],
        ]);

        $this->assertNotEmpty($first['tool_calls']);
        $this->assertSame('sig-abc-123', $first['tool_calls'][0]['thought_signature'] ?? null);
        $this->assertSame('sig-abc-123', $first['model_content']['parts'][0]['thoughtSignature'] ?? null);

        $second = $provider->continueWithToolResults([
            'system' => 'You are Centrix.',
            'messages' => [['role' => 'user', 'content' => 'Sales today?']],
            'tools' => [[
                'name' => 'get_sales_summary',
                'description' => 'Sales summary',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass],
            ]],
            'prior_tool_calls' => $first['tool_calls'],
            'prior_model_content' => $first['model_content'],
            'tool_results' => [[
                'id' => $first['tool_calls'][0]['id'],
                'name' => 'get_sales_summary',
                'result' => ['gross_sales' => 1000, 'transactions' => 2],
            ]],
        ]);

        $this->assertSame('Sales were KES 1,000.', $second['text']);

        Http::assertSent(function ($request) {
            $data = $request->data();
            if (! is_array($data['contents'] ?? null)) {
                return false;
            }
            foreach ($data['contents'] as $content) {
                foreach ($content['parts'] ?? [] as $part) {
                    if (($part['thoughtSignature'] ?? null) === 'sig-abc-123') {
                        return true;
                    }
                }
            }

            return false;
        });
    }

    public function test_malformed_function_call_maps_to_friendly_error(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'finishReason' => 'MALFORMED_FUNCTION_CALL',
                    'content' => ['parts' => []],
                ]],
            ], 200),
        ]);

        $provider = new GeminiProvider('key', 'gemini-3.7-flash');

        try {
            $provider->chat([
                'system' => 'x',
                'messages' => [['role' => 'user', 'content' => 'y']],
                'tools' => [[
                    'name' => 'get_sales_summary',
                    'description' => 'x',
                    'parameters' => ['type' => 'object', 'properties' => new \stdClass],
                ]],
            ]);
            $this->fail('Expected AiProviderException');
        } catch (AiProviderException $e) {
            $this->assertSame('malformed_function_call', $e->codeKey);
        }
    }
}
