<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiSpokenEntityResolver;
use Tests\TestCase;

class AiSpokenEntityResolverTest extends TestCase
{
    public function test_extract_terms_drops_stopwords_and_keeps_local_names(): void
    {
        $resolver = app(AiSpokenEntityResolver::class);
        $terms = $resolver->extractTerms('What is the stock of Kamande today?');

        $this->assertContains('kamande', $terms);
        $this->assertNotContains('stock', $terms);
        $this->assertNotContains('today', $terms);
    }

    public function test_score_spoken_matches_exact_and_contains(): void
    {
        $resolver = app(AiSpokenEntityResolver::class);

        $this->assertGreaterThanOrEqual(99, $resolver->scoreSpoken('kamande', 'Kamande'));
        $this->assertLessThan(60, $resolver->scoreSpoken('kamande', 'Sugar 50kg'));
        $contains = $resolver->scoreSpoken('kamande', 'Royal Kamande Meal');
        $this->assertGreaterThanOrEqual(85, $contains);
    }

    public function test_score_spoken_handles_stt_garble_via_metaphone(): void
    {
        if (! function_exists('metaphone')) {
            $this->markTestSkipped('metaphone extension unavailable');
        }

        $resolver = app(AiSpokenEntityResolver::class);
        // Common mic mis-hear for Kamande.
        $score = $resolver->scoreSpoken('command', 'Kamande');
        $this->assertGreaterThanOrEqual(
            70,
            $score,
            'Expected phonetic match between "command" and "Kamande", got '.$score
        );
    }
}
