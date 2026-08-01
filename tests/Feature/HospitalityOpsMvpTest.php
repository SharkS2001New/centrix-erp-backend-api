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

        $this->postJson('/api/v1/hospitality/night-audit/run', [
            'business_date' => now()->toDateString(),
        ])->assertOk()
            ->assertJsonPath('rooms_posted', 1);

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
}
