<?php

namespace Tests\Unit;

use App\Services\SystemIssues\SystemIssueReporter;
use ReflectionException;
use Tests\TestCase;

class SystemIssueReporterTest extends TestCase
{
    public function test_formats_exception_like_laravel_log(): void
    {
        $reporter = app(SystemIssueReporter::class);
        $exception = new ReflectionException('Class "App\\Http\\Controllers\\Api\\V1\\ErpContext" does not exist');

        $formatted = $reporter->formatException($exception);

        $this->assertStringContainsString('[object] (ReflectionException(code:', $formatted);
        $this->assertStringContainsString('ErpContext', $formatted);
        $this->assertStringContainsString('[stacktrace]', $formatted);
        $this->assertStringContainsString('#0', $formatted);
    }

    public function test_summarizes_exception_for_issue_list(): void
    {
        $reporter = app(SystemIssueReporter::class);
        $exception = new ReflectionException('Class "ErpContext" does not exist');

        $summary = $reporter->summarizeException($exception);

        $this->assertStringStartsWith('ReflectionException: Class "ErpContext" does not exist', $summary);
    }
}
