<?php

namespace Tests\Unit\Reports;

use App\Models\Product;
use App\Models\User;
use App\Services\Reports\ReportBuilderSuggestService;
use App\Support\AppTimezone;
use Carbon\Carbon;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ReportBuilderSuggestProductsTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_suggest_resolves_yesterday_and_asks_to_pick_ambiguous_products(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24 10:00:00', AppTimezone::name()));

        $admin = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $admin->organization_id;

        foreach ([
            ['SUG50', 'SUGAR 50 KG', 100],
            ['SUG10', 'SUGAR 10 KG', 40],
            ['POL01', 'POLISHED RICE 25KG', 80],
        ] as [$code, $name, $price]) {
            Product::query()->create([
                'product_code' => $code,
                'product_name' => $name,
                'subcategory_id' => 1,
                'unit_id' => 1,
                'unit_price' => $price,
                'vat_id' => 1,
                'organization_id' => $orgId,
                'created_by' => $admin->id,
            ]);
        }

        /** @var ReportBuilderSuggestService $service */
        $service = app(ReportBuilderSuggestService::class);
        $result = $service->suggest(
            $admin,
            'I need a report only for several products, Sugar and Polished, according to yesterdays sales',
            'backoffice',
        );

        $this->assertSame('2026-08-23', $result['filters']['from_date'] ?? null);
        $this->assertSame('2026-08-23', $result['filters']['to_date'] ?? null);
        $this->assertTrue($result['needs_product_selection'] ?? false);
        $this->assertSame('needs_selection', $result['product_resolution']['status'] ?? null);

        $withPick = $service->suggest(
            $admin,
            'I need a report only for several products, Sugar and Polished, according to yesterdays sales',
            'backoffice',
            ['SUG50', 'POL01'],
        );

        $this->assertFalse($withPick['needs_product_selection'] ?? true);
        $this->assertEqualsCanonicalizing(['SUG50', 'POL01'], $withPick['filters']['product_codes'] ?? []);
    }

    public function test_suggest_applies_entity_refs_without_product_selection(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24 10:00:00', AppTimezone::name()));

        $admin = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $admin->organization_id;

        Product::query()->create([
            'product_code' => 'MENTION1',
            'product_name' => 'Mentioned Cola',
            'subcategory_id' => 1,
            'unit_id' => 1,
            'unit_price' => 50,
            'vat_id' => 1,
            'organization_id' => $orgId,
            'created_by' => $admin->id,
        ]);

        /** @var ReportBuilderSuggestService $service */
        $service = app(ReportBuilderSuggestService::class);
        $result = $service->suggest(
            $admin,
            'Yesterday sales for Mentione d Cola',
            'backoffice',
            null,
            [
                [
                    'type' => 'product',
                    'code' => 'MENTION1',
                    'label' => 'Mentioned Cola',
                ],
            ],
        );

        $this->assertFalse($result['needs_product_selection'] ?? true);
        $this->assertEqualsCanonicalizing(['MENTION1'], $result['filters']['product_codes'] ?? []);
        $this->assertSame('2026-08-23', $result['filters']['from_date'] ?? null);
    }
}
