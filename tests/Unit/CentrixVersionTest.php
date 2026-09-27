<?php

namespace Tests\Unit;

use App\Support\CentrixVersion;
use Tests\TestCase;

class CentrixVersionTest extends TestCase
{
    private string $original;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = CentrixVersion::filePath();
        $this->original = is_readable($this->path)
            ? trim((string) file_get_contents($this->path))
            : '1.0.0';
        CentrixVersion::write('1.2.3');
    }

    protected function tearDown(): void
    {
        if (preg_match('/^\d+\.\d+\.\d+$/', $this->original)) {
            CentrixVersion::write($this->original);
        }
        parent::tearDown();
    }

    public function test_current_and_label(): void
    {
        $this->assertSame('1.2.3', CentrixVersion::current());
        $this->assertSame('v1.2.3', CentrixVersion::label());
    }

    public function test_bump_patch_minor_major(): void
    {
        $this->assertSame('1.2.4', CentrixVersion::bump('patch'));
        $this->assertSame('1.3.0', CentrixVersion::bump('minor'));
        $this->assertSame('2.0.0', CentrixVersion::bump('major'));
    }
}
