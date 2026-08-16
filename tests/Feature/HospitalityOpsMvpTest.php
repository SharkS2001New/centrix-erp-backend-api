<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\HospitalityRoom;
use App\Models\HospitalityRoomType;
use App\Models\Organization;
use App\Models\User;
use App\Services\Hospitality\HospitalityServices;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class HospitalityOpsMvpTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);
        $this->user = User::where('username', 'admin')->firstOrFail();
        $this->org = Organization::query()->findOrFail($this->user->organization_id);
        $modules = $this->org->enabled_modules ?? [];
        $modules['hospitality'] = true;
        $modules['hospitality.backend'] = true;
        $modules['hospitality.dashboard'] = true;
        $modules['hospitality.reports'] = true;
        $this->org->forceFill(['enabled_modules' => $modules])->save();

        $settings = $this->org->module_settings ?? [];
        $hospitality = is_array($settings['hospitality'] ?? null) ? $settings['hospitality'] : [];
        $hospitality['services'] = array_merge(HospitalityServices::DEFAULTS, [
            'rooms' => true,
            'reservations' => true,
            'front_desk' => true,
            'folios' => true,
            'housekeeping' => true,
            'night_audit' => true,
            'room_charge' => true,
        ]);
        $this->org->putModuleSettingsSection('hospitality', $hospitality);

        Sanctum::actingAs($this->user);
    }

    public function test_reservation_check_in_folio_night_audit_checkout_flow(): void
    {
        $type = HospitalityRoomType::query()->create([
            'organization_id' => $this->org->id,
            'code' => 'STD'.random_int(10, 99),
            'name' => 'Standard',
            'base_rate' => 5000,
            'max_occupancy' => 2,
            'is_active' => true,
        ]);
        $room = HospitalityRoom::query()->create([
            'organization_id' => $this->org->id,
            'room_type_id' => $type->id,
            'room_number' => 'T'.random_int(100, 999),
            'floor' => '1',
            'status' => 'vacant',
            'is_active' => true,
        ]);

        $arrival = now()->toDateString();
        $departure = now()->addDay()->toDateString();

        $res = $this->postJson('/api/v1/hospitality/reservations', [
            'guest_name' => 'Test Guest',
            'room_type_id' => $type->id,
            'room_id' => $room->id,
            'arrival_date' => $arrival,
            'departure_date' => $departure,
            'deposit_amount' => 0,
        ])->assertCreated();

        $reservationId = (int) $res->json('reservation.id');
        $this->assertGreaterThan(0, $reservationId);

        $checkIn = $this->postJson('/api/v1/hospitality/front-desk/check-in', [
            'reservation_id' => $reservationId,
            'room_id' => $room->id,
        ])->assertCreated();

        $folioId = (int) $checkIn->json('folio.id');
        $this->assertGreaterThan(0, $folioId);
        $this->assertSame('occupied', $room->fresh()->status);

        $preview = $this->getJson('/api/v1/hospitality/night-audit/preview')
            ->assertOk()
            ->json();
        $this->assertGreaterThanOrEqual(1, (int) ($preview['rooms_count'] ?? 0));
        $this->assertArrayHasKey('manager_flash', $preview);
        $this->assertNotEmpty($preview['manager_flash']['rows'] ?? []);

        $this->postJson('/api/v1/hospitality/night-audit/run', [
            'business_date' => now()->toDateString(),
        ])->assertOk()
            ->assertJsonPath('rooms_posted', 1)
            ->assertJsonStructure(['manager_flash' => ['columns', 'rows']]);

        $folio = $this->getJson("/api/v1/hospitality/folios/{$folioId}")
            ->assertOk()
            ->json('folio');
        $this->assertGreaterThan(0, (float) $folio['balance']);

        $this->postJson("/api/v1/hospitality/folios/{$folioId}/payments", [
            'method_code' => 'CASH',
            'amount' => (float) $folio['balance'],
        ])->assertOk();

        $this->postJson("/api/v1/hospitality/front-desk/folios/{$folioId}/check-out", [])
            ->assertOk();

        $this->assertSame('dirty', $room->fresh()->status);
        $this->assertSame('checked_out', $folioId ? $this->getJson("/api/v1/hospitality/folios/{$folioId}")->json('folio.status') : null);

        $this->getJson('/api/v1/hospitality/dashboard')->assertOk()
            ->assertJsonStructure(['rooms', 'arrivals_today', 'open_folios', 'fnb_today']);

        $this->getJson('/api/v1/reports/hospitality-occupancy')->assertOk();
    }

    public function test_front_desk_check_in_without_folios_occupies_room(): void
    {
        $settings = $this->org->module_settings ?? [];
        $hospitality = is_array($settings['hospitality'] ?? null) ? $settings['hospitality'] : [];
        $hospitality['services'] = array_merge(HospitalityServices::DEFAULTS, [
            'rooms' => true,
            'reservations' => true,
            'front_desk' => true,
            'folios' => false,
            'room_charge' => false,
            'night_audit' => false,
        ]);
        $this->org->putModuleSettingsSection('hospitality', $hospitality);

        $type = HospitalityRoomType::query()->create([
            'organization_id' => $this->org->id,
            'code' => 'PAY'.random_int(10, 99),
            'name' => 'Pay Now',
            'base_rate' => 3000,
            'max_occupancy' => 2,
            'is_active' => true,
        ]);
        $room = HospitalityRoom::query()->create([
            'organization_id' => $this->org->id,
            'room_type_id' => $type->id,
            'room_number' => 'P'.random_int(100, 999),
            'floor' => '1',
            'status' => 'vacant',
            'is_active' => true,
        ]);

        $checkIn = $this->postJson('/api/v1/hospitality/front-desk/check-in', [
            'guest_name' => 'Cash Guest',
            'guest_phone' => '0700000000',
            'room_id' => $room->id,
        ])->assertCreated();

        $this->assertNull($checkIn->json('folio'));
        $this->assertSame('Cash Guest', $checkIn->json('occupancy.guest_name'));
        $this->assertSame('occupied', $room->fresh()->status);
        $this->assertSame('Cash Guest', $room->fresh()->guest_name);

        $inHouse = $this->getJson('/api/v1/hospitality/front-desk/in-house')->assertOk()->json('data');
        $this->assertTrue(collect($inHouse)->contains(fn ($row) => ($row['room_id'] ?? null) == $room->id));

        $this->postJson("/api/v1/hospitality/front-desk/rooms/{$room->id}/check-out", [])
            ->assertOk();

        $fresh = $room->fresh();
        $this->assertSame('dirty', $fresh->status);
        $this->assertNull($fresh->guest_name);
    }

    public function test_pos_room_sale_occupies_until_checkout_then_releases(): void
    {
        $modules = $this->org->enabled_modules ?? [];
        $modules['hospitality.bar_pos'] = true;
        $this->org->forceFill(['enabled_modules' => $modules])->save();

        $settings = $this->org->module_settings ?? [];
        $hospitality = is_array($settings['hospitality'] ?? null) ? $settings['hospitality'] : [];
        $hospitality['services'] = array_merge(HospitalityServices::DEFAULTS, [
            'rooms' => true,
            'front_desk' => true,
            'folios' => false,
            'table_pos' => false,
            'room_charge' => false,
        ]);
        $this->org->putModuleSettingsSection('hospitality', $hospitality);

        $type = HospitalityRoomType::query()->create([
            'organization_id' => $this->org->id,
            'code' => 'POS'.random_int(10, 99),
            'name' => 'POS Twin',
            'base_rate' => 4500,
            'max_occupancy' => 2,
            'is_active' => true,
        ]);
        $room = HospitalityRoom::query()->create([
            'organization_id' => $this->org->id,
            'room_type_id' => $type->id,
            'room_number' => 'R'.random_int(100, 999),
            'floor' => '2',
            'status' => 'vacant',
            'is_active' => true,
        ]);

        $rooms = $this->getJson('/api/v1/hospitality/pos/rooms')->assertOk()->json('data');
        $this->assertTrue(collect($rooms)->contains(fn ($r) => (int) $r['id'] === (int) $room->id));

        $checkId = (int) $this->postJson('/api/v1/hospitality/pos/checks', [])
            ->assertCreated()
            ->json('check.id');

        $checkout = now()->addDays(2)->setTime(10, 0)->toIso8601String();
        $this->postJson("/api/v1/hospitality/pos/checks/{$checkId}/room-stays", [
            'room_id' => $room->id,
            'nights' => 2,
            'checkout_at' => $checkout,
            'guest_name' => 'Walk-in Guest',
        ])->assertOk()
            ->assertJsonPath('check.guest_name', 'Walk-in Guest');

        $this->assertSame('vacant', $room->fresh()->status);

        $this->postJson("/api/v1/hospitality/pos/checks/{$checkId}/settle", [
            'payments' => [['method_code' => 'CASH', 'amount' => 9000]],
        ])->assertOk();

        $fresh = $room->fresh();
        $this->assertSame('occupied', $fresh->status);
        $this->assertSame('Walk-in Guest', $fresh->guest_name);
        $this->assertNotNull($fresh->expected_checkout_at);

        $listed = collect($this->getJson('/api/v1/hospitality/rooms?per_page=200')->assertOk()->json('data'));
        $row = $listed->first(fn ($r) => (int) $r['id'] === (int) $room->id);
        $this->assertSame('pos_room_sale', $row['occupancy_source'] ?? null);
        $this->assertFalse($row['pos_sellable'] ?? true);

        $this->putJson("/api/v1/hospitality/rooms/{$room->id}", ['status' => 'vacant'])
            ->assertUnprocessable();

        $inHouse = collect($this->getJson('/api/v1/hospitality/front-desk/in-house')->assertOk()->json('data'));
        $this->assertTrue($inHouse->contains(fn ($r) => (int) ($r['room_id'] ?? 0) === (int) $room->id));

        $released = app(\App\Services\Hospitality\HospitalityPosRoomSaleService::class)
            ->releaseExpiredStays(now()->addDays(3));
        $this->assertGreaterThanOrEqual(1, $released['released']);
        $this->assertSame('dirty', $room->fresh()->status);
        $this->assertNull($room->fresh()->guest_name);
    }

    public function test_backoffice_bulk_creates_vacant_rooms_and_rejects_occupied_status(): void
    {
        $type = HospitalityRoomType::query()->create([
            'organization_id' => $this->org->id,
            'code' => 'BLK'.random_int(10, 99),
            'name' => 'Bulk',
            'base_rate' => 2500,
            'max_occupancy' => 2,
            'is_active' => true,
        ]);
        $start = 'B'.random_int(200, 700);

        $created = $this->postJson('/api/v1/hospitality/rooms/bulk', [
            'room_type_id' => $type->id,
            'start_number' => $start,
            'count' => 3,
            'floor' => '3',
        ])->assertCreated()->json('data');

        $this->assertCount(3, $created);
        $this->assertSame('vacant', $created[0]['status']);
        $this->assertTrue($created[0]['pos_sellable']);

        $this->postJson('/api/v1/hospitality/rooms', [
            'room_type_id' => $type->id,
            'room_number' => 'OCC'.random_int(100, 999),
            'status' => 'occupied',
        ])->assertUnprocessable();
    }
}
