<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiSettingsResolver;
use Tests\TestCase;

class AiSettingsResolverOpenAiCompatibleTest extends TestCase
{
    public function test_blank_base_url_defaults_to_openai_even_with_groq_key(): void
    {
        $resolved = AiSettingsResolver::resolveOpenAiCompatibleConfig('gsk_test_key');

        $this->assertSame('https://api.openai.com/v1', $resolved['base_url']);
        $this->assertSame('gpt-4o-mini', $resolved['model']);
    }

    public function test_openai_key_defaults_to_openai_base_url(): void
    {
        $resolved = AiSettingsResolver::resolveOpenAiCompatibleConfig('sk-test-key');

        $this->assertSame('https://api.openai.com/v1', $resolved['base_url']);
        $this->assertSame('gpt-4o-mini', $resolved['model']);
    }

    public function test_explicit_compatible_base_url_is_kept(): void
    {
        $resolved = AiSettingsResolver::resolveOpenAiCompatibleConfig(
            'gsk_test_key',
            'llama-3.3-70b-versatile',
            'https://api.groq.com/openai/v1',
        );

        $this->assertSame('https://api.groq.com/openai/v1', $resolved['base_url']);
        $this->assertSame('llama-3.3-70b-versatile', $resolved['model']);
    }

    public function test_double_v1_suffix_is_normalized(): void
    {
        $resolved = AiSettingsResolver::resolveOpenAiCompatibleConfig(
            'sk-test-key',
            '',
            'https://api.openai.com/v1/v1',
        );

        $this->assertSame('https://api.openai.com/v1', $resolved['base_url']);
    }
}
