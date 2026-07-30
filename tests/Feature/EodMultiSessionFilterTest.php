<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Till;
use App\Models\TillFloatSession;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class EodMultiSessionFilterTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected Till $till;

    protected string $productCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);

        $this->user = User::where('username', 'admin')->firstOrFail();
        $this->till = Till::firstOrFail();
        $this->productCode = Product::query()->value('product_code');
        Sanctum::actingAs($this->user);

        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'require_pos_till_float' => true,
        ]);
        $org->update(['module_settings' => $settings]);

        TillFloatSession::query()
            ->where('cashier_id', $this->user->id)
            ->whereDate('session_date', now()->toDateString())
            ->update([
                'status' => 'closed',
                'closed_at' => now(),
                'session_date' => now()->subDay()->toDateString(),
            ]);
    }

    public function test_eod_lists_each_session_maths_and_can_filter_one_session(): void
    {
        $first = $this->openSession(4000);
        $this->completeSaleOnSession($first->id, 1);
        $this->postJson("/api/v1/pos/sessions/{$first->id}/close", [
            'closing_amount' => 4000,
        ])->assertOk();

        $second = $this->openSession(1500);
        $this->completeSaleOnSession($second->id, 1);

        $date = now()->toDateString();
        $all = $this->getJson('/api/v1/reports/eod-report?sale_date='.$date.'&cashier_id='.$this->user->id)
            ->assertOk()
            ->json();

        $sessionIds = collect($all['sessions'] ?? [])->pluck('float_session_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $first->id, $sessionIds);
        $this->assertContains((int) $second->id, $sessionIds);

        $firstRow = collect($all['sessions'])->firstWhere('float_session_id', (int) $first->id);
        $secondRow = collect($all['sessions'])->firstWhere('float_session_id', (int) $second->id);
        $this->assertNotNull($firstRow);
        $this->assertNotNull($secondRow);
        $this->assertEqualsWithDelta(4000, (float) $firstRow['opening_float'], 0.01);
        $this->assertEqualsWithDelta(1500, (float) $secondRow['opening_float'], 0.01);
        $this->assertGreaterThan(0, (float) $firstRow['gross_sales']);
        $this->assertGreaterThan(0, (float) $secondRow['gross_sales']);
        $this->assertArrayHasKey('expected_net_sales', $firstRow);
        $this->assertArrayHasKey('expected_net_sales', $secondRow);

        // Unfiltered opening float sums both sessions.
        $this->assertEqualsWithDelta(
            5500,
            (float) ($all['summary']['opening_float'] ?? 0),
            0.01,
        );

        $filtered = $this->getJson(
            '/api/v1/reports/eod-report?sale_date='.$date
            .'&cashier_id='.$this->user->id
            .'&float_session_id='.$second->id,
        )->assertOk()->json();

        $this->assertSame((int) $second->id, (int) ($filtered['float_session_id'] ?? 0));
        $this->assertEqualsWithDelta(
            1500,
            (float) ($filtered['summary']['opening_float'] ?? 0),
            0.01,
        );
        // Sessions list still includes both for the filter UI.
        $filteredIds = collect($filtered['sessions'] ?? [])->pluck('float_session_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $first->id, $filteredIds);
        $this->assertContains((int) $second->id, $filteredIds);
    }

    protected function openSession(float $float): TillFloatSession
    {
        $response = $this->postJson('/api/v1/pos/sessions/open', [
            'till_id' => $this->till->id,
            'branch_id' => $this->user->branch_id,
            'working_amount' => $float,
            'payment_type' => 'CASH',
            'float_breakdown' => [
                ['new_float' => $float, 'payment_type' => 'CASH'],
            ],
        ])->assertCreated()->json();

        return TillFloatSession::query()->findOrFail((int) $response['id']);
    }

    protected function completeSaleOnSession(int $sessionId, float $qty): void
    {
        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
            'till_id' => $this->till->id,
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => $qty,
        ])->assertCreated();

        $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'payment_method_code' => 'CASH',
            'float_session_id' => $sessionId,
        ])->assertCreated();
    }
}
