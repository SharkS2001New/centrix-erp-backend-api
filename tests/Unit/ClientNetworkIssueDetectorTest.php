<?php

namespace Tests\Unit;

use App\Support\ClientNetworkIssueDetector;
use Tests\TestCase;

class ClientNetworkIssueDetectorTest extends TestCase
{
    public function test_detects_mobile_timeout_message(): void
    {
        $this->assertTrue(ClientNetworkIssueDetector::isClientNetworkIssue(
            'Request timed out. Check your connection and try again.',
            ['source' => 'mobile'],
        ));
    }

    public function test_detects_user_message_in_context(): void
    {
        $this->assertTrue(ClientNetworkIssueDetector::isClientNetworkIssue(
            'Checkout failed',
            ['user_message' => 'Network request failed'],
        ));
    }

    public function test_does_not_flag_server_exceptions(): void
    {
        $this->assertFalse(ClientNetworkIssueDetector::isClientNetworkIssue(
            'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock',
            ['source' => 'server', 'exception_class' => 'Illuminate\\Database\\QueryException'],
        ));
    }
}
