<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Till;
use App\Models\TillFloatSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

    public function test_eod_attributes_expenses_to_cashier_session_maths(): void
    {
        $groupId = (int) DB::table('expense_groups')
            ->where('organization_id', $this->user->organization_id)
            ->value('id');
        $this->assertGreaterThan(0, $groupId);
        $methodId = (int) PaymentMethod::where('method_code', 'CASH')->value('id');
        $this->assertGreaterThan(0, $methodId);

        $first = $this->openSession(4000);
        $this->postJson("/api/v1/pos/sessions/{$first->id}/expenses", [
            'expense_group_id' => $groupId,
            'expense_amount' => 100,
            'description' => 'session-one-expense',
            'payment_method_id' => $methodId,
        ])->assertCreated();
        $this->postJson("/api/v1/pos/sessions/{$first->id}/close", [
            'closing_amount' => 3900,
        ])->assertOk();

        $second = $this->openSession(1500);
        $this->postJson("/api/v1/pos/sessions/{$second->id}/expenses", [
            'expense_group_id' => $groupId,
            'expense_amount' => 50,
            'description' => 'session-two-expense',
            'payment_method_id' => $methodId,
        ])->assertCreated();

        // Backoffice / date-only expense — must not enter till EOD maths or summary.
        $branchOnly = [
            'branch_id' => $this->user->branch_id,
            'expense_group_id' => $groupId,
            'float_session_id' => null,
            'description' => 'branch-only-expense',
            'expense_amount' => 9999,
            'expense_date' => now()->toDateString(),
            'payment_method_id' => $methodId,
            'recorded_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (\Illuminate\Support\Facades\Schema::hasColumn('expenses', 'organization_id')) {
            $branchOnly['organization_id'] = $this->user->organization_id;
        }
        DB::table('expenses')->insert($branchOnly);

        $date = now()->toDateString();
        $all = $this->getJson('/api/v1/reports/eod-report?sale_date='.$date.'&cashier_id='.$this->user->id)
            ->assertOk()
            ->json();

        $descriptions = collect($all['expenses'] ?? [])->pluck('description')->filter()->all();
        $this->assertContains('session-one-expense', $descriptions);
        $this->assertContains('session-two-expense', $descriptions);
        $this->assertNotContains('branch-only-expense', $descriptions);
        $this->assertEqualsWithDelta(150, (float) ($all['total_expenses'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(150, (float) ($all['summary']['session_expenses'] ?? 0), 0.01);

        $firstRow = collect($all['sessions'])->firstWhere('float_session_id', (int) $first->id);
        $secondRow = collect($all['sessions'])->firstWhere('float_session_id', (int) $second->id);
        $this->assertEqualsWithDelta(100, (float) ($firstRow['session_expenses'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(50, (float) ($secondRow['session_expenses'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(
            (float) $firstRow['opening_float'] + (float) $firstRow['gross_sales'] - 100,
            (float) $firstRow['expected_net_sales'],
            0.01,
        );

        $filtered = $this->getJson(
            '/api/v1/reports/eod-report?sale_date='.$date
            .'&cashier_id='.$this->user->id
            .'&float_session_id='.$second->id,
        )->assertOk()->json();

        $filteredDescriptions = collect($filtered['expenses'] ?? [])->pluck('description')->filter()->all();
        $this->assertSame(['session-two-expense'], array_values($filteredDescriptions));
        $this->assertEqualsWithDelta(50, (float) ($filtered['total_expenses'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(50, (float) ($filtered['summary']['session_expenses'] ?? 0), 0.01);
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
