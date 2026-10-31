<?php

namespace App\Services\Platform;

use App\Models\Branch;
use App\Models\Category;
use App\Models\CurrentStock;
use App\Models\Organization;
use App\Models\Product;
use App\Models\SubCategory;
use App\Models\Uom;
use App\Models\User;
use App\Models\Vat;
use App\Support\SeededProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CommerceDemoDataSeeder
{
    private const PRODUCT_PREFIX = 'DEMO-COM';

    /**
     * @var list<array{name: string, category: string, price: float, cost: float, shop: float, store: float, photo: string}>
     */
    private const PRODUCTS = [
        ['name' => 'Premium rice 2kg', 'category' => 'Pantry', 'price' => 420, 'cost' => 350, 'shop' => 36, 'store' => 84, 'photo' => 'retail'],
        ['name' => 'Maize flour 2kg', 'category' => 'Pantry', 'price' => 180, 'cost' => 145, 'shop' => 48, 'store' => 96, 'photo' => 'retail'],
        ['name' => 'White sugar 2kg', 'category' => 'Pantry', 'price' => 390, 'cost' => 330, 'shop' => 30, 'store' => 60, 'photo' => 'retail'],
        ['name' => 'Cooking oil 1 litre', 'category' => 'Pantry', 'price' => 340, 'cost' => 280, 'shop' => 24, 'store' => 48, 'photo' => 'juice'],
        ['name' => 'Bottled water 1 litre', 'category' => 'Beverages', 'price' => 100, 'cost' => 65, 'shop' => 40, 'store' => 80, 'photo' => 'water'],
        ['name' => 'Tea leaves 500g', 'category' => 'Beverages', 'price' => 280, 'cost' => 220, 'shop' => 20, 'store' => 40, 'photo' => 'tea'],
        ['name' => 'White bread loaf', 'category' => 'Bakery', 'price' => 80, 'cost' => 60, 'shop' => 18, 'store' => 0, 'photo' => 'chapati'],
        ['name' => 'Instant coffee 100g', 'category' => 'Beverages', 'price' => 450, 'cost' => 360, 'shop' => 12, 'store' => 24, 'photo' => 'coffee'],
    ];

    /**
     * @return array{products: int, images: int, product_codes: list<string>}
     */
    public function seedForOrganization(Organization $org, Branch $branch, ?User $actor = null): array
    {
        return DB::transaction(function () use ($org, $branch, $actor) {
            $this->removeForOrganization($org);

            $actorId = $actor?->id;
            $vat = Vat::query()->firstOrCreate(
                ['organization_id' => $org->id, 'vat_code' => 'V'],
                ['vat_name' => 'Standard Rated', 'vat_percentage' => 16, 'is_active' => true, 'created_by' => $actorId],
            );
            $unit = Uom::query()
                ->where('organization_id', $org->id)
                ->whereIn('uom_type', ['piece', 'pcs'])
                ->orderByRaw("CASE WHEN uom_type = 'piece' THEN 0 ELSE 1 END")
                ->first();

            if (! $unit) {
                $unit = Uom::query()->create([
                    'organization_id' => $org->id,
                    'conversion_factor' => 1,
                    'full_name' => 'Piece',
                    'uom_type' => 'piece',
                    'is_base_unit' => true,
                    'is_active' => true,
                    'created_by' => $actorId,
                ]);
            }

            $category = Category::query()->firstOrCreate(
                ['organization_id' => $org->id, 'category_name' => 'Demo Essentials'],
                ['created_by' => $actorId],
            );
            $subcategories = [];
            foreach (array_unique(array_column(self::PRODUCTS, 'category')) as $name) {
                $subcategories[$name] = SubCategory::query()->firstOrCreate(
                    [
                        'organization_id' => $org->id,
                        'category_id' => $category->id,
                        'subcategory_name' => $name,
                    ],
                    ['created_by' => $actorId],
                );
            }

            $codes = [];
            $imageCount = 0;
            foreach (self::PRODUCTS as $index => $item) {
                $code = self::PRODUCT_PREFIX.'-'.$org->id.'-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
                $attributes = [
                    'product_name' => $item['name'],
                    'subcategory_id' => $subcategories[$item['category']]->id,
                    'unit_id' => $unit->id,
                    'unit_price' => $item['price'],
                    'last_selling_price' => $item['price'],
                    'last_cost_price' => $item['cost'],
                    'stock_in_shop' => $item['shop'],
                    'stock_in_store' => $item['store'],
                    'sell_on_retail' => true,
                    'vat_id' => $vat->id,
                    'organization_id' => $org->id,
                    'branch_id' => null,
                    'reorder_point' => 8,
                    'low_stock_alert_enabled' => true,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                    'deleted_at' => null,
                ];
                if (Schema::hasColumn('products', 'sell_on_bar')) {
                    $attributes['sell_on_bar'] = false;
                }
                if (Schema::hasColumn('products', 'sell_on_hotel')) {
                    $attributes['sell_on_hotel'] = false;
                }

                $product = Product::withTrashed()->updateOrCreate([
                    'organization_id' => $org->id,
                    'product_code' => $code,
                ], $attributes);

                $imagePath = SeededProductImage::ensureForProduct(
                    $product,
                    $org,
                    $item['name'],
                    'retail',
                    photoKey: $item['photo'],
                );
                if ($imagePath !== null) {
                    $imageCount++;
                }

                CurrentStock::query()->updateOrCreate(
                    ['product_code' => $code, 'branch_id' => $branch->id],
                    ['shop_quantity' => $item['shop'], 'store_quantity' => $item['store']],
                );
                $codes[] = $code;
            }

            return [
                'products' => count($codes),
                'images' => $imageCount,
                'product_codes' => $codes,
            ];
        });
    }

    /**
     * @return array{products: int, stock_rows: int}
     */
    public function removeForOrganization(Organization $org): array
    {
        return DB::transaction(function () use ($org) {
            $prefix = self::PRODUCT_PREFIX.'-'.$org->id.'-';
            $products = Product::withTrashed()
                ->where('organization_id', $org->id)
                ->where('product_code', 'like', $prefix.'%')
                ->get();
            $codes = $products->pluck('product_code')->map(fn ($code) => (string) $code)->all();

            if ($codes === []) {
                return ['products' => 0, 'stock_rows' => 0];
            }

            $branchIds = Branch::query()->where('organization_id', $org->id)->pluck('id');
            $stockRows = CurrentStock::query()
                ->whereIn('product_code', $codes)
                ->whereIn('branch_id', $branchIds)
                ->delete();

            if (Schema::hasTable('retail_package_settings')) {
                DB::table('retail_package_settings')
                    ->whereIn('product_code', $codes)
                    ->delete();
            }
            if (Schema::hasTable('price_history')) {
                DB::table('price_history')
                    ->where('organization_id', $org->id)
                    ->whereIn('product_code', $codes)
                    ->delete();
            }

            $activeCount = $products->filter(fn (Product $product) => $product->deleted_at === null)->count();
            Product::query()
                ->where('organization_id', $org->id)
                ->whereIn('product_code', $codes)
                ->delete();

            return ['products' => $activeCount, 'stock_rows' => $stockRows];
        });
    }
}
