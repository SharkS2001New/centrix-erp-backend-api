<?php

namespace Tests\Unit;

use Monolog\Formatter\JsonFormatter;
use Tests\TestCase;

class LoggingConfigurationTest extends TestCase
{
    public function test_stderr_channel_uses_the_json_formatter_class_directly(): void
    {
        $this->assertSame(
            JsonFormatter::class,
            config('logging.channels.stderr.formatter'),
        );
    }
}
