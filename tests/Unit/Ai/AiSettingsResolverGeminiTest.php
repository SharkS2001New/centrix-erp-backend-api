<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiSettingsResolver;
use Tests\TestCase;

class AiSettingsResolverGeminiTest extends TestCase
{
    public function test_infer_provider_from_aq_key(): void
    {
        $this->assertSame('gemini', AiSettingsResolver::inferProviderFromApiKey('AQ.Ab8RN6LNZQZb_suK_jyWcOpcqm8AIzeCxtBL3mpVTd-Lcv0GLw'));
    }

    public function test_infer_provider_from_aiza_key(): void
    {
        $this->assertSame('gemini', AiSettingsResolver::inferProviderFromApiKey('AIzaSyExampleKey'));
    }

    public function test_infer_provider_from_openai_key(): void
    {
        $this->assertSame('openai', AiSettingsResolver::inferProviderFromApiKey('sk-test-openai-key'));
    }

    public function test_normalize_retired_gemini_models(): void
    {
        config(['ai.gemini.model' => 'gemini-3.6-flash']);

        $this->assertSame('gemini-3.6-flash', AiSettingsResolver::normalizeGeminiModel('gemini-2.0-flash'));
        $this->assertSame('gemini-3.6-flash', AiSettingsResolver::normalizeGeminiModel('gemini-3.7-flash'));
        $this->assertSame('gemini-3.6-flash', AiSettingsResolver::normalizeGeminiModel(''));
        $this->assertSame('gemini-3.6-flash', AiSettingsResolver::normalizeGeminiModel('gemini-3.6-flash'));
    }
}
