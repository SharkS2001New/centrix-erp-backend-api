<?php

namespace Tests\Feature;

use Tests\TestCase;

class RecordResponseTimeMiddlewareTest extends TestCase
{
    public function test_api_responses_include_timing_headers(): void
    {
        $response = $this->getJson('/api/v1/health?connectivity=1')
            ->assertOk()
            ->assertHeader('X-Response-Time')
            ->assertHeader('Server-Timing');

        $xResponseTime = (string) $response->headers->get('X-Response-Time');
        $this->assertMatchesRegularExpression('/^\d+ms$/', $xResponseTime);
        $this->assertMatchesRegularExpression('/dur=\d+/', (string) $response->headers->get('Server-Timing'));
    }
}
