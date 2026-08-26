<?php

namespace Tests\Unit\Ai;

use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiToolResultReplyBuilder;
use App\Services\Ai\Tools\GetProductPriceHistoryTool;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class GetProductPriceHistoryToolTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_returns_formal_price_history_for_product(): void
    {
        if (! Schema::hasTable('price_history') || ! Schema::hasTable('products')) {
            $this->markTestSkipped('price_history / products tables missing');
        }

        $admin = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $admin->organization_id;

        $product = Product::query()
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->orderBy('product_code')
            ->first();
        if (! $product) {
            $this->markTestSkipped('No products available for price history test');
        }

        $code = (string) $product->product_code;

        PriceHistory::query()->create([
            'organization_id' => $orgId,
            'product_code' => $code,
            'unit_price' => 5800,
            'cost_price' => 5500,
            'discount_pct' => 0,
            'changed_by' => $admin->id,
            'changed_at' => now()->subDays(10),
        ]);
        PriceHistory::query()->create([
            'organization_id' => $orgId,
            'product_code' => $code,
            'unit_price' => 6200.01,
            'cost_price' => 6000,
            'discount_pct' => 0,
            'changed_by' => $admin->id,
            'changed_at' => now()->subDay(),
        ]);

        try {
            $result = app(GetProductPriceHistoryTool::class)->execute($admin, [
                'product_code' => $code,
            ]);
        } catch (\Throwable $e) {
            $this->fail('Tool threw: '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());
        }

        if (! empty($result['error'])) {
            $this->fail('Tool error: '.json_encode($result));
        }

        $this->assertSame('price_history', $result['source'] ?? null);
        $this->assertSame((string) $product->product_name, $result['product']['product_name'] ?? null);
        $this->assertGreaterThanOrEqual(2, (int) ($result['count'] ?? 0));
        $this->assertEqualsWithDelta(6200.01, (float) ($result['history'][0]['unit_price'] ?? 0), 0.001);
        $this->assertStringContainsString('/price-history', (string) json_encode($result['screens'] ?? []));
    }
}
