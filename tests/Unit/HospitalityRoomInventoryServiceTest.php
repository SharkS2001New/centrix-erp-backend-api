<?php

namespace Tests\Unit;

use App\Services\Hospitality\HospitalityRoomInventoryService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HospitalityRoomInventoryServiceTest extends TestCase
{
    public function test_expands_padded_room_numbers(): void
    {
        $this->assertSame(
            ['101', '102', '103'],
            HospitalityRoomInventoryService::expandRoomNumbers('101', 3),
        );
        $this->assertSame(
            ['G01', 'G02'],
            HospitalityRoomInventoryService::expandRoomNumbers('G01', 2),
        );
    }

    public function test_rejects_start_without_digits(): void
    {
        $this->expectException(ValidationException::class);
        HospitalityRoomInventoryService::expandRoomNumbers('Suite', 2);
    }
}
