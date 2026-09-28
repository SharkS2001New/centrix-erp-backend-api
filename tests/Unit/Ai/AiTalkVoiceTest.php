<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiSettingsResolver;
use Tests\TestCase;

class AiTalkVoiceTest extends TestCase
{
    public function test_normalize_talk_voice_id_accepts_known_and_aliases(): void
    {
        $this->assertSame('en-GB-female', AiSettingsResolver::normalizeTalkVoiceId('en-GB-female'));
        $this->assertSame('en-US-male', AiSettingsResolver::normalizeTalkVoiceId('en_US_male'));
        $this->assertSame('en-GB-female', AiSettingsResolver::normalizeTalkVoiceId('british'));
        $this->assertSame('en-GB-male', AiSettingsResolver::normalizeTalkVoiceId('male'));
        $this->assertSame('en-GB-female', AiSettingsResolver::normalizeTalkVoiceId('nope'));
    }

    public function test_talk_voice_returns_structured_preference(): void
    {
        $voice = AiSettingsResolver::talkVoice();
        $this->assertSame('en-GB-female', $voice['id']);
        $this->assertSame('en-GB', $voice['locale']);
        $this->assertSame('female', $voice['gender']);
        $this->assertNotEmpty($voice['label']);
    }
}
