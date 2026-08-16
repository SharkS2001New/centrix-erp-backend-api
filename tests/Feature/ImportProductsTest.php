<?php

namespace Tests\Feature;

use App\Jobs\ImportProductsJob;
use App\Models\BackgroundTask;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\SubCategory;
use App\Models\Uom;
use App\Models\User;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ImportProductsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_product_import_resolves_subcategory_by_category_and_name(): void
    {
        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $organizationId = (int) $admin->organization_id;
        [$category, $subcategory, $uom] = $this->catalogFixtures($organizationId);

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

        $this->app->call([new ImportProductsJob($task->id), 'handle']);

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
        $this->assertNull($product->branch_id);
    }

    public function test_hospitality_menu_import_defaults_bar_and_hotel_channels(): void
    {
        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $organizationId = (int) $admin->organization_id;
        [$category, $subcategory, $uom] = $this->catalogFixtures($organizationId);
        $org = Organization::query()->findOrFail($organizationId);
        $originalProfile = $org->deployment_profile;
        $org->forceFill(['deployment_profile' => 'hotel_bar'])->save();

        try {
            $task = BackgroundTask::createPending('product_import', $organizationId, (int) $admin->id, [
                'rows' => [
                    [
                        'product_code' => 'MENU-IMPORT-001',
                        'product_name' => 'Imported Club Soda',
                        'category_name' => $category->category_name,
                        'subcategory_name' => $subcategory->subcategory_name,
                        'measure_name' => $uom->full_name,
                        'unit_price' => 150,
                        'outlet_stock' => 12,
                        'storeroom_stock' => 24,
                    ],
                ],
            ]);

            $this->app->call([new ImportProductsJob($task->id), 'handle']);

            $task->refresh();
            $this->assertSame('completed', $task->status);
            $this->assertSame(1, $task->result['created'] ?? null);

            $product = Product::query()
                ->where('organization_id', $organizationId)
                ->where('product_code', 'MENU-IMPORT-001')
                ->firstOrFail();

            $this->assertFalse((bool) $product->sell_on_retail);
            $this->assertTrue((bool) $product->sell_on_bar);
            $this->assertTrue((bool) $product->sell_on_hotel);
        } finally {
            $org->forceFill(['deployment_profile' => $originalProfile])->save();
        }
    }

    public function test_product_reimport_skips_existing_code_and_name_within_org(): void
    {
        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $organizationId = (int) $admin->organization_id;
        [$category, $subcategory, $uom] = $this->catalogFixtures($organizationId);

        $first = BackgroundTask::createPending('product_import', $organizationId, (int) $admin->id, [
            'rows' => [
                [
                    'product_code' => 'DEDUP-CODE-1',
                    'product_name' => 'Dedup Sugar Pack',
                    'category_name' => $category->category_name,
                    'subcategory_name' => $subcategory->subcategory_name,
                    'measure_name' => $uom->full_name,
                    'unit_price' => 50,
                ],
            ],
        ]);
        $this->app->call([new ImportProductsJob($first->id), 'handle']);
        $first->refresh();
        $this->assertSame(1, $first->result['created'] ?? null);

        $second = BackgroundTask::createPending('product_import', $organizationId, (int) $admin->id, [
            'rows' => [
                [
                    'product_code' => 'DEDUP-CODE-1',
                    'product_name' => 'Dedup Sugar Pack renamed',
                    'category_name' => $category->category_name,
                    'subcategory_name' => $subcategory->subcategory_name,
                    'measure_name' => $uom->full_name,
                    'unit_price' => 55,
                ],
                [
                    'product_name' => 'Dedup Sugar Pack',
                    'category_name' => $category->category_name,
                    'subcategory_name' => $subcategory->subcategory_name,
                    'measure_name' => $uom->full_name,
                    'unit_price' => 60,
                ],
            ],
        ]);
        $this->app->call([new ImportProductsJob($second->id), 'handle']);
        $second->refresh();

        $this->assertSame('completed', $second->status);
        $this->assertSame(0, $second->result['created'] ?? null);
        $this->assertSame(2, $second->result['skipped'] ?? null);
        $this->assertSame(
            1,
            Product::query()
                ->where('organization_id', $organizationId)
                ->where('product_name', 'Dedup Sugar Pack')
                ->count(),
        );
    }

    public function test_product_import_assigns_branch_scope_when_requested(): void
    {
        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $organizationId = (int) $admin->organization_id;
        [$category, $subcategory, $uom] = $this->catalogFixtures($organizationId);

        $branchTwo = Branch::query()->create([
            'organization_id' => $organizationId,
            'branch_code' => 'IMP-BR2',
            'branch_name' => 'Import Branch Two',
            'branch_type' => 'retail',
            'is_active' => true,
        ]);

        $task = BackgroundTask::createPending('product_import', $organizationId, (int) $admin->id, [
            'rows' => [
                [
                    'product_code' => 'BRANCH-ONLY-1',
                    'product_name' => 'Branch Only Import Item',
                    'category_name' => $category->category_name,
                    'subcategory_name' => $subcategory->subcategory_name,
                    'measure_name' => $uom->full_name,
                    'unit_price' => 80,
                    'catalog_scope' => 'branch',
                    'branch_id' => $branchTwo->id,
                ],
            ],
        ]);

        $this->app->call([new ImportProductsJob($task->id), 'handle']);
        $task->refresh();

        $this->assertSame('completed', $task->status);
        $this->assertSame(1, $task->result['created'] ?? null);

        $product = Product::query()
            ->where('organization_id', $organizationId)
            ->where('product_code', 'BRANCH-ONLY-1')
            ->firstOrFail();

        $this->assertSame((int) $branchTwo->id, (int) $product->branch_id);
    }

    /** @return array{0: Category, 1: SubCategory, 2: Uom} */
    protected function catalogFixtures(int $organizationId): array
    {
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

        return [$category, $subcategory, $uom];
    }
}
