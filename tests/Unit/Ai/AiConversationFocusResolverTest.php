<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiConversationFocusResolver;
use Tests\TestCase;

class AiConversationFocusResolverTest extends TestCase
{
    public function test_extracts_customer_from_assistant_profile_path(): void
    {
        $resolver = new AiConversationFocusResolver;
        $history = [
            [
                'role' => 'assistant',
                'content' => 'The balance of **KES 720,500** is outstanding for **BOSIBORI**. '
                    .'Open their profile at `/customers/9812999`.',
            ],
        ];

        $focus = $resolver->resolve($history);

        $this->assertCount(1, $focus['customers']);
        $this->assertSame('9812999', $focus['customers'][0]['customer_num']);
        $this->assertSame('BOSIBORI', $focus['customers'][0]['customer_name']);
    }

    public function test_enriches_entity_refs_when_user_uses_she_pronoun(): void
    {
        $resolver = new AiConversationFocusResolver;
        $history = [
            [
                'role' => 'assistant',
                'content' => 'Balance due for **BOSIBORI**. Profile: /customers/9812999',
            ],
        ];

        $refs = $resolver->enrichEntityRefs(
            'What has she been buying alot from us?',
            $history,
            [],
        );

        $this->assertCount(1, $refs);
        $this->assertSame('customer', $refs[0]['type']);
        $this->assertSame('9812999', $refs[0]['code']);
        $this->assertSame('BOSIBORI', $refs[0]['label']);
    }

    public function test_does_not_override_explicit_customer_mention(): void
    {
        $resolver = new AiConversationFocusResolver;
        $history = [
            ['role' => 'assistant', 'content' => 'See /customers/9812999'],
        ];
        $existing = [
            ['type' => 'customer', 'id' => '111', 'code' => '111', 'label' => 'OTHER'],
        ];

        $refs = $resolver->enrichEntityRefs('What has she bought?', $history, $existing);

        $this->assertCount(1, $refs);
        $this->assertSame('111', $refs[0]['code']);
    }

    public function test_prompt_block_includes_customer_num_for_tools(): void
    {
        $resolver = new AiConversationFocusResolver;
        $block = $resolver->promptBlock([
            'customers' => [[
                'customer_num' => '9812999',
                'customer_name' => 'BOSIBORI',
            ]],
            'suppliers' => [],
        ]);

        $this->assertStringContainsString('customer_num=9812999', $block);
        $this->assertStringContainsString('get_customer_statement', $block);
        $this->assertStringContainsString('BOSIBORI', $block);
    }

    public function test_detects_customer_pronouns(): void
    {
        $resolver = new AiConversationFocusResolver;

        $this->assertTrue($resolver->messageUsesCustomerPronoun('What has she been buying?'));
        $this->assertTrue($resolver->messageUsesCustomerPronoun('Show me their balance'));
        $this->assertTrue($resolver->messageUsesCustomerPronoun('Open this customer'));
        $this->assertFalse($resolver->messageUsesCustomerPronoun('What has bosibori been buying?'));
    }
}
