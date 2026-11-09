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
use Illuminate\Support\Facades\Storage;

class CommerceDemoDataSeeder
{
    private const PRODUCT_PREFIX = 'DEMO-COM';

    /**
     * Small shop / duka — everyday pack sizes for till testing.
     *
     * @var list<array{name: string, category: string, price: float, cost: float, shop: float, store: float, photo: string}>
     */
    private const SMALL_SHOP_PRODUCTS = [
        ['name' => 'Premium rice 2kg', 'category' => 'Pantry', 'price' => 420, 'cost' => 350, 'shop' => 36, 'store' => 84, 'photo' => 'rice'],
        ['name' => 'Maize flour 2kg', 'category' => 'Pantry', 'price' => 180, 'cost' => 145, 'shop' => 48, 'store' => 96, 'photo' => 'flour'],
        ['name' => 'White sugar 2kg', 'category' => 'Pantry', 'price' => 390, 'cost' => 330, 'shop' => 30, 'store' => 60, 'photo' => 'sugar'],
        ['name' => 'Cooking oil 1 litre', 'category' => 'Pantry', 'price' => 340, 'cost' => 280, 'shop' => 24, 'store' => 48, 'photo' => 'oil'],
        ['name' => 'Bottled water 1 litre', 'category' => 'Beverages', 'price' => 100, 'cost' => 65, 'shop' => 40, 'store' => 80, 'photo' => 'water'],
        ['name' => 'Tea leaves 500g', 'category' => 'Beverages', 'price' => 280, 'cost' => 220, 'shop' => 20, 'store' => 40, 'photo' => 'tea'],
        ['name' => 'White bread loaf', 'category' => 'Bakery', 'price' => 80, 'cost' => 60, 'shop' => 18, 'store' => 0, 'photo' => 'bread'],
        ['name' => 'Instant coffee 100g', 'category' => 'Beverages', 'price' => 450, 'cost' => 360, 'shop' => 12, 'store' => 24, 'photo' => 'coffee'],
    ];

    /**
     * Supermarket shelf mix — produce, dairy, household, snacks.
     *
     * @var list<array{name: string, category: string, price: float, cost: float, shop: float, store: float, photo: string}>
     */
    private const SUPERMARKET_PRODUCTS = [
        ['name' => 'Fresh bananas (bunch)', 'category' => 'Fresh produce', 'price' => 150, 'cost' => 90, 'shop' => 40, 'store' => 20, 'photo' => 'banana'],
        ['name' => 'Ripe tomatoes 1kg', 'category' => 'Fresh produce', 'price' => 180, 'cost' => 110, 'shop' => 35, 'store' => 25, 'photo' => 'tomato'],
        ['name' => 'Fresh milk 500ml', 'category' => 'Dairy', 'price' => 70, 'cost' => 52, 'shop' => 48, 'store' => 24, 'photo' => 'milk'],
        ['name' => 'Eggs tray (30)', 'category' => 'Dairy', 'price' => 520, 'cost' => 420, 'shop' => 16, 'store' => 24, 'photo' => 'eggs'],
        ['name' => 'Laundry bar soap 1kg', 'category' => 'Household', 'price' => 220, 'cost' => 170, 'shop' => 28, 'store' => 56, 'photo' => 'soap'],
        ['name' => 'Toothpaste 100ml', 'category' => 'Household', 'price' => 180, 'cost' => 130, 'shop' => 24, 'store' => 48, 'photo' => 'toothpaste'],
        ['name' => 'Assorted biscuits 200g', 'category' => 'Snacks', 'price' => 160, 'cost' => 110, 'shop' => 30, 'store' => 60, 'photo' => 'biscuit'],
        ['name' => 'Coca-Cola 500ml', 'category' => 'Beverages', 'price' => 90, 'cost' => 60, 'shop' => 60, 'store' => 120, 'photo' => 'coca-cola'],
    ];

    /**
     * @var list<array{name: string, category: string, price: float, cost: float, shop: float, store: float, photo: string}>
     */
    private const WHOLESALE_PRODUCTS = [
        ['name' => 'Premium rice 25kg sack', 'category' => 'Bulk grains', 'price' => 4200, 'cost' => 3500, 'shop' => 12, 'store' => 48, 'photo' => 'rice'],
        ['name' => 'Maize flour 50kg bag', 'category' => 'Bulk grains', 'price' => 4200, 'cost' => 3600, 'shop' => 8, 'store' => 32, 'photo' => 'flour'],
        ['name' => 'White sugar 50kg bag', 'category' => 'Bulk grains', 'price' => 6500, 'cost' => 5600, 'shop' => 10, 'store' => 40, 'photo' => 'sugar'],
        ['name' => 'Cooking oil 20 litre jerrycan', 'category' => 'Bulk groceries', 'price' => 5600, 'cost' => 4800, 'shop' => 8, 'store' => 32, 'photo' => 'oil'],
        ['name' => 'Bottled water crate', 'category' => 'Bulk beverages', 'price' => 600, 'cost' => 420, 'shop' => 20, 'store' => 80, 'photo' => 'water'],
        ['name' => 'Tea leaves 5kg carton', 'category' => 'Bulk beverages', 'price' => 2600, 'cost' => 2100, 'shop' => 8, 'store' => 32, 'photo' => 'tea'],
        ['name' => 'White bread 12-loaf tray', 'category' => 'Bulk bakery', 'price' => 900, 'cost' => 720, 'shop' => 12, 'store' => 24, 'photo' => 'bread'],
        ['name' => 'Instant coffee 1kg carton', 'category' => 'Bulk beverages', 'price' => 3600, 'cost' => 2900, 'shop' => 6, 'store' => 24, 'photo' => 'coffee'],
    ];

    /**
     * @var list<array{name: string, category: string, price: float, cost: float, shop: float, store: float, photo: string}>
     */
    private const DISTRIBUTION_PRODUCTS = [
        ['name' => 'Rice 2kg retail pack (carton)', 'category' => 'Grains', 'price' => 3600, 'cost' => 3000, 'shop' => 4, 'store' => 60, 'photo' => 'rice'],
        ['name' => 'Maize flour 2kg retail pack (carton)', 'category' => 'Grains', 'price' => 1700, 'cost' => 1350, 'shop' => 4, 'store' => 80, 'photo' => 'flour'],
        ['name' => 'Sugar 2kg retail pack (carton)', 'category' => 'Grains', 'price' => 3700, 'cost' => 3100, 'shop' => 4, 'store' => 50, 'photo' => 'sugar'],
        ['name' => 'Cooking oil 1 litre (carton)', 'category' => 'Household goods', 'price' => 3200, 'cost' => 2600, 'shop' => 4, 'store' => 48, 'photo' => 'oil'],
        ['name' => 'Bottled water 1 litre (crate)', 'category' => 'Beverages', 'price' => 900, 'cost' => 600, 'shop' => 8, 'store' => 100, 'photo' => 'water'],
        ['name' => 'Tea leaves 500g (carton)', 'category' => 'Beverages', 'price' => 2600, 'cost' => 2000, 'shop' => 4, 'store' => 36, 'photo' => 'tea'],
        ['name' => 'White bread loaf (tray)', 'category' => 'Bakery', 'price' => 840, 'cost' => 600, 'shop' => 6, 'store' => 30, 'photo' => 'bread'],
        ['name' => 'Instant coffee 100g (carton)', 'category' => 'Beverages', 'price' => 4200, 'cost' => 3300, 'shop' => 4, 'store' => 28, 'photo' => 'coffee'],
    ];

    /**
     * @return array{products: int, images: int, product_codes: list<string>, catalogue: string}
     */
    public function seedForOrganization(Organization $org, Branch $branch, ?User $actor = null): array
    {
        return DB::transaction(function () use ($org, $branch, $actor) {
            $this->removeForOrganization($org);

            $actorId = $actor?->id;
            $profile = (string) $org->deployment_profile;
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

            $categoryName = $this->rootCategoryName($profile);
            $category = Category::query()->firstOrCreate(
                ['organization_id' => $org->id, 'category_name' => $categoryName],
                ['created_by' => $actorId],
            );
            $subcategories = [];
            $products = $this->productsForProfile($profile);
            foreach (array_unique(array_column($products, 'category')) as $name) {
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
            foreach ($products as $index => $item) {
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
                    force: true,
                    photoKey: $item['photo'],
                    preferRemoteUrl: false,
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
                'catalogue' => $this->catalogueLabel($profile),
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

            foreach ($products as $product) {
                if (
                    is_string($product->image_path)
                    && $product->image_path !== ''
                    && ! filter_var($product->image_path, FILTER_VALIDATE_URL)
                ) {
                    Storage::disk('public')->delete($product->image_path);
                }
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

    /**
     * @return list<array{name: string, category: string, price: float, cost: float, shop: float, store: float, photo: string}>
     */
    private function productsForProfile(string $profile): array
    {
        return match ($profile) {
            'wholesale_retail' => self::WHOLESALE_PRODUCTS,
            'distribution' => self::DISTRIBUTION_PRODUCTS,
            'supermarket' => self::SUPERMARKET_PRODUCTS,
            default => self::SMALL_SHOP_PRODUCTS,
        };
    }

    private function rootCategoryName(string $profile): string
    {
        return match ($profile) {
            'wholesale_retail' => 'Wholesale samples',
            'distribution' => 'Distribution samples',
            'supermarket' => 'Supermarket samples',
            'custom' => 'Starter samples',
            default => 'Shop samples',
        };
    }

    private function catalogueLabel(string $profile): string
    {
        return match ($profile) {
            'wholesale_retail' => 'wholesale bulk packs',
            'distribution' => 'distributor cartons & crates',
            'supermarket' => 'supermarket shelf mix',
            'custom' => 'starter shop samples',
            default => 'small-shop essentials',
        };
    }
}
