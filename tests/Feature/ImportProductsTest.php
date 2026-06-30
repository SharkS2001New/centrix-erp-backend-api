<?php

namespace Tests\Feature;

use App\Jobs\ImportProductsJob;
use App\Models\BackgroundTask;
use App\Models\Category;
use App\Models\Product;
use App\Models\SubCategory;
use App\Models\Uom;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ImportProductsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_product_import_resolves_subcategory_by_category_and_name(): void
    {
        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $organizationId = (int) $admin->organization_id;

        $category = Category::query()
            ->where('organization_id', $organizationId)
            ->where('category_name', 'Food & Beverage')
            ->firstOrFail();
        $subcategory = SubCategory::query()
            ->where('organization_id', $organizationId)
            ->where('subcategory_name', 'Sugar')
            ->firstOrFail();
        $uom = Uom::query()
            ->where('organization_id', $organizationId)
            ->where('full_name', 'Kilogram')
            ->firstOrFail();

        $task = BackgroundTask::createPending('product_import', $organizationId, (int) $admin->id, [
            'rows' => [
                [
                    'product_code' => 'TEST-IMPORT-001',
                    'product_name' => 'Imported Product',
                    'category_name' => $category->category_name,
                    'subcategory_name' => $subcategory->subcategory_name,
                    'measure_name' => $uom->full_name,
                    'unit_price' => 100,
                ],
            ],
        ]);

        (new ImportProductsJob($task->id))->handle(app(BackgroundTaskService::class));

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame(1, $task->result['created'] ?? null);
        $this->assertSame(0, $task->result['failed'] ?? null);

        $product = Product::query()
            ->where('organization_id', $organizationId)
            ->where('product_code', 'TEST-IMPORT-001')
            ->firstOrFail();

        $this->assertSame((int) $subcategory->id, (int) $product->subcategory_id);
        $this->assertSame((int) $uom->id, (int) $product->unit_id);
    }
}
