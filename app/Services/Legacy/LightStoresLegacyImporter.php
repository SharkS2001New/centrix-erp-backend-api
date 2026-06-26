<?php

namespace App\Services\Legacy;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\RetailPackageSetting;
use App\Models\Role;
use App\Models\RouteModel;
use App\Models\Sale;
use App\Models\SubCategory;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\User;
use App\Models\Vat;
use App\Services\Erp\PermissionMatrixService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class LightStoresLegacyImporter
{
    /** Phases that copy master data into Centrix; legacy MySQL stays a read-only sales archive. */
    public const MASTER_DATA_PHASES = ['foundation', 'catalog', 'customers'];

    /** Centrix tables populated by --master-data (plus categories, sub_categories, routes, users). */
    public const MASTER_DATA_ENTITIES = [
        'vats',
        'uoms',
        'suppliers',
        'products',
        'retail_package_settings',
        'customers',
    ];

    protected string $legacy = 'legacy';

    protected bool $dryRun = false;

    /** @var list<string> */
    protected array $only = [];

    protected ?Organization $organization = null;

    protected ?Branch $branch = null;

    protected ?User $migrationUser = null;

    protected ?Role $adminRole = null;

    /** @var array<string, int> */
    protected array $stats = [];

    /** @var array<int, int> */
    protected array $cashierMap = [];

    /** Centrix order_num bases for legacy import only — live sales stay below 1_000_000. */
    protected const LEGACY_ORDER_BASE_POS = 1_000_000;

    protected const LEGACY_ORDER_BASE_MOBILE = 2_000_000;

    protected const LEGACY_ORDER_BASE_DEBTOR = 3_000_000;

    protected int $legacyPosSequence = 0;

    protected int $legacyMobileSequence = 0;

    protected int $legacyDebtorSequence = 0;

    protected ?int $targetOrganizationId = null;

    public function legacyDatabaseName(): string
    {
        return (string) config('database.connections.'.$this->legacy.'.database', '');
    }

    /**
     * @param  list<string>|null  $only
     * @return array<string, int>
     */
    public function run(bool $dryRun = false, ?array $only = null, bool $force = false, ?int $organizationId = null): array
    {
        $this->dryRun = $dryRun;
        $this->only = $only ?? [];
        $this->stats = [];
        $this->targetOrganizationId = null;

        if ($organizationId) {
            $org = Organization::query()->findOrFail($organizationId);
            $this->targetOrganizationId = $organizationId;
            $this->useLegacyConnectionForOrganization($org);
        }

        $this->assertLegacyDatabase();

        if ($this->shouldRun('foundation')) {
            $this->importFoundation($force);
        }
        if ($this->shouldRun('catalog')) {
            $this->importCatalog();
        }
        if ($this->shouldRun('customers')) {
            $this->importCustomers();
        }
        if ($this->shouldRun('sales')) {
            $this->assertSalesImportAllowed();
            $this->importSales();
        }

        return $this->stats;
    }

    protected function assertSalesImportAllowed(): void
    {
        if ($this->dryRun) {
            return;
        }

        $org = $this->organization;
        if (! $org && $this->targetOrganizationId) {
            $org = Organization::query()->find($this->targetOrganizationId);
        }

        if (! $org) {
            return;
        }

        if (app(OrganizationLegacyArchiveService::class)->isConfigured($org)) {
            throw new RuntimeException(
                'Bulk sales import into Centrix is disabled while legacy archive is enabled for this organization. '
                .'Run with --master-data to import products, customers, VAT, UOMs, suppliers, and retail packages into Centrix. '
                .'Historical sales remain in the legacy database and can be browsed or materialized on demand.',
            );
        }
    }

    public function isMasterDataRun(): bool
    {
        if ($this->only === []) {
            return false;
        }

        return ! in_array('sales', $this->only, true)
            && array_values(array_intersect($this->only, self::MASTER_DATA_PHASES)) === $this->only;
    }

    /**
     * Import one legacy sale into Centrix so it can be returned / credited like any other sale.
     * Safe to call repeatedly — returns the existing Centrix sale when already materialized.
     */
    public function materializeSale(Organization $org, string $channel, int $legacyOrderNum, string $saleDate): Sale
    {
        if (! in_array($channel, ['pos', 'mobile', 'debtor'], true)) {
            throw new RuntimeException('Channel must be pos, mobile, or debtor.');
        }

        $saleDate = date('Y-m-d', strtotime($saleDate));

        $this->useLegacyConnectionForOrganization($org);
        $this->assertLegacyDatabase();
        $this->bootContext($org);

        $existing = $this->findMaterializedSale($channel, $legacyOrderNum, $saleDate);
        if ($existing) {
            return $existing->load(['items.product.unit', 'customer']);
        }

        $this->bootLegacySequencesFromDatabase();

        $row = $this->fetchLegacySaleRow($channel, $legacyOrderNum, $saleDate);
        if (! $row) {
            throw new RuntimeException("Legacy {$channel} sale #{$legacyOrderNum} on {$saleDate} was not found.");
        }

        $walkInName = $channel === 'pos'
            ? $this->fetchPosWalkInName($legacyOrderNum, $saleDate)
            : null;

        $sale = DB::transaction(function () use ($channel, $row, $legacyOrderNum, $saleDate, $walkInName) {
            return match ($channel) {
                'mobile' => $this->createMobileRouteSale($row, $legacyOrderNum, $saleDate),
                'debtor' => $this->createDebtorSale($row, $legacyOrderNum, $saleDate),
                'pos' => $this->createPosSale($row, $legacyOrderNum, $saleDate, $walkInName),
            };
        });

        if (! $sale) {
            throw new RuntimeException("Legacy {$channel} sale #{$legacyOrderNum} could not be materialized (missing customer, products, or lines).");
        }

        return $sale->load(['items.product.unit', 'customer']);
    }

    protected function useLegacyConnectionForOrganization(Organization $org): void
    {
        $this->legacy = app(LegacyArchiveConnectionManager::class)->configureForOrganization($org);
    }

    public function findMaterializedSale(string $channel, int $legacyOrderNum, ?string $saleDate = null): ?Sale
    {
        $query = Sale::query()
            ->where('organization_id', $this->organization?->id ?? Organization::query()->value('id'))
            ->where('fulfillment_meta->legacy_import', true)
            ->where('fulfillment_meta->legacy_order_num', $legacyOrderNum)
            ->whereIn('fulfillment_meta->legacy_source', LightStoresLegacySchema::legacySourcesForChannel($channel));

        if ($saleDate !== null) {
            $query->where('fulfillment_meta->legacy_sale_date', date('Y-m-d', strtotime($saleDate)));
        }

        return $query->first();
    }

    protected function legacySourceForChannel(string $channel): string
    {
        return LightStoresLegacySchema::legacySourceForChannel($channel);
    }

    protected function bootLegacySequencesFromDatabase(): void
    {
        $this->legacyPosSequence = max(0, (int) (Sale::query()
            ->whereBetween('order_num', [self::LEGACY_ORDER_BASE_POS + 1, self::LEGACY_ORDER_BASE_MOBILE - 1])
            ->max('order_num') ?? self::LEGACY_ORDER_BASE_POS) - self::LEGACY_ORDER_BASE_POS);

        $this->legacyMobileSequence = max(0, (int) (Sale::query()
            ->whereBetween('order_num', [self::LEGACY_ORDER_BASE_MOBILE + 1, self::LEGACY_ORDER_BASE_DEBTOR - 1])
            ->max('order_num') ?? self::LEGACY_ORDER_BASE_MOBILE) - self::LEGACY_ORDER_BASE_MOBILE);

        $this->legacyDebtorSequence = max(0, (int) (Sale::query()
            ->where('order_num', '>', self::LEGACY_ORDER_BASE_DEBTOR)
            ->max('order_num') ?? self::LEGACY_ORDER_BASE_DEBTOR) - self::LEGACY_ORDER_BASE_DEBTOR);
    }

    protected function fetchLegacySaleRow(string $channel, int $legacyOrderNum, string $saleDate): ?object
    {
        $saleDate = date('Y-m-d', strtotime($saleDate));

        return match ($channel) {
            'mobile' => DB::connection($this->legacy)
                ->table(LightStoresLegacySchema::ROUTE_MASTERS)
                ->whereNull('DLT_ON')
                ->where('order_num', $legacyOrderNum)
                ->where('create_time', $saleDate)
                ->first(),
            'debtor' => DB::connection($this->legacy)
                ->table(LightStoresLegacySchema::DEBTOR_MASTERS)
                ->whereNull('dlt_on')
                ->where('order_num', $legacyOrderNum)
                ->whereDate('create_time', $saleDate)
                ->first(),
            'pos' => DB::connection($this->legacy)
                ->table(LightStoresLegacySchema::POS_MASTERS)
                ->where('order_num', $legacyOrderNum)
                ->where('create_time', $saleDate)
                ->first(),
            default => null,
        };
    }

    protected function fetchPosWalkInName(int $legacyOrderNum, string $saleDate): ?string
    {
        return DB::connection($this->legacy)
            ->table(LightStoresLegacySchema::POS_WALK_IN.' as sc')
            ->join(LightStoresLegacySchema::POS_MASTERS.' as sm', 'sm.order_num', '=', 'sc.order_no')
            ->where('sc.order_no', $legacyOrderNum)
            ->where('sm.create_time', date('Y-m-d', strtotime($saleDate)))
            ->orderByDesc('sc.create_time')
            ->value('sc.customer_name');
    }

    protected function shouldRun(string $phase): bool
    {
        return $this->only === [] || in_array($phase, $this->only, true);
    }

    protected function assertLegacyDatabase(): void
    {
        try {
            DB::connection($this->legacy)->select('SELECT 1');
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Legacy database is not reachable. Restore LightStoresDBBackup.sql first, e.g. '
                .'mysql -u USER -p -e "CREATE DATABASE lightstores_legacy CHARACTER SET utf8mb4;" '
                .'&& mysql -u USER -p lightstores_legacy < /path/to/LightStoresDBBackup.sql. '
                .$e->getMessage(),
            );
        }

        if (! DB::connection($this->legacy)->getSchemaBuilder()->hasTable('org_info')) {
            throw new RuntimeException('Legacy database is missing table org_info — is LightStoresDBBackup.sql loaded?');
        }

        $inspect = app(LightStoresArchiveDatabaseService::class)->inspect($this->legacy);
        if ($inspect['missing'] !== []) {
            throw new RuntimeException(
                'Legacy database is missing required sales tables: '.implode(', ', $inspect['missing'])
                .'. Run: php artisan legacy:restore-archive --database='
                .config('database.connections.'.$this->legacy.'.database', 'lightstores_moonlight'),
            );
        }
    }

    protected function importFoundation(bool $force): void
    {
        $legacyOrg = DB::connection($this->legacy)->table('org_info')->first();
        if (! $legacyOrg) {
            throw new RuntimeException('Legacy org_info row not found.');
        }

        if ($this->targetOrganizationId) {
            $existing = Organization::query()->findOrFail($this->targetOrganizationId);
            if (! $existing->matchesLegacyCompanyCode((string) $legacyOrg->company_code)) {
                throw new RuntimeException(
                    "Organization #{$existing->id} [{$existing->company_code}] does not match legacy org_info [{$legacyOrg->company_code}]. "
                    .'Set legacy_archive.legacy_company_code on the organization, add the legacy code as a company_code alias, or rename the Centrix company code to match.',
                );
            }
        } else {
            $existing = Organization::query()->where('company_code', $legacyOrg->company_code)->first();
        }

        if ($existing && ! $this->targetOrganizationId && ! $force) {
            throw new RuntimeException(
                "Organization [{$legacyOrg->company_code}] already exists. Pass --force to import into a fresh database or reuse it.",
            );
        }

        if ($this->dryRun) {
            $this->bump('organizations', 1);
            $this->bump('branches', 1);
            $this->bump('users', DB::connection($this->legacy)->table('user')->whereNull('dlt_on')->count() + 1);
            $this->bump('vats', DB::connection($this->legacy)->table('vat_status')->count());

            return;
        }

        DB::transaction(function () use ($legacyOrg, $existing) {
            $this->organization = $existing ?? Organization::query()->create([
                'company_code' => $legacyOrg->company_code,
                'logo' => $legacyOrg->logo,
                'org_name' => $legacyOrg->org_name,
                'org_email' => $legacyOrg->org_email,
                'primary_tel' => $legacyOrg->primary_tel,
                'secondary_tel' => $legacyOrg->secondary_tel,
                'addn_tel1' => $legacyOrg->addn_tel1,
                'addn_tel2' => $legacyOrg->addn_tel2,
                'org_address' => $legacyOrg->org_address,
                'org_pin' => $legacyOrg->org_pin,
                'vat_regno' => $legacyOrg->vat_regno,
                'deployment_profile' => 'wholesale_retail',
                'enabled_modules' => config('erp.profiles.wholesale_retail.modules'),
                'module_settings' => config('erp.module_settings_defaults'),
                'is_active' => true,
            ]);

            $this->branch = Branch::query()->firstOrCreate(
                [
                    'organization_id' => $this->organization->id,
                    'branch_code' => 'HQ',
                ],
                [
                    'branch_name' => 'Head Office',
                    'branch_address' => $legacyOrg->org_address,
                    'branch_phone' => $legacyOrg->primary_tel,
                    'branch_email' => $legacyOrg->org_email,
                    'branch_type' => 'wholesale',
                    'is_active' => true,
                ],
            );

            $this->ensurePermissions();
            $this->adminRole = Role::query()->firstOrCreate(
                ['role_name' => 'Administrator'],
                ['scope' => 'org', 'is_active' => true],
            );
            $this->attachAllPermissions($this->adminRole);

            $this->migrationUser = User::query()->firstOrCreate(
                [
                    'organization_id' => $this->organization->id,
                    'username' => 'legacy.import',
                ],
                [
                    'branch_id' => $this->branch->id,
                    'role_id' => $this->adminRole->id,
                    'email' => $legacyOrg->org_email,
                    'password' => Hash::make(Str::password(24)),
                    'full_name' => 'Legacy Import System',
                    'is_admin' => true,
                    'is_active' => true,
                    'login_channels' => ['backoffice'],
                ],
            );

            $this->importLegacyUsers();
            $this->importVats();
        });

        $this->bump('organizations', 1);
        $this->bump('branches', 1);
        $this->bump('users', 1);
    }

    protected function ensurePermissions(): void
    {
        if (DB::table('permissions')->count() > 0) {
            return;
        }

        PermissionMatrixService::ensure();
    }

    protected function attachAllPermissions(Role $role): void
    {
        $permissionIds = DB::table('permissions')->pluck('id');
        $rows = $permissionIds->map(fn ($id) => [
            'role_id' => $role->id,
            'permission_id' => $id,
        ])->all();

        DB::table('role_permissions')->upsert($rows, ['role_id', 'permission_id']);
    }

    protected function importLegacyUsers(): void
    {
        $legacyUsers = DB::connection($this->legacy)
            ->table('user')
            ->whereNull('dlt_on')
            ->get();

        foreach ($legacyUsers as $legacyUser) {
            $user = User::query()->firstOrCreate(
                [
                    'organization_id' => $this->organization->id,
                    'username' => strtoupper((string) $legacyUser->username),
                ],
                [
                    'branch_id' => $this->branch->id,
                    'role_id' => $this->adminRole->id,
                    'email' => $legacyUser->email,
                    'password' => Hash::make(Str::password(24)),
                    'full_name' => strtoupper((string) $legacyUser->username),
                    'is_admin' => (bool) $legacyUser->is_admin,
                    'access_scope' => (bool) $legacyUser->is_admin ? 'org' : 'branch',
                    'is_active' => true,
                    'login_channels' => (bool) $legacyUser->user_is_mobile ? ['backoffice', 'mobile'] : ['backoffice'],
                    'is_mobile_user' => (bool) $legacyUser->user_is_mobile,
                ],
            );

            $this->cashierMap[(int) $legacyUser->id] = $user->id;
        }

        $this->cashierMap[0] = $this->migrationUser->id;
    }

    protected function importVats(): void
    {
        $legacyRows = DB::connection($this->legacy)->table('vat_status')->get();

        foreach ($legacyRows as $row) {
            $this->upsertById(Vat::class, (int) $row->id, [
                'vat_code' => (string) ($row->vat_code ?: 'VAT'.$row->id),
                'vat_name' => (string) ($row->vstatus ?: $row->vat_code ?: 'VAT '.$row->vat_percentage.'%'),
                'vat_percentage' => (float) $row->vat_percentage,
                'is_active' => true,
                'created_by' => $this->migrationUser->id,
            ]);
            $this->bump('vats');
        }

        if (Vat::query()->count() === 0) {
            Vat::query()->create([
                'vat_code' => 'VAT16',
                'vat_name' => 'VAT 16%',
                'vat_percentage' => 16,
                'is_active' => true,
                'created_by' => $this->migrationUser->id,
            ]);
            $this->bump('vats');
        }
    }

    protected function importCatalog(): void
    {
        if (! $this->dryRun) {
            $this->bootContext();
        }

        if ($this->dryRun) {
            $this->bump('categories', DB::connection($this->legacy)->table('category')->count());
            $this->bump('sub_categories', DB::connection($this->legacy)->table('sub_category')->count());
            $this->bump('uoms', DB::connection($this->legacy)->table('uom')->count());
            $this->bump('suppliers', DB::connection($this->legacy)->table('suppliers')->count());
            $this->bump('products', $this->legacyProductQuery()->count());
            $this->bump('retail_package_settings', DB::connection($this->legacy)->table('retail_package_setting')->count());

            return;
        }

        DB::transaction(function () {
            foreach (DB::connection($this->legacy)->table('category')->orderBy('id')->get() as $row) {
                $this->upsertById(Category::class, (int) $row->id, [
                    'category_name' => (string) $row->category_name,
                    'created_by' => $this->migrationUser->id,
                ]);
                $this->bump('categories');
            }

            foreach (DB::connection($this->legacy)->table('sub_category')->orderBy('id')->get() as $row) {
                $this->upsertById(SubCategory::class, (int) $row->id, [
                    'category_id' => (int) $row->category_id,
                    'subcategory_name' => (string) $row->subcategory_name,
                    'created_by' => $this->migrationUser->id,
                ]);
                $this->bump('sub_categories');
            }

            foreach (DB::connection($this->legacy)->table('uom')->orderBy('id')->get() as $row) {
                $factor = is_numeric($row->short_name) ? (float) $row->short_name : 1.0;
                $this->upsertById(Uom::class, (int) $row->id, [
                    'conversion_factor' => $factor > 0 ? $factor : 1,
                    'full_name' => (string) $row->full_name,
                    'measure_name' => is_numeric($row->short_name) ? null : (string) $row->short_name,
                    'uom_type' => (string) ($row->uom_type ?: 'UNIT'),
                    'is_base_unit' => abs($factor - 1.0) < 0.0001,
                    'is_active' => $row->deleted_on === null,
                    'created_by' => $this->migrationUser->id,
                    'deleted_at' => $row->deleted_on,
                    'deleted_by' => $row->deleted_by ? $this->migrationUser->id : null,
                ]);
                $this->bump('uoms');
            }

            $this->importLegacySuppliers();

            $defaultVatId = (int) Vat::query()->orderBy('id')->value('id');

            foreach ($this->legacyProductQuery()->orderBy('id')->cursor() as $row) {
                $code = (string) $row->product_code;
                Product::query()->updateOrCreate(
                    ['product_code' => $code],
                    [
                        'product_name' => (string) $row->product_name,
                        'subcategory_id' => (int) $row->subcateg_id,
                        'unit_id' => (int) $row->unit_id,
                        'unit_price' => (float) $row->unit_price,
                        'last_selling_price' => (float) ($row->last_selling_price ?? 0),
                        'last_cost_price' => (float) ($row->last_cost_price ?? 0),
                        'discount_percentage' => (float) ($row->discount_percentage ?? 0),
                        'product_weight' => $row->product_weight,
                        'stock_in_shop' => 0,
                        'stock_in_store' => 0,
                        'supplier_id' => $this->resolveLegacySupplierId($row->supplier_id),
                        'sell_on_retail' => (bool) $row->sell_on_retail,
                        'vat_id' => $this->resolveLegacyVatId($row->vat_statusid, $defaultVatId),
                        'organization_id' => $this->organization->id,
                        'branch_id' => null,
                        'reorder_point' => 0,
                        'low_stock_alert_enabled' => true,
                        'created_by' => $this->migrationUser->id,
                        'deleted_at' => $row->dlt_on,
                        'deleted_by' => $row->dlt_by ? $this->migrationUser->id : null,
                    ],
                );
                $this->bump('products');
            }

            foreach (DB::connection($this->legacy)->table('retail_package_setting')->orderBy('id')->cursor() as $row) {
                $code = (string) $row->product_code;
                if (! Product::query()->where('product_code', $code)->exists()) {
                    continue;
                }

                RetailPackageSetting::query()->updateOrCreate(
                    ['product_code' => $code],
                    [
                        'max_qty_measure' => $row->max_qty_measure,
                        'markup_price' => (float) ($row->markup_price ?? 0),
                        'min_uom_measure' => $row->min_uom_measure,
                        'max_uom_measure' => $row->max_uom_measure,
                        'wholesale_qty_measure' => (float) ($row->wholesale_qty_measure ?? 0),
                        'wholesale_markup_price' => (float) ($row->wholesale_markup_price ?? 0),
                    ],
                );
                $this->bump('retail_package_settings');
            }
        });
    }

    /**
     * Active catalog rows for Centrix, plus any product codes referenced on legacy sale lines
     * (including deleted SKUs needed when materializing historical sales).
     */
    protected function legacyProductQuery()
    {
        $referencedCodes = collect()
            ->merge(DB::connection($this->legacy)->table(LightStoresLegacySchema::POS_LINES)->distinct()->pluck('productsales_id'))
            ->merge(DB::connection($this->legacy)->table('route_order_details')->distinct()->pluck('product_code'))
            ->merge(DB::connection($this->legacy)->table(LightStoresLegacySchema::DEBTOR_LINES)->distinct()->pluck('product_code'))
            ->unique()
            ->filter()
            ->values();

        return DB::connection($this->legacy)
            ->table('product')
            ->where(function ($query) use ($referencedCodes) {
                $query->whereNull('dlt_on');
                if ($referencedCodes->isNotEmpty()) {
                    $query->orWhereIn('product_code', $referencedCodes);
                }
            });
    }

    protected function importCustomers(): void
    {
        if (! $this->dryRun) {
            $this->bootContext();
        }

        if ($this->dryRun) {
            $this->bump('routes', DB::connection($this->legacy)->table('routes')->count());
            $this->bump('customers', DB::connection($this->legacy)->table('customer')->whereNull('dlt_on')->where('customer_num', '>', 0)->count());

            return;
        }

        DB::transaction(function () {
            foreach (DB::connection($this->legacy)->table('routes')->orderBy('id')->get() as $row) {
                $this->upsertById(RouteModel::class, (int) $row->id, [
                    'route_name' => (string) $row->route_name,
                    'route_markup_price' => (int) ($row->route_markup_price ?? 0),
                    'direction' => $row->direction,
                    'is_active' => true,
                ]);
                $this->bump('routes');
            }

            foreach (
                DB::connection($this->legacy)
                    ->table('customer')
                    ->whereNull('dlt_on')
                    ->where('customer_num', '>', 0)
                    ->orderBy('customer_num')
                    ->cursor() as $row
            ) {
                $routeId = $row->route_id ? (int) $row->route_id : null;
                if ($routeId && ! RouteModel::query()->where('id', $routeId)->exists()) {
                    $routeId = null;
                }

                Customer::query()->updateOrCreate(
                    ['customer_num' => (int) $row->customer_num],
                    [
                        'branch_id' => $this->branch->id,
                        'organization_id' => $this->organization->id,
                        'customer_name' => (string) $row->customer_name,
                        'customer_type' => $routeId ? 'route' : 'debtor',
                        'phone_number' => $row->phone_number,
                        'additional_phone' => $row->addnl_phone_number,
                        'town' => $row->town,
                        'route_id' => $routeId,
                        'created_by' => $this->migrationUser->id,
                        'customer_status' => (int) ($row->cust_status ?? 0),
                        'kra_pin' => $row->kra_pin,
                        'terms_of_payment' => $row->terms_of_payment,
                        'credit_limit' => 0,
                        'current_balance' => 0,
                    ],
                );
                $this->bump('customers');
            }
        });
    }

    protected function importSales(): void
    {
        if (! $this->dryRun) {
            $this->bootContext();
        }

        $queue = $this->buildLegacySalesQueue();

        if ($this->dryRun) {
            foreach ($queue as $item) {
                $this->bump(match ($item['type']) {
                    'mobile' => 'sales_mobile',
                    'debtor' => 'sales_debtor',
                    default => 'sales_pos',
                });
            }

            $this->bump('sale_items',
                DB::connection($this->legacy)->table(LightStoresLegacySchema::POS_LINES)->count()
                + DB::connection($this->legacy)->table(LightStoresLegacySchema::ROUTE_LINES)->count()
                + DB::connection($this->legacy)->table(LightStoresLegacySchema::DEBTOR_LINES)->count(),
            );

            return;
        }

        $walkIns = DB::connection($this->legacy)
            ->table(LightStoresLegacySchema::POS_WALK_IN)
            ->pluck('customer_name', 'order_no');

        foreach (array_chunk($queue, 250) as $chunk) {
            DB::transaction(function () use ($chunk, $walkIns) {
                foreach ($chunk as $item) {
                    $this->importLegacySaleItem($item, $walkIns);
                }
            });
        }

        $this->syncDebtorBalances();
    }

    /**
     * Import every legacy sale. Overlapping legacy order_num values are expected — each type
     * gets its own prefixed sequence (R/M/D) and a unique Centrix order_num in a reserved range.
     *
     * @return list<array{type: string, sale_date: string, legacy_order_num: int, row: object}>
     */
    protected function buildLegacySalesQueue(): array
    {
        $queue = [];

        foreach (DB::connection($this->legacy)->table(LightStoresLegacySchema::ROUTE_MASTERS)->whereNull('DLT_ON')->cursor() as $row) {
            $queue[] = [
                'type' => 'mobile',
                'sale_date' => $this->legacySaleDate($row->create_time, $row->delivery_date),
                'legacy_order_num' => (int) $row->order_num,
                'row' => $row,
            ];
        }

        foreach (DB::connection($this->legacy)->table(LightStoresLegacySchema::DEBTOR_MASTERS)->whereNull('dlt_on')->cursor() as $row) {
            $queue[] = [
                'type' => 'debtor',
                'sale_date' => $this->legacySaleDate($row->create_time),
                'legacy_order_num' => (int) $row->order_num,
                'row' => $row,
            ];
        }

        foreach (DB::connection($this->legacy)->table(LightStoresLegacySchema::POS_MASTERS)->cursor() as $row) {
            $queue[] = [
                'type' => 'pos',
                'sale_date' => $this->legacySaleDate($row->create_time),
                'legacy_order_num' => (int) $row->order_num,
                'row' => $row,
            ];
        }

        usort($queue, function (array $a, array $b) {
            $dateCompare = strcmp($a['sale_date'], $b['sale_date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            $typeCompare = strcmp($a['type'], $b['type']);
            if ($typeCompare !== 0) {
                return $typeCompare;
            }

            return $a['legacy_order_num'] <=> $b['legacy_order_num'];
        });

        return $queue;
    }

    /**
     * @param  array{type: string, sale_date: string, legacy_order_num: int, row: object}  $item
     */
    protected function importLegacySaleItem(array $item, Collection $walkIns): void
    {
        $saleDate = date('Y-m-d', strtotime($item['sale_date']));

        match ($item['type']) {
            'mobile' => $this->createMobileRouteSale($item['row'], $item['legacy_order_num'], $saleDate),
            'debtor' => $this->createDebtorSale($item['row'], $item['legacy_order_num'], $saleDate),
            'pos' => $this->createPosSale(
                $item['row'],
                $item['legacy_order_num'],
                $saleDate,
                $this->fetchPosWalkInName($item['legacy_order_num'], $saleDate),
            ),
            default => null,
        };
    }

    /**
     * @return array{order_num: int, label: string}
     */
    protected function allocateLegacyOrderNum(string $type): array
    {
        return match ($type) {
            'mobile' => [
                'order_num' => self::LEGACY_ORDER_BASE_MOBILE + (++$this->legacyMobileSequence),
                'label' => $this->legacyOrderLabel('M', $this->legacyMobileSequence),
            ],
            'debtor' => [
                'order_num' => self::LEGACY_ORDER_BASE_DEBTOR + (++$this->legacyDebtorSequence),
                'label' => $this->legacyOrderLabel('D', $this->legacyDebtorSequence),
            ],
            default => [
                'order_num' => self::LEGACY_ORDER_BASE_POS + (++$this->legacyPosSequence),
                'label' => $this->legacyOrderLabel('R', $this->legacyPosSequence),
            ],
        };
    }

    protected function legacyOrderLabel(string $prefix, int $sequence): string
    {
        return $prefix.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{
     *   legacy_import: true,
     *   legacy_order_label: string,
     *   legacy_order_num: int,
     *   legacy_sale_date: string,
     *   legacy_source: string,
     *   legacy_order_total?: float,
     *   legacy_total_vat?: float,
     * }
     */
    protected function legacyFulfillmentMeta(
        string $label,
        int $legacyOrderNum,
        string $legacySource,
        string $saleDate,
        ?float $legacyOrderTotal = null,
        ?float $legacyTotalVat = null,
    ): array {
        $meta = [
            'legacy_import' => true,
            'legacy_order_label' => $label,
            'legacy_order_num' => $legacyOrderNum,
            'legacy_sale_date' => date('Y-m-d', strtotime($saleDate)),
            'legacy_source' => $legacySource,
            'legacy_preserve_amounts' => true,
        ];

        if ($legacyOrderTotal !== null) {
            $meta['legacy_order_total'] = round($legacyOrderTotal, 2);
        }
        if ($legacyTotalVat !== null) {
            $meta['legacy_total_vat'] = round($legacyTotalVat, 2);
        }

        return $meta;
    }

    protected function legacySaleDate(mixed ...$candidates): string
    {
        foreach ($candidates as $value) {
            if ($value) {
                return date('Y-m-d H:i:s', strtotime((string) $value));
            }
        }

        return '1970-01-01 00:00:00';
    }

    protected function createMobileRouteSale(object $row, int $legacyOrderNum, string $saleDate): ?Sale
    {
        $saleDate = date('Y-m-d', strtotime($saleDate));
        $customerNum = (int) $row->customer_num;
        if (! Customer::query()->where('customer_num', $customerNum)->exists()) {
            return null;
        }

        $customer = Customer::query()->where('customer_num', $customerNum)->first();
        $lines = DB::connection($this->legacy)
            ->table(LightStoresLegacySchema::ROUTE_MASTERS.' as rm')
            ->join(LightStoresLegacySchema::ROUTE_LINES.' as rod', function ($join) {
                $join->on('rod.order_no', '=', 'rm.order_num')
                    ->on('rod.create_time', '=', 'rm.create_time');
            })
            ->where('rm.order_num', $legacyOrderNum)
            ->where('rm.create_time', $saleDate)
            ->whereNull('rm.DLT_ON')
            ->orderBy('rod.item_code')
            ->get(['rod.*']);

        if ($lines->isEmpty()) {
            return null;
        }

        $this->assertLegacyProductsExist($lines, 'product_code');
        [$orderTotal, $totalVat] = $this->sumLegacyLines($lines, 'amount', 'product_vat');
        $routeStatus = $this->mapLegacyRouteOrderStatus((int) $row->order_status);
        $amountPaid = $routeStatus['payment_status'] === 'paid' ? $orderTotal : 0.0;
        $orderRef = $this->allocateLegacyOrderNum('mobile');

        $sale = Sale::query()->create([
            'order_num' => $orderRef['order_num'],
            'branch_id' => $this->branch->id,
            'organization_id' => $this->organization->id,
            'channel' => 'mobile',
            'payment_status' => $routeStatus['payment_status'],
            'amount_paid' => $amountPaid,
            'cashier_id' => $this->cashierMap[(int) $row->user_id] ?? $this->migrationUser->id,
            'customer_num' => $customerNum,
            'route_id' => $customer?->route_id,
            'required_date' => $row->required_date,
            'delivery_date' => $row->delivery_date,
            'status' => $routeStatus['status'],
            'total_vat' => $totalVat,
            'order_total' => $orderTotal,
            'payment_method_code' => $routeStatus['is_credit_sale'] ? 'CREDIT' : 'CASH',
            'is_credit_sale' => $routeStatus['is_credit_sale'],
            'stock_balanced' => 1,
            'receipt_printed' => (int) ($row->receipt_printed ?? 0),
            'comments' => $this->mergeLegacyComment($row->comments ?? null, $orderRef['label'], $legacyOrderNum),
            'fulfillment_meta' => $this->legacyFulfillmentMeta(
                $orderRef['label'],
                $legacyOrderNum,
                LightStoresLegacySchema::ROUTE_MASTERS,
                $saleDate,
                $orderTotal,
                $totalVat,
            ),
            'completed_at' => $row->delivery_date ?: $row->create_time,
        ]);

        $this->stampSaleCreatedAt($sale->id, $row->create_time);
        $this->insertRouteOrderLines($sale->id, $lines);
        $this->bump('sales_mobile');

        return $sale;
    }

    protected function createDebtorSale(object $row, int $legacyOrderNum, string $saleDate): ?Sale
    {
        $saleDate = date('Y-m-d', strtotime($saleDate));
        $customerNum = (int) $row->customer_num;
        if (! Customer::query()->where('customer_num', $customerNum)->exists()) {
            return null;
        }

        $lines = DB::connection($this->legacy)
            ->table(LightStoresLegacySchema::DEBTOR_MASTERS.' as dm')
            ->join(LightStoresLegacySchema::DEBTOR_LINES.' as dp', 'dp.order_num', '=', 'dm.order_num')
            ->where('dm.order_num', $legacyOrderNum)
            ->whereDate('dm.create_time', $saleDate)
            ->whereNull('dm.dlt_on')
            ->orderBy('dp.item_code')
            ->get(['dp.*']);

        if ($lines->isEmpty()) {
            return null;
        }

        $debtorStatus = $this->mapLegacyDebtorPaymentStatus((int) ($row->payment_status ?? 1));
        [$orderTotal, $totalVat] = $this->sumLegacyLines($lines, 'amount', 'product_vat');
        $amountPaid = $debtorStatus['payment_status'] === 'paid' ? $orderTotal : 0.0;
        $orderRef = $this->allocateLegacyOrderNum('debtor');

        $sale = Sale::query()->create([
            'order_num' => $orderRef['order_num'],
            'branch_id' => $this->branch->id,
            'organization_id' => $this->organization->id,
            'channel' => 'mobile',
            'payment_status' => $debtorStatus['payment_status'],
            'amount_paid' => $amountPaid,
            'cashier_id' => $this->cashierMap[(int) $row->user_id] ?? $this->migrationUser->id,
            'customer_num' => $customerNum,
            'status' => $debtorStatus['status'],
            'total_vat' => $totalVat,
            'order_total' => $orderTotal,
            'payment_method_code' => 'CREDIT',
            'is_credit_sale' => $debtorStatus['is_credit_sale'],
            'stock_balanced' => 1,
            'comments' => $this->mergeLegacyComment(null, $orderRef['label'], $legacyOrderNum),
            'fulfillment_meta' => $this->legacyFulfillmentMeta(
                $orderRef['label'],
                $legacyOrderNum,
                LightStoresLegacySchema::DEBTOR_MASTERS,
                $saleDate,
                $orderTotal,
                $totalVat,
            ),
            'completed_at' => $row->create_time,
        ]);

        $this->stampSaleCreatedAt($sale->id, $row->create_time);
        $this->insertDebtorOrderLines($sale->id, $lines);
        $this->bump('sales_debtor');

        return $sale;
    }

    protected function createPosSale(object $row, int $legacyOrderNum, string $saleDate, ?string $walkInName): ?Sale
    {
        $saleDate = date('Y-m-d', strtotime($saleDate));
        $lines = DB::connection($this->legacy)
            ->table(LightStoresLegacySchema::POS_LINES.' as sp')
            ->where('sp.order_num_ref', $legacyOrderNum)
            ->whereDate('sp.create_time', $saleDate)
            ->orderBy('sp.id')
            ->get();

        if ($lines->isEmpty()) {
            return null;
        }

        $this->assertLegacyProductsExist($lines, 'productsales_id');
        [$orderTotal, $totalVat] = $this->sumLegacyLines($lines, 'amount', 'product_vat');

        $cashierId = $this->cashierMap[(int) $row->userid_sales] ?? $this->migrationUser->id;
        $amountPaid = (float) $row->cash
            + (float) $row->mpesa_amount
            + (float) $row->equity_amount
            + (float) $row->kcb_amount;
        $paymentStatus = $this->resolvePaymentStatus($amountPaid, $orderTotal, (string) ($row->payment_method ?? ''));
        $orderRef = $this->allocateLegacyOrderNum('pos');

        $sale = Sale::query()->create([
            'order_num' => $orderRef['order_num'],
            'branch_id' => $this->branch->id,
            'organization_id' => $this->organization->id,
            'channel' => 'pos',
            'payment_status' => $paymentStatus,
            'amount_paid' => min($amountPaid, $orderTotal > 0 ? $orderTotal : $amountPaid),
            'cashier_id' => $cashierId,
            'customer_name_override' => $walkInName,
            'status' => 'completed',
            'total_vat' => $totalVat,
            'order_total' => $orderTotal,
            'cash' => (int) round((float) $row->cash),
            'mpesa_amount' => (int) round((float) $row->mpesa_amount),
            'equity_amount' => (int) round((float) $row->equity_amount),
            'kcb_amount' => (int) round((float) $row->kcb_amount),
            'order_change' => (int) round((float) $row->order_change),
            'payment_method_code' => $this->mapPaymentMethodCode((string) ($row->payment_method ?? 'C')),
            'is_credit_sale' => strtoupper((string) ($row->payment_method ?? '')) === 'CR',
            'stock_balanced' => 1,
            'comments' => $this->mergeLegacyComment(null, $orderRef['label'], $legacyOrderNum),
            'fulfillment_meta' => $this->legacyFulfillmentMeta(
                $orderRef['label'],
                $legacyOrderNum,
                LightStoresLegacySchema::POS_MASTERS,
                $saleDate,
                $orderTotal,
                $totalVat,
            ),
            'completed_at' => $row->create_time,
        ]);

        $this->stampSaleCreatedAt($sale->id, $row->create_time);
        $this->insertPosOrderLines($sale->id, $lines);
        $this->bump('sales_pos');

        return $sale;
    }

    protected function mergeLegacyComment(?string $existing, string $label, int $legacyOrderNum): ?string
    {
        $prefix = sprintf('[%s legacy #%d]', $label, $legacyOrderNum);
        $existing = trim((string) $existing);

        return $existing === '' ? $prefix : $prefix.' '.$existing;
    }

    protected function syncDebtorBalances(): void
    {
        $balances = DB::connection($this->legacy)
            ->table(LightStoresLegacySchema::DEBTOR_MASTERS.' as dm')
            ->join(LightStoresLegacySchema::DEBTOR_LINES.' as dp', 'dp.order_num', '=', 'dm.order_num')
            ->whereNull('dm.dlt_on')
            ->whereIn('dm.payment_status', [1, 3])
            ->selectRaw('dm.customer_num, ROUND(SUM(dp.amount), 2) as balance')
            ->groupBy('dm.customer_num')
            ->get();

        foreach ($balances as $row) {
            Customer::query()
                ->where('customer_num', (int) $row->customer_num)
                ->update(['current_balance' => (float) $row->balance]);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $lines
     * @return array{0: float, 1: float}
     */
    protected function sumLegacyLines(Collection $lines, string $amountField, string $vatField): array
    {
        $orderTotal = 0.0;
        $totalVat = 0.0;

        foreach ($lines as $line) {
            $orderTotal += (float) ($line->{$amountField} ?? 0);
            $totalVat += (float) ($line->{$vatField} ?? 0);
        }

        return [round($orderTotal, 2), round($totalVat, 2)];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $lines
     */
    protected function assertLegacyProductsExist(Collection $lines, string $productField): void
    {
        $missing = [];

        foreach ($lines as $line) {
            $productCode = trim((string) ($line->{$productField} ?? ''));
            if ($productCode === '') {
                $missing[] = '(empty product code)';

                continue;
            }

            if (! Product::query()->where('product_code', $productCode)->exists()) {
                $missing[] = $productCode;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Cannot materialize this legacy sale: product(s) not found in Centrix catalog: '
                .implode(', ', array_values(array_unique($missing)))
                .'. Import the products first — quantities and amounts must match the legacy order exactly.',
            );
        }
    }

    /**
     * @return array{status: string, payment_status: string, is_credit_sale: bool}
     */
    protected function mapLegacyRouteOrderStatus(int $legacyStatus): array
    {
        return match ($legacyStatus) {
            2 => ['status' => 'completed', 'payment_status' => 'paid', 'is_credit_sale' => false],
            3 => ['status' => 'completed', 'payment_status' => 'unpaid', 'is_credit_sale' => true],
            default => ['status' => 'unpaid', 'payment_status' => 'unpaid', 'is_credit_sale' => false],
        };
    }

    /**
     * @return array{status: string, payment_status: string, is_credit_sale: bool}
     */
    protected function mapLegacyDebtorPaymentStatus(int $legacyStatus): array
    {
        return match ($legacyStatus) {
            2 => ['status' => 'completed', 'payment_status' => 'paid', 'is_credit_sale' => false],
            3 => ['status' => 'completed', 'payment_status' => 'unpaid', 'is_credit_sale' => true],
            default => ['status' => 'completed', 'payment_status' => 'unpaid', 'is_credit_sale' => true],
        };
    }

    protected function stampSaleCreatedAt(int $saleId, mixed $legacyTimestamp): void
    {
        if (! $legacyTimestamp) {
            return;
        }

        DB::table('sales')->where('id', $saleId)->update([
            'created_at' => $legacyTimestamp,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $lines
     */
    protected function insertRouteOrderLines(int $saleId, Collection $lines): void
    {
        $lineNo = 0;
        foreach ($lines as $line) {
            $productCode = (string) $line->product_code;
            if (! Product::query()->where('product_code', $productCode)->exists()) {
                continue;
            }

            $lineNo++;
            DB::table('sale_items')->insert([
                'sale_id' => $saleId,
                'product_code' => $productCode,
                'line_no' => $lineNo,
                'quantity' => (float) ($line->qty_ordered ?? 0),
                'uom' => $line->uom,
                'selling_price' => (float) ($line->unit_price ?? 0),
                'discount_given' => 0,
                'product_vat' => (float) ($line->product_vat ?? 0),
                'amount' => (float) ($line->amount ?? 0),
                'on_wholesale_retail' => 0,
                'created_at' => $line->create_time ? date('Y-m-d', strtotime((string) $line->create_time)) : now()->toDateString(),
            ]);
            $this->bump('sale_items');
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $lines
     */
    protected function insertDebtorOrderLines(int $saleId, Collection $lines): void
    {
        $lineNo = 0;
        foreach ($lines as $line) {
            $productCode = (string) $line->product_code;
            if (! Product::query()->where('product_code', $productCode)->exists()) {
                continue;
            }

            $lineNo++;
            DB::table('sale_items')->insert([
                'sale_id' => $saleId,
                'product_code' => $productCode,
                'line_no' => $lineNo,
                'quantity' => (float) $line->quantity,
                'uom' => $line->uom,
                'selling_price' => (float) $line->selling_price,
                'discount_given' => (float) ($line->discount_given ?? 0),
                'product_vat' => (float) ($line->product_vat ?? 0),
                'amount' => (float) $line->amount,
                'on_wholesale_retail' => 0,
                'created_at' => $line->create_time ? date('Y-m-d', strtotime((string) $line->create_time)) : now()->toDateString(),
            ]);
            $this->bump('sale_items');
        }
    }

    protected function insertPosOrderLines(int $saleId, Collection $lines): void
    {
        $lineNo = 0;

        foreach ($lines as $line) {
            $productCode = (string) $line->productsales_id;
            if (! Product::query()->where('product_code', $productCode)->exists()) {
                continue;
            }

            $lineNo++;
            DB::table('sale_items')->insert([
                'sale_id' => $saleId,
                'product_code' => $productCode,
                'line_no' => $lineNo,
                'quantity' => (float) $line->quantity,
                'uom' => $line->uom,
                'selling_price' => (float) $line->selling_price,
                'discount_given' => (float) ($line->discount_given ?? 0),
                'product_vat' => (float) ($line->product_vat ?? 0),
                'amount' => (float) $line->amount,
                'on_wholesale_retail' => 0,
                'created_at' => $line->create_time ? date('Y-m-d', strtotime((string) $line->create_time)) : now()->toDateString(),
            ]);
            $this->bump('sale_items');
        }
    }

    protected function resolvePaymentStatus(float $amountPaid, float $orderTotal, string $legacyMethod): string
    {
        if ($orderTotal <= 0) {
            return 'paid';
        }

        if ($amountPaid <= 0 && strtoupper($legacyMethod) === 'CR') {
            return 'unpaid';
        }

        if ($amountPaid + 0.01 < $orderTotal) {
            return $amountPaid > 0 ? 'partial' : 'unpaid';
        }

        return 'paid';
    }

    protected function mapPaymentMethodCode(string $legacyMethod): string
    {
        return match (strtoupper(trim($legacyMethod))) {
            'M' => 'MPESA',
            'CR' => 'CREDIT',
            default => 'CASH',
        };
    }

    protected function bootContext(?Organization $organization = null): void
    {
        if ($this->organization && $this->branch && $this->migrationUser) {
            return;
        }

        if ($organization) {
            $this->organization = $organization;
        } elseif ($this->targetOrganizationId) {
            $this->organization = Organization::query()->findOrFail($this->targetOrganizationId);
        } else {
            $legacyOrg = DB::connection($this->legacy)->table('org_info')->first();
            if (! $legacyOrg) {
                throw new RuntimeException('Legacy org_info row not found.');
            }

            $this->organization = Organization::query()->where('company_code', $legacyOrg->company_code)->firstOrFail();
        }

        $this->branch = Branch::query()
            ->where('organization_id', $this->organization->id)
            ->where('branch_code', 'HQ')
            ->firstOrFail();
        $this->migrationUser = User::query()
            ->where('organization_id', $this->organization->id)
            ->where('username', 'legacy.import')
            ->firstOrFail();

        $legacyUsers = DB::connection($this->legacy)->table('user')->whereNull('dlt_on')->get();
        foreach ($legacyUsers as $legacyUser) {
            $user = User::query()
                ->where('organization_id', $this->organization->id)
                ->where('username', strtoupper((string) $legacyUser->username))
                ->first();
            if ($user) {
                $this->cashierMap[(int) $legacyUser->id] = $user->id;
            }
        }
        $this->cashierMap[0] = $this->migrationUser->id;
    }

    protected function importLegacySuppliers(): void
    {
        foreach (DB::connection($this->legacy)->table('suppliers')->orderBy('SPLR_ID')->get() as $row) {
            $contacts = DB::connection($this->legacy)
                ->table('supplier_addnl_contacts')
                ->where('SPLR_REF', $row->SPLR_ID)
                ->get()
                ->map(fn ($contact) => array_filter([
                    'label' => 'Additional',
                    'phone' => $contact->PHONE_NO,
                    'email' => $contact->SPLR_EMAIL,
                ], fn ($value) => filled($value)))
                ->filter(fn (array $contact) => $contact !== [])
                ->values()
                ->all();

            $this->upsertById(Supplier::class, (int) $row->SPLR_ID, [
                'supplier_code' => 'LS-'.(int) $row->SPLR_ID,
                'supplier_name' => (string) ($row->SPLR_NAME ?: 'Supplier '.$row->SPLR_ID),
                'contact_person' => $row->CNTCT_PRSN,
                'email' => $row->SPLR_EMAIL,
                'phone' => $row->SPLR_CNTCT,
                'address' => $row->SPLR_ADDRS,
                'town' => $row->SPLR_TOWN,
                'additional_info' => $row->ADDNL_INFO,
                'contacts' => $contacts !== [] ? $contacts : null,
                'organization_id' => $this->organization->id,
                'is_active' => (bool) ($row->ACTV_FLG ?? true),
                'created_by' => $this->migrationUser->id,
                'deleted_at' => $row->DLT_ON,
                'deleted_by' => filled($row->DLT_BY) ? $this->migrationUser->id : null,
            ]);
            $this->bump('suppliers');
        }
    }

    protected function resolveLegacySupplierId(mixed $legacySupplierId): ?int
    {
        $id = (int) $legacySupplierId;
        if ($id <= 0) {
            return null;
        }

        return Supplier::query()->whereKey($id)->exists() ? $id : null;
    }

    protected function resolveLegacyVatId(mixed $legacyVatId, int $defaultVatId): int
    {
        $id = (int) $legacyVatId;
        if ($id > 0 && Vat::query()->whereKey($id)->exists()) {
            return $id;
        }

        return $defaultVatId;
    }

    protected function bump(string $key, int $count = 1): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + $count;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $attributes
     */
    protected function upsertById(string $modelClass, int $id, array $attributes): void
    {
        $modelClass::unguarded(function () use ($modelClass, $id, $attributes) {
            $modelClass::query()->updateOrCreate(['id' => $id], $attributes);
        });
    }
}
