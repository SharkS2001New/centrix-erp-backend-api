<?php

namespace Tests;

use App\Support\SchemaProbe;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        // Bridges/controllers may call set_time_limit(N) during a test; that limit
        // persists for the whole PHPUnit process and can kill later bootstraps.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        parent::setUp();

        // Unit cases may observe missing tables before Feature migrations run.
        SchemaProbe::flushRequestCache();
        SchemaProbe::forget('platform_subscriptions');
    }
}
