<?php

namespace App\Services\Platform;

use App\Models\Branch;
use App\Models\Category;
use App\Models\CurrentStock;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Expense;
use App\Models\ExpenseGroup;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\RouteModel;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SubCategory;
use App\Models\Supplier;
use App\Models\Till;
use App\Models\TillFloatSession;
use App\Models\Uom;
use App\Models\User;
use App\Models\Vat;
use App\Models\Vehicle;
use App\Services\Organization\OrganizationReferenceDataService;
use App\Support\SeededProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Seeds a commerce organisation with catalogue, stock, suppliers, customers,
 * till session, and sample sales so Platform → Generate demo data can fully
 * demonstrate Backoffice / POS / reports without touching live tenant records.
 *
 * All demo rows are tagged (product / supplier / till / vehicle / driver codes,
 * reserved customer_nums, fulfillment_meta.platform_demo) so clear is safe.
 */
class CommerceDemoDataSeeder
{
    private const PRODUCT_PREFIX = 'DEMO-COM';

    private const SUPPLIER_PREFIX = 'DEMO-SUP';

    private const TILL_PREFIX = 'DEMO-TILL';

    private const VEHICLE_PREFIX = 'DEMO-VEH';

    private const DRIVER_PREFIX = 'DEMO-DRV';

    private const ROUTE_NAME = 'Demo city route';

    /** Reserved customer_num values (unique within each organisation). */
    private const DEMO_CUSTOMER_NUMS = [91001, 91002, 91003];

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
     * @return array{
     *   products: int,
     *   images: int,
     *   product_codes: list<string>,
     *   catalogue: string,
     *   suppliers: int,
     *   customers: int,
     *   sales: int,
     *   expenses: int,
     *   distribution: bool
     * }
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

            $suppliers = $this->seedSuppliers($org, $actorId);
            $primarySupplierId = $suppliers[0]->id ?? null;

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
            $seededProducts = [];
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
                    'supplier_id' => $primarySupplierId,
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
                $seededProducts[] = $product->fresh();
            }

            $distribution = $this->seedDistributionExtras($org, $branch, $profile, $actorId);
            $customers = $this->seedCustomers($org, $branch, $actorId, $distribution['route'] ?? null);
            $ops = $this->seedSalesAndTill(
                $org,
                $branch,
                $actor,
                $seededProducts,
                $customers,
                $distribution,
                (float) ($vat->vat_percentage ?? 16),
            );
            $expenseCount = $this->seedExpense($org, $branch, $actor, $ops['float_session'] ?? null);

            return [
                'products' => count($codes),
                'images' => $imageCount,
                'product_codes' => $codes,
                'catalogue' => $this->catalogueLabel($profile),
                'suppliers' => count($suppliers),
                'customers' => count($customers),
                'sales' => $ops['sales'],
                'expenses' => $expenseCount,
                'distribution' => $distribution['enabled'],
            ];
        });
    }

    /**
     * @return array{products: int, stock_rows: int, suppliers: int, customers: int, sales: int, expenses: int}
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

            $salesRemoved = $this->removeDemoSales($org, $codes);
            $expensesRemoved = $this->removeDemoExpenses($org);
            $customersRemoved = $this->removeDemoCustomers($org);
            $distributionRemoved = $this->removeDemoDistribution($org);
            $this->removeDemoTills($org);

            $stockRows = 0;
            $activeCount = 0;
            if ($codes !== []) {
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
                // Clear supplier FK before deleting demo suppliers.
                Product::withTrashed()
                    ->where('organization_id', $org->id)
                    ->whereIn('product_code', $codes)
                    ->update(['supplier_id' => null]);
                Product::query()
                    ->where('organization_id', $org->id)
                    ->whereIn('product_code', $codes)
                    ->delete();
            }

            $suppliersRemoved = $this->removeDemoSuppliers($org);

            return [
                'products' => $activeCount,
                'stock_rows' => $stockRows,
                'suppliers' => $suppliersRemoved,
                'customers' => $customersRemoved,
                'sales' => $salesRemoved,
                'expenses' => $expensesRemoved,
                'distribution' => $distributionRemoved,
            ];
        });
    }

    /**
     * @return list<Supplier>
     */
    private function seedSuppliers(Organization $org, ?int $actorId): array
    {
        $defs = [
            [
                'suffix' => '01',
                'name' => 'Demo Grains & Commodities Ltd',
                'contact' => 'Grace Wanjiku',
                'phone' => '0711001001',
                'email' => 'orders@demograins.example',
                'town' => 'Nairobi',
            ],
            [
                'suffix' => '02',
                'name' => 'Demo Fresh Distributors',
                'contact' => 'Peter Otieno',
                'phone' => '0711001002',
                'email' => 'sales@demofresh.example',
                'town' => 'Thika',
            ],
        ];

        $created = [];
        foreach ($defs as $def) {
            $created[] = Supplier::query()->updateOrCreate(
                [
                    'organization_id' => $org->id,
                    'supplier_code' => self::SUPPLIER_PREFIX.'-'.$org->id.'-'.$def['suffix'],
                ],
                [
                    'supplier_name' => $def['name'],
                    'contact_person' => $def['contact'],
                    'phone' => $def['phone'],
                    'email' => $def['email'],
                    'town' => $def['town'],
                    'address' => 'Industrial Area, '.$def['town'],
                    'terms_of_payment' => 'Net 30',
                    'is_active' => true,
                    'contacts' => [[
                        'label' => 'Sales',
                        'phone' => $def['phone'],
                        'email' => $def['email'],
                    ]],
                    'created_by' => $actorId,
                    'deleted_at' => null,
                    'deleted_by' => null,
                ],
            );
        }

        return $created;
    }

    /**
     * @return array{enabled: bool, route: ?RouteModel, driver: ?Driver, vehicle: ?Vehicle}
     */
    private function seedDistributionExtras(Organization $org, Branch $branch, string $profile, ?int $actorId): array
    {
        $wantsDistribution = in_array($profile, ['distribution', 'wholesale_retail'], true);
        if (! $wantsDistribution) {
            return ['enabled' => false, 'route' => null, 'driver' => null, 'vehicle' => null];
        }

        $route = RouteModel::query()->updateOrCreate(
            [
                'organization_id' => $org->id,
                'route_name' => self::ROUTE_NAME,
            ],
            [
                'branch_id' => $branch->id,
                'route_markup_price' => 5,
                'direction' => 'City',
                'is_active' => true,
            ],
        );

        $vehicle = Vehicle::query()->updateOrCreate(
            [
                'organization_id' => $org->id,
                'vehicle_code' => self::VEHICLE_PREFIX.'-'.$org->id,
            ],
            [
                'branch_id' => $branch->id,
                'vehicle_name' => 'Demo delivery van',
                'plate_number' => 'KDA '.$org->id.'DM',
                'is_active' => true,
            ],
        );

        $driver = Driver::query()->updateOrCreate(
            [
                'organization_id' => $org->id,
                'driver_code' => self::DRIVER_PREFIX.'-'.$org->id,
            ],
            [
                'branch_id' => $branch->id,
                'default_vehicle_id' => $vehicle->id,
                'default_route_id' => $route->id,
                'full_name' => 'Demo Driver Kamau',
                'phone' => '0711001099',
                'is_active' => true,
            ],
        );

        return [
            'enabled' => true,
            'route' => $route,
            'driver' => $driver,
            'vehicle' => $vehicle,
        ];
    }

    /**
     * @return list<Customer>
     */
    private function seedCustomers(Organization $org, Branch $branch, ?int $actorId, ?RouteModel $route): array
    {
        $defs = [
            [
                'num' => self::DEMO_CUSTOMER_NUMS[0],
                'name' => 'Demo Walk-in Club',
                'type' => 'regular',
                'phone' => '0722002001',
                'town' => 'Nairobi',
                'credit' => 0,
                'route' => false,
            ],
            [
                'num' => self::DEMO_CUSTOMER_NUMS[1],
                'name' => 'Demo Corner Shop',
                'type' => 'debtor',
                'phone' => '0722002002',
                'town' => 'Westlands',
                'credit' => 25000,
                'route' => false,
            ],
            [
                'num' => self::DEMO_CUSTOMER_NUMS[2],
                'name' => 'Demo Route Mini Mart',
                'type' => $route ? 'route' : 'regular',
                'phone' => '0722002003',
                'town' => 'Eastlands',
                'credit' => 75000,
                'route' => (bool) $route,
            ],
        ];

        $created = [];
        foreach ($defs as $def) {
            $created[] = Customer::withTrashed()->updateOrCreate(
                [
                    'organization_id' => $org->id,
                    'customer_num' => $def['num'],
                ],
                [
                    'branch_id' => $branch->id,
                    'customer_name' => $def['name'],
                    'customer_type' => $def['type'],
                    'phone_number' => $def['phone'],
                    'email' => 'customer'.$def['num'].'@demo.example',
                    'town' => $def['town'],
                    'route_id' => $def['route'] ? $route?->id : null,
                    'credit_limit' => $def['credit'],
                    'current_balance' => 0,
                    'customer_status' => 1,
                    'terms_of_payment' => $def['credit'] > 0 ? 'Net 14' : 'Cash',
                    'created_by' => $actorId,
                    'deleted_at' => null,
                    'deleted_by' => null,
                ],
            );
        }

        return $created;
    }

    /**
     * @param  list<Product>  $products
     * @param  list<Customer>  $customers
     * @param  array{enabled: bool, route: ?RouteModel, driver: ?Driver, vehicle: ?Vehicle}  $distribution
     * @return array{sales: int, float_session: ?TillFloatSession}
     */
    private function seedSalesAndTill(
        Organization $org,
        Branch $branch,
        ?User $actor,
        array $products,
        array $customers,
        array $distribution,
        float $vatPercent,
    ): array {
        if ($products === [] || $actor === null) {
            return ['sales' => 0, 'float_session' => null];
        }

        app(OrganizationReferenceDataService::class)->ensurePaymentMethods($org->id);

        $cashMethod = PaymentMethod::query()
            ->where('organization_id', $org->id)
            ->where('method_code', 'CASH')
            ->first();
        $mpesaMethod = PaymentMethod::query()
            ->where('organization_id', $org->id)
            ->where('method_code', 'MPESA')
            ->first();

        $till = Till::query()->updateOrCreate(
            [
                'organization_id' => $org->id,
                'till_number' => self::TILL_PREFIX.'-'.$org->id,
            ],
            [
                'branch_id' => $branch->id,
                'till_name' => 'Demo till 1',
                'description' => 'Platform demo till',
                'is_active' => true,
                'cashier_id' => $actor->id,
            ],
        );

        $session = TillFloatSession::query()->create([
            'organization_id' => $org->id,
            'till_id' => $till->id,
            'branch_id' => $branch->id,
            'cashier_id' => $actor->id,
            'session_date' => now()->toDateString(),
            'working_amount' => 5000,
            'float_breakdown' => ['CASH' => 5000],
            'status' => 'open',
            'opened_at' => now()->subHours(3),
            'notes' => 'Platform demo float session',
        ]);

        $nextOrder = (int) Sale::query()
            ->where('organization_id', $org->id)
            ->max('order_num') + 1;

        $p0 = $products[0];
        $p1 = $products[1] ?? $products[0];
        $p2 = $products[2] ?? $products[0];
        $retailCustomer = $customers[1] ?? $customers[0] ?? null;
        $routeCustomer = $customers[2] ?? $retailCustomer;

        $sales = 0;

        // 1) Completed POS cash sale (walk-in).
        $posLines = [
            ['product' => $p0, 'qty' => 2],
            ['product' => $p1, 'qty' => 1],
        ];
        $this->createSaleWithLines(
            $org,
            $branch,
            $actor,
            $nextOrder++,
            [
                'channel' => 'pos',
                'order_source' => 'pos',
                'till_id' => $till->id,
                'float_session_id' => $session->id,
                'customer_name_override' => 'Walk-in Customer',
                'status' => 'completed',
                'payment_method_code' => 'CASH',
                'payment_status' => 'paid',
                'completed_at' => now()->subHours(2),
            ],
            $posLines,
            $vatPercent,
            $cashMethod?->id,
            $session->id,
        );
        $sales++;

        // 2) Completed M-Pesa sale for a registered retail customer.
        if ($retailCustomer) {
            $mpesaLines = [
                ['product' => $p1, 'qty' => 3],
                ['product' => $p2, 'qty' => 2],
            ];
            $this->createSaleWithLines(
                $org,
                $branch,
                $actor,
                $nextOrder++,
                [
                    'channel' => 'backend',
                    'order_source' => 'backoffice',
                    'customer_num' => (int) $retailCustomer->customer_num,
                    'status' => 'completed',
                    'payment_method_code' => 'MPESA',
                    'payment_status' => 'paid',
                    'completed_at' => now()->subHour(),
                ],
                $mpesaLines,
                $vatPercent,
                $mpesaMethod?->id ?? $cashMethod?->id,
                null,
            );
            $sales++;
        }

        // 3) Unpaid / credit balance sale for reports + debtors demos.
        if ($retailCustomer) {
            $creditLines = [
                ['product' => $p0, 'qty' => 1],
                ['product' => $p2, 'qty' => 1],
            ];
            $this->createSaleWithLines(
                $org,
                $branch,
                $actor,
                $nextOrder++,
                [
                    'channel' => 'backend',
                    'order_source' => 'backoffice',
                    'customer_num' => (int) $retailCustomer->customer_num,
                    'status' => 'completed',
                    'payment_method_code' => 'CASH',
                    'payment_status' => 'unpaid',
                    'is_credit_sale' => true,
                    'completed_at' => now()->subMinutes(30),
                    'amount_paid_override' => 0,
                ],
                $creditLines,
                $vatPercent,
                null,
                null,
            );
            $sales++;
        }

        // 4) Distribution / wholesale: completed mobile delivery + open processed order.
        if ($distribution['enabled'] && $routeCustomer && $distribution['route']) {
            $deliveryLines = [
                ['product' => $p0, 'qty' => 2],
                ['product' => $p1, 'qty' => 1],
            ];
            $meta = [
                'platform_demo' => true,
                'driver_id' => $distribution['driver']?->id,
                'vehicle_id' => $distribution['vehicle']?->id,
            ];
            $this->createSaleWithLines(
                $org,
                $branch,
                $actor,
                $nextOrder++,
                [
                    'channel' => 'mobile',
                    'order_source' => 'mobile',
                    'customer_num' => (int) $routeCustomer->customer_num,
                    'route_id' => $distribution['route']->id,
                    'status' => 'completed',
                    'payment_method_code' => 'CASH',
                    'payment_status' => 'paid',
                    'fulfillment_meta' => $meta,
                    'completed_at' => now()->subMinutes(20),
                ],
                $deliveryLines,
                $vatPercent,
                $cashMethod?->id,
                null,
            );
            $sales++;

            $pendingLines = [
                ['product' => $p2, 'qty' => 2],
            ];
            $this->createSaleWithLines(
                $org,
                $branch,
                $actor,
                $nextOrder++,
                [
                    'channel' => 'mobile',
                    'order_source' => 'mobile',
                    'customer_num' => (int) $routeCustomer->customer_num,
                    'route_id' => $distribution['route']->id,
                    'status' => 'processed',
                    'payment_method_code' => 'CASH',
                    'payment_status' => 'unpaid',
                    'fulfillment_meta' => $meta,
                    'amount_paid_override' => 0,
                ],
                $pendingLines,
                $vatPercent,
                null,
                null,
            );
            $sales++;
        }

        return ['sales' => $sales, 'float_session' => $session];
    }

    /**
     * @param  list<array{product: Product, qty: float|int}>  $lines
     * @param  array<string, mixed>  $header
     */
    private function createSaleWithLines(
        Organization $org,
        Branch $branch,
        User $actor,
        int $orderNum,
        array $header,
        array $lines,
        float $vatPercent,
        ?int $paymentMethodId,
        ?int $floatSessionId,
    ): Sale {
        $lineRows = [];
        $gross = 0.0;
        $vatTotal = 0.0;
        foreach ($lines as $index => $line) {
            /** @var Product $product */
            $product = $line['product'];
            $qty = (float) $line['qty'];
            $price = round((float) $product->unit_price, 2);
            $amount = round($price * $qty, 2);
            $vat = $vatPercent > 0
                ? round($amount * ($vatPercent / (100 + $vatPercent)), 2)
                : 0.0;
            $gross += $amount;
            $vatTotal += $vat;
            $lineRows[] = [
                'product_code' => (string) $product->product_code,
                'product_name' => (string) $product->product_name,
                'line_no' => $index + 1,
                'item_code' => (string) ($index + 1),
                'quantity' => $qty,
                'uom' => 'piece',
                'selling_price' => $price,
                'product_vat' => $vat,
                'amount' => $amount,
                'on_wholesale_retail' => 1,
            ];
        }

        $paidOverride = array_key_exists('amount_paid_override', $header)
            ? (float) $header['amount_paid_override']
            : null;
        $amountPaid = $paidOverride !== null
            ? $paidOverride
            : (($header['payment_status'] ?? 'paid') === 'paid' ? $gross : 0.0);

        $meta = $header['fulfillment_meta'] ?? [];
        if (! is_array($meta)) {
            $meta = [];
        }
        $meta['platform_demo'] = true;

        $saleAttrs = [
            'order_num' => $orderNum,
            'branch_id' => $branch->id,
            'organization_id' => $org->id,
            'channel' => $header['channel'],
            'order_source' => $header['order_source'] ?? $header['channel'],
            'till_id' => $header['till_id'] ?? null,
            'float_session_id' => $header['float_session_id'] ?? null,
            'cashier_id' => $actor->id,
            'customer_num' => $header['customer_num'] ?? null,
            'customer_name_override' => $header['customer_name_override'] ?? null,
            'route_id' => $header['route_id'] ?? null,
            'status' => $header['status'],
            'total_vat' => round($vatTotal, 2),
            'order_total' => round($gross, 2),
            'cash' => ($header['payment_method_code'] ?? null) === 'CASH' ? $amountPaid : 0,
            'mpesa_amount' => ($header['payment_method_code'] ?? null) === 'MPESA' ? $amountPaid : 0,
            'payment_method_code' => $header['payment_method_code'] ?? 'CASH',
            'payment_status' => $header['payment_status'] ?? 'paid',
            'amount_paid' => round($amountPaid, 2),
            'is_credit_sale' => (bool) ($header['is_credit_sale'] ?? false),
            'fulfillment_meta' => $meta,
            'completed_at' => $header['completed_at'] ?? null,
            'archived' => 0,
        ];

        $sale = Sale::query()->create($saleAttrs);

        foreach ($lineRows as $row) {
            SaleItem::query()->create([
                'sale_id' => $sale->id,
                ...$row,
            ]);
        }

        if ($paymentMethodId && $amountPaid > 0) {
            SalePayment::query()->create([
                'sale_id' => $sale->id,
                'float_session_id' => $floatSessionId,
                'payment_method_id' => $paymentMethodId,
                'amount' => round($amountPaid, 2),
                'reference_number' => $header['payment_method_code'] === 'MPESA'
                    ? 'DEMO'.str_pad((string) $sale->id, 8, '0', STR_PAD_LEFT)
                    : null,
                'paid_at' => $header['completed_at'] ?? now(),
            ]);
        }

        return $sale;
    }

    private function seedExpense(Organization $org, Branch $branch, ?User $actor, ?TillFloatSession $session): int
    {
        if ($actor === null || ! Schema::hasTable('expenses')) {
            return 0;
        }

        $group = ExpenseGroup::query()
            ->where('organization_id', $org->id)
            ->orderBy('id')
            ->first();
        if (! $group) {
            return 0;
        }

        $cashMethod = PaymentMethod::query()
            ->where('organization_id', $org->id)
            ->where('method_code', 'CASH')
            ->first();

        Expense::withTrashed()->updateOrCreate(
            [
                'organization_id' => $org->id,
                'invoice_no' => 'DEMO-EXP-'.$org->id,
            ],
            [
                'branch_id' => $branch->id,
                'expense_group_id' => $group->id,
                'float_session_id' => $session?->id,
                'description' => 'Demo till stationery / packaging',
                'expense_amount' => 450,
                'expense_date' => now()->toDateString(),
                'balance_due' => 0,
                'billable_status' => 0,
                'payment_method_id' => $cashMethod?->id,
                'recorded_by' => $actor->id,
                'deleted_at' => null,
                'deleted_by' => null,
            ],
        );

        return 1;
    }

    private function removeDemoSales(Organization $org, array $productCodes): int
    {
        $saleIds = Sale::query()
            ->where('organization_id', $org->id)
            ->where(function ($q) use ($productCodes) {
                $q->where('fulfillment_meta->platform_demo', true);
                if ($productCodes !== []) {
                    $q->orWhereIn('id', function ($sub) use ($productCodes) {
                        $sub->select('sale_id')
                            ->from('sale_items')
                            ->whereIn('product_code', $productCodes);
                    });
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($saleIds === []) {
            return 0;
        }

        if (Schema::hasTable('customer_invoice_payments') && Schema::hasTable('customer_invoices')) {
            $invoiceIds = DB::table('customer_invoices')
                ->whereIn('sale_id', $saleIds)
                ->pluck('id');
            if ($invoiceIds->isNotEmpty()) {
                DB::table('customer_invoice_payments')->whereIn('customer_invoice_id', $invoiceIds)->delete();
                DB::table('customer_invoices')->whereIn('id', $invoiceIds)->delete();
            }
        } elseif (Schema::hasTable('customer_invoices')) {
            DB::table('customer_invoices')->whereIn('sale_id', $saleIds)->delete();
        }

        if (Schema::hasTable('sale_payments')) {
            SalePayment::query()->whereIn('sale_id', $saleIds)->delete();
        }
        SaleItem::query()->whereIn('sale_id', $saleIds)->delete();
        Sale::query()->whereIn('id', $saleIds)->delete();

        return count($saleIds);
    }

    private function removeDemoExpenses(Organization $org): int
    {
        if (! Schema::hasTable('expenses')) {
            return 0;
        }

        return Expense::withTrashed()
            ->where('organization_id', $org->id)
            ->where('invoice_no', 'DEMO-EXP-'.$org->id)
            ->forceDelete();
    }

    private function removeDemoCustomers(Organization $org): int
    {
        return Customer::withTrashed()
            ->where('organization_id', $org->id)
            ->whereIn('customer_num', self::DEMO_CUSTOMER_NUMS)
            ->forceDelete();
    }

    private function removeDemoSuppliers(Organization $org): int
    {
        return Supplier::query()
            ->where('organization_id', $org->id)
            ->where('supplier_code', 'like', self::SUPPLIER_PREFIX.'-'.$org->id.'-%')
            ->delete();
    }

    private function removeDemoTills(Organization $org): void
    {
        $tills = Till::query()
            ->where('organization_id', $org->id)
            ->where('till_number', self::TILL_PREFIX.'-'.$org->id)
            ->get();
        $tillIds = $tills->pluck('id');
        if ($tillIds->isNotEmpty()) {
            TillFloatSession::query()->whereIn('till_id', $tillIds)->delete();
            Till::query()->whereIn('id', $tillIds)->delete();
        }
    }

    /**
     * @return array{routes: int, drivers: int, vehicles: int}
     */
    private function removeDemoDistribution(Organization $org): array
    {
        $drivers = Driver::query()
            ->where('organization_id', $org->id)
            ->where('driver_code', self::DRIVER_PREFIX.'-'.$org->id)
            ->delete();
        $vehicles = Vehicle::query()
            ->where('organization_id', $org->id)
            ->where('vehicle_code', self::VEHICLE_PREFIX.'-'.$org->id)
            ->delete();
        $routes = RouteModel::query()
            ->where('organization_id', $org->id)
            ->where('route_name', self::ROUTE_NAME)
            ->delete();

        return [
            'routes' => $routes,
            'drivers' => $drivers,
            'vehicles' => $vehicles,
        ];
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
