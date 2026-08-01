<?php

namespace Tests\Unit\Hospitality;

use App\Services\Hospitality\HospitalityServices;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HospitalityServicesTest extends TestCase
{
    #[Test]
    public function defaults_include_rooms_only(): void
    {
        $defaults = HospitalityServices::DEFAULTS;

        $this->assertTrue($defaults['rooms']);
        $this->assertFalse($defaults['reservations']);
        $this->assertFalse($defaults['front_desk']);
        $this->assertFalse($defaults['folios']);
        $this->assertFalse($defaults['housekeeping']);
        $this->assertFalse($defaults['night_audit']);
        $this->assertFalse($defaults['extra_outlets']);
        $this->assertFalse($defaults['floor_tables']);
        $this->assertFalse($defaults['table_pos']);
        $this->assertFalse($defaults['room_charge']);
    }

    #[Test]
    public function normalize_merges_overrides_onto_defaults(): void
    {
        $normalized = HospitalityServices::normalize([
            'reservations' => true,
            'extra_outlets' => '1',
            'unknown' => true,
        ]);

        $this->assertTrue($normalized['rooms']);
        $this->assertTrue($normalized['reservations']);
        $this->assertTrue($normalized['extra_outlets']);
        $this->assertFalse($normalized['folios']);
        $this->assertArrayNotHasKey('unknown', $normalized);
    }

    #[Test]
    public function present_for_organization_marks_main_outlet_always_on(): void
    {
        $presented = HospitalityServices::presentForOrganization(null);

        $this->assertTrue($presented['main_outlet']);
        $this->assertTrue($presented['services']['rooms']);
        $this->assertSame(HospitalityServices::CATALOG, $presented['catalog']);
    }
}
