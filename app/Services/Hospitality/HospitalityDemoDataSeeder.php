<?php

namespace App\Services\Hospitality;

use App\Models\Category;
use App\Models\HospitalityFloorTable;
use App\Models\HospitalityOutlet;
use App\Models\Organization;
use App\Models\Product;
use App\Models\SubCategory;
use App\Models\Uom;
use App\Models\User;
use App\Models\Vat;
use App\Services\Cache\OrganizationCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Platform-admin demo seed for Hotel POS testing (menu, tables, outlets).
 */
class HospitalityDemoDataSeeder
{
    public const SEED_PREFIX = 'HTL';

    /**
     * @return array{
     *   outlet_id: int,
     *   categories: int,
     *   products: int,
     *   tables: int,
     *   product_codes: list<string>
     * }
     */
    public function seedForOrganization(Organization $org, ?User $actor = null): array
    {
        if (($org->deployment_profile ?? '') !== 'hotel_bar') {
            throw ValidationException::withMessages([
                'organization' => ['Demo Hotel POS data can only be seeded for Hotel & Bar organizations.'],
            ]);
        }

        return DB::transaction(function () use ($org, $actor) {
            $actorId = $actor?->id;
            $vat = $this->ensureVat($org, $actorId);
            $uom = $this->ensureUom($org, $actorId);
            $outlet = $this->ensureMainOutlet($org);
            $categories = $this->ensureMenuCategories($org, $actorId);
            $products = $this->ensureMenuProducts($org, $categories, $uom, $vat, $actorId);
            $tables = $this->ensureFloorTables($org, $outlet);

            OrganizationCache::invalidateCapabilities((int) $org->id);

            return [
                'outlet_id' => (int) $outlet->id,
                'categories' => count($categories),
                'products' => count($products),
                'tables' => count($tables),
                'product_codes' => array_values($products),
            ];
        });
    }

    protected function ensureVat(Organization $org, ?int $actorId): Vat
    {
        $vat = Vat::query()
            ->where('organization_id', $org->id)
            ->where('vat_code', 'V')
            ->first();
        if ($vat) {
            return $vat;
        }

        return Vat::query()->create([
            'organization_id' => $org->id,
            'vat_code' => 'V',
            'vat_name' => 'Standard Rated',
            'vat_percentage' => 16,
            'created_by' => $actorId,
        ]);
    }

    protected function ensureUom(Organization $org, ?int $actorId): Uom
    {
        $uom = Uom::query()
            ->where('organization_id', $org->id)
            ->where('uom_type', 'pcs')
            ->first();
        if ($uom) {
            return $uom;
        }

        return Uom::query()->create([
            'organization_id' => $org->id,
            'conversion_factor' => 1,
            'full_name' => 'Piece',
            'uom_type' => 'pcs',
            'small_packaging_label' => 'pcs',
            'is_base_unit' => true,
            'is_active' => true,
            'created_by' => $actorId,
        ]);
    }

    protected function ensureMainOutlet(Organization $org): HospitalityOutlet
    {
        $outlet = HospitalityOutlet::query()
            ->where('organization_id', $org->id)
            ->where('code', 'MAIN')
            ->first();
        if ($outlet) {
            return $outlet;
        }

        return HospitalityOutlet::query()->create([
            'organization_id' => $org->id,
            'code' => 'MAIN',
            'name' => 'Main outlet',
            'outlet_type' => 'restaurant',
            'is_active' => true,
        ]);
    }

    /**
     * @return array{food: SubCategory, drinks: SubCategory}
     */
    protected function ensureMenuCategories(Organization $org, ?int $actorId): array
    {
        $foodCat = Category::query()->firstOrCreate(
            ['organization_id' => $org->id, 'category_name' => 'Food'],
            ['created_by' => $actorId],
        );
        $drinksCat = Category::query()->firstOrCreate(
            ['organization_id' => $org->id, 'category_name' => 'Drinks'],
            ['created_by' => $actorId],
        );

        $foodSub = SubCategory::query()->firstOrCreate(
            [
                'organization_id' => $org->id,
                'category_id' => $foodCat->id,
                'subcategory_name' => 'Kitchen',
            ],
            ['created_by' => $actorId],
        );
        $drinksSub = SubCategory::query()->firstOrCreate(
            [
                'organization_id' => $org->id,
                'category_id' => $drinksCat->id,
                'subcategory_name' => 'Bar',
            ],
            ['created_by' => $actorId],
        );

        return ['food' => $foodSub, 'drinks' => $drinksSub];
    }

    /**
     * @param  array{food: SubCategory, drinks: SubCategory}  $categories
     * @return list<string>
     */
    protected function ensureMenuProducts(
        Organization $org,
        array $categories,
        Uom $uom,
        Vat $vat,
        ?int $actorId,
    ): array {
        $items = [
            // Food (10)
            ['code' => self::SEED_PREFIX.'-F01', 'name' => 'Ugali plate', 'price' => 250, 'group' => 'food'],
            ['code' => self::SEED_PREFIX.'-F02', 'name' => 'Chicken stew', 'price' => 650, 'group' => 'food'],
            ['code' => self::SEED_PREFIX.'-F03', 'name' => 'Beef stew', 'price' => 700, 'group' => 'food'],
            ['code' => self::SEED_PREFIX.'-F04', 'name' => 'Fish fillet', 'price' => 850, 'group' => 'food'],
            ['code' => self::SEED_PREFIX.'-F05', 'name' => 'Chips', 'price' => 300, 'group' => 'food'],
            ['code' => self::SEED_PREFIX.'-F06', 'name' => 'Chapati (2pcs)', 'price' => 100, 'group' => 'food'],
            ['code' => self::SEED_PREFIX.'-F07', 'name' => 'Vegetable salad', 'price' => 350, 'group' => 'food'],
            ['code' => self::SEED_PREFIX.'-F08', 'name' => 'Pilau', 'price' => 550, 'group' => 'food'],
            ['code' => self::SEED_PREFIX.'-F09', 'name' => 'Githeri special', 'price' => 400, 'group' => 'food'],
            ['code' => self::SEED_PREFIX.'-F10', 'name' => 'Nyama choma platter', 'price' => 1200, 'group' => 'food'],
            // Drinks (10)
            ['code' => self::SEED_PREFIX.'-D01', 'name' => 'Tusker lager 500ml', 'price' => 350, 'group' => 'drinks'],
            ['code' => self::SEED_PREFIX.'-D02', 'name' => 'White Cap 500ml', 'price' => 350, 'group' => 'drinks'],
            ['code' => self::SEED_PREFIX.'-D03', 'name' => 'Soft drink 300ml', 'price' => 150, 'group' => 'drinks'],
            ['code' => self::SEED_PREFIX.'-D04', 'name' => 'Mineral water 500ml', 'price' => 100, 'group' => 'drinks'],
            ['code' => self::SEED_PREFIX.'-D05', 'name' => 'Fresh juice', 'price' => 250, 'group' => 'drinks'],
            ['code' => self::SEED_PREFIX.'-D06', 'name' => 'House coffee', 'price' => 200, 'group' => 'drinks'],
            ['code' => self::SEED_PREFIX.'-D07', 'name' => 'Tea', 'price' => 120, 'group' => 'drinks'],
            ['code' => self::SEED_PREFIX.'-D08', 'name' => 'Red wine glass', 'price' => 600, 'group' => 'drinks'],
            ['code' => self::SEED_PREFIX.'-D09', 'name' => 'Whisky single', 'price' => 450, 'group' => 'drinks'],
            ['code' => self::SEED_PREFIX.'-D10', 'name' => 'Cocktail of the day', 'price' => 750, 'group' => 'drinks'],
        ];

        $codes = [];
        $hasBar = Schema::hasColumn('products', 'sell_on_bar');
        $hasHotel = Schema::hasColumn('products', 'sell_on_hotel');

        foreach ($items as $item) {
            $sub = $item['group'] === 'drinks' ? $categories['drinks'] : $categories['food'];
            $attrs = [
                'product_name' => $item['name'],
                'subcategory_id' => $sub->id,
                'unit_id' => $uom->id,
                'unit_price' => $item['price'],
                'last_selling_price' => $item['price'],
                'last_cost_price' => round($item['price'] * 0.45, 2),
                'vat_id' => $vat->id,
                'sell_on_retail' => false,
                'stock_in_shop' => 100,
                'stock_in_store' => 200,
                'reorder_point' => 10,
                'updated_by' => $actorId,
            ];
            if ($hasBar) {
                $attrs['sell_on_bar'] = true;
            }
            if ($hasHotel) {
                $attrs['sell_on_hotel'] = true;
            }

            $product = Product::query()->updateOrCreate(
                [
                    'organization_id' => $org->id,
                    'product_code' => $item['code'],
                ],
                array_merge($attrs, [
                    'created_by' => $actorId,
                ]),
            );
            $codes[] = (string) $product->product_code;
        }

        return $codes;
    }

    /**
     * @return list<string>
     */
    protected function ensureFloorTables(Organization $org, HospitalityOutlet $outlet): array
    {
        $defs = [
            ['code' => 'T1', 'label' => 'Table 1', 'zone' => 'Main', 'seats' => 4],
            ['code' => 'T2', 'label' => 'Table 2', 'zone' => 'Main', 'seats' => 4],
            ['code' => 'T3', 'label' => 'Table 3', 'zone' => 'Main', 'seats' => 6],
            ['code' => 'T4', 'label' => 'Table 4', 'zone' => 'Terrace', 'seats' => 2],
            ['code' => 'T5', 'label' => 'Table 5', 'zone' => 'Terrace', 'seats' => 4],
            ['code' => 'T6', 'label' => 'Table 6', 'zone' => 'VIP', 'seats' => 8],
            ['code' => 'B1', 'label' => 'Bar stool 1', 'zone' => 'Bar', 'seats' => 1],
            ['code' => 'B2', 'label' => 'Bar stool 2', 'zone' => 'Bar', 'seats' => 1],
        ];

        $codes = [];
        foreach ($defs as $def) {
            $table = HospitalityFloorTable::query()->updateOrCreate(
                [
                    'organization_id' => $org->id,
                    'outlet_id' => $outlet->id,
                    'code' => $def['code'],
                ],
                [
                    'label' => $def['label'],
                    'zone' => $def['zone'],
                    'seats' => $def['seats'],
                    'is_active' => true,
                ],
            );
            $codes[] = (string) $table->code;
        }

        return $codes;
    }
}
