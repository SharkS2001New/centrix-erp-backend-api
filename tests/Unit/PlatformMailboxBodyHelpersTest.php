<?php

namespace Tests\Unit;

use App\Services\Platform\PlatformMailboxService;
use Tests\TestCase;

class PlatformMailboxBodyHelpersTest extends TestCase
{
    public function test_snippet_body_handles_invalid_utf8_without_wiping_content(): void
    {
        $service = app(PlatformMailboxService::class);
        $raw = "Hello ".chr(0xC3)." world"; // incomplete UTF-8 sequence mixed with ascii

        $snippet = $service->snippetBody($raw);

        $this->assertNotSame('', $snippet);
        $this->assertStringContainsString('Hello', $snippet);
        $this->assertStringContainsString('world', $snippet);
    }

    public function test_snippet_body_collapses_whitespace(): void
    {
        $service = app(PlatformMailboxService::class);

        $this->assertSame(
            'Line one Line two',
            $service->snippetBody("Line one\n\n  Line two  "),
        );
    }

    public function test_detects_unsupported_header_search_errors(): void
    {
        $service = app(PlatformMailboxService::class);
        $method = new \ReflectionMethod(PlatformMailboxService::class, 'imapErrorsIndicateUnsupportedHeaderSearch');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(
            $service,
            ['PHP Request Shutdown: Unknown search criterion: HEADER (errflg=2)'],
        ));
        $this->assertTrue($method->invoke(
            $service,
            ['Unknown search criterion: HEADER'],
        ));
        $this->assertFalse($method->invoke(
            $service,
            ['Can not authenticate to IMAP server'],
        ));
    }

    public function test_decode_part_charset_accepts_stdclass_parameters(): void
    {
        $service = app(PlatformMailboxService::class);
        $method = new \ReflectionMethod(PlatformMailboxService::class, 'decodePartCharset');
        $method->setAccessible(true);

        $part = (object) [
            // PHP IMAP often returns a single parameter object instead of a list.
            'parameters' => (object) [
                'attribute' => 'charset',
                'value' => 'UTF-8',
            ],
            'dparameters' => null,
        ];

        $this->assertSame(
            'Price adjustment',
            $method->invoke($service, 'Price adjustment', $part),
        );
    }

    public function test_imap_part_parameter_list_merges_array_and_object_params(): void
    {
        $service = app(PlatformMailboxService::class);
        $method = new \ReflectionMethod(PlatformMailboxService::class, 'imapPartParameterList');
        $method->setAccessible(true);

        $part = (object) [
            'parameters' => [
                (object) ['attribute' => 'NAME', 'value' => 'a.txt'],
            ],
            'dparameters' => (object) [
                'attribute' => 'CHARSET',
                'value' => 'ISO-8859-1',
            ],
        ];

        $rows = $method->invoke($service, $part);
        $this->assertCount(2, $rows);
        $this->assertSame('NAME', $rows[0]->attribute);
        $this->assertSame('CHARSET', $rows[1]->attribute);
    }
}
