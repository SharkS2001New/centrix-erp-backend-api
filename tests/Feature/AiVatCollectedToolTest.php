<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\AiToolRegistry;
use App\Services\Ai\Tools\GetVatCollectedTool;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiVatCollectedToolTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_returns_vat_totals_for_named_month(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        /** @var GetVatCollectedTool $tool */
        $tool = app(GetVatCollectedTool::class);
        $result = $tool->execute($admin, [
            'month' => 'august',
            'year' => 2026,
        ]);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame('2026-08-01', $result['period']['from_date'] ?? null);
        $this->assertSame('2026-08-31', $result['period']['to_date'] ?? null);
        $this->assertArrayHasKey('vat_collected_total', $result['summary'] ?? []);
        $this->assertArrayHasKey('taxable_sales_gross', $result['summary'] ?? []);
        $this->assertTrue(
            collect($result['screens'] ?? [])->contains(fn ($s) => ($s['path'] ?? null) === '/reports/vat-collected'),
        );
    }

    public function test_registry_includes_vat_collected_tool(): void
    {
        $names = array_map(fn ($tool) => $tool->name(), app(AiToolRegistry::class)->all());
        $this->assertContains('get_vat_collected', $names);
    }
}
