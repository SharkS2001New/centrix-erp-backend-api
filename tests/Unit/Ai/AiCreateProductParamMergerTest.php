<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiCreateProductParamMerger;
use App\Services\Ai\AiEntitySchemaCatalog;
use Tests\TestCase;

class AiCreateProductParamMergerTest extends TestCase
{
    public function test_extracts_labeled_product_fields(): void
    {
        $merger = app(AiCreateProductParamMerger::class);
        $fields = $merger->extractFields(
            "Product name: Sugar Ai Test\nCategory: Sugar(grocery)\nUom: bags\nSelling price: 120\nCost price: 100\nVAT rate: VAT 16%"
        );

        $this->assertSame('Sugar Ai Test', $fields['product_name']);
        $this->assertSame(120.0, $fields['unit_price']);
        $this->assertSame(100.0, $fields['last_cost_price']);
        $this->assertSame('Sugar(grocery)', $fields['subcategory']);
        $this->assertSame('bags', $fields['unit']);
        $this->assertSame('VAT 16%', $fields['vat']);
    }

    public function test_extracts_short_vat_reply(): void
    {
        $merger = app(AiCreateProductParamMerger::class);

        $this->assertTrue($merger->looksLikeFieldFollowUp('Vat 16%'));
        $this->assertTrue($merger->looksLikeFieldFollowUp('vat rate: vat 16%'));
        $this->assertSame('vat 16%', strtolower($merger->extractFields('Vat 16%')['vat']));
    }

    public function test_matches_vat_option_fuzzy(): void
    {
        $merger = app(AiCreateProductParamMerger::class);
        $match = $merger->matchOption('Vat 16%', [
            ['value' => 1, 'label' => 'VAT Exempt'],
            ['value' => 2, 'label' => 'VAT 16%'],
            ['value' => 3, 'label' => 'Vatable'],
        ]);

        $this->assertNotNull($match);
        $this->assertSame(2, $match['value']);
    }

    public function test_merges_vat_into_pending_params(): void
    {
        $catalog = $this->createMock(AiEntitySchemaCatalog::class);
        $catalog->method('forEntityWithOptions')->willReturn([
            'fields' => [
                'vat_id' => [
                    'label' => 'VAT rate',
                    'options' => [
                        ['value' => 9, 'label' => 'VAT 16%'],
                        ['value' => 1, 'label' => 'VAT Exempt'],
                    ],
                ],
                'unit_id' => ['label' => 'Unit of measure', 'options' => []],
                'subcategory_id' => ['label' => 'Sub-category', 'options' => []],
                'supplier_id' => ['label' => 'Supplier', 'options' => []],
            ],
        ]);

        $merger = new AiCreateProductParamMerger($catalog);
        $user = new \App\Models\User;
        $result = $merger->merge(
            $user,
            [
                'type' => 'create_product',
                'params' => [
                    'product_name' => 'Sugar Ai Test',
                    'unit_price' => 120,
                ],
            ],
            'Vat 16%',
            [],
        );

        $this->assertTrue($result['changed']);
        $this->assertSame(9, $result['pending']['params']['vat_id']);
    }
}
