<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Customer;
use App\Models\Sale;
use App\Services\Customers\CustomerNumberAllocator;
use App\Services\Customers\CustomerRoutePolicy;
use App\Services\Customers\CustomerUniquenessValidator;
use App\Services\Auth\UserAccessService;
use App\Services\Erp\ErpContext;
use App\Services\Cache\OrganizationCache;
use App\Support\SqlLikeSearch;
use App\Support\TenantRouteRules;
use App\Support\UploadedImageProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\StoredPublicFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CustomerController extends BaseResourceController
{
    public function __construct(
        protected CustomerUniquenessValidator $customerUniqueness,
        protected CustomerRoutePolicy $customerRoutePolicy,
        protected ErpContext $erp,
    ) {}

    protected function modelClass(): string
    {
        return Customer::class;
    }

    protected function routeKeyColumn(): string
    {
        return 'customer_num';
    }

    protected function sortableColumns(): array
    {
        return [
            'customer_num',
            'customer_name',
            'customer_type',
            'phone_number',
            'town',
            'credit_limit',
            'current_balance',
            'created_at',
        ];
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request);

        $status = (string) $request->input('status', 'active');
        if ($status === 'inactive') {
            $query->onlyTrashed();
        } elseif ($status === 'all') {
            $query->withTrashed();
        }

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if ($val === null || $val === '') {
                continue;
            }
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($q = trim((string) $request->input('q', ''))) {
            SqlLikeSearch::applyCustomerSearch($query, $q);
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $this->applyListOrdering($request, $query, 'customer_num', 'desc');

        return response()->json($query->paginate($perPage));
    }

    /** GET /customers/summary */
    public function summary(Request $request)
    {
        $user = $request->user();
        $orgId = $this->access()->organizationId($user, $request);
        $ttl = max(60, min(300, (int) config('cache.hub_summary_ttl', 120)));

        $build = function () use ($request, $user) {
            $query = $this->baseQuery($request)->whereNull('deleted_at');
            $now = now();

            $row = (clone $query)->selectRaw(
                'COUNT(*) AS active,
                 SUM(CASE WHEN MONTH(created_at) = ? AND YEAR(created_at) = ? THEN 1 ELSE 0 END) AS new_this_month,
                 SUM(CASE WHEN route_id IS NOT NULL THEN 1 ELSE 0 END) AS on_routes,
                 COALESCE(SUM(current_balance), 0) AS outstanding_balance',
                [$now->month, $now->year],
            )->first();

            return [
                'active' => (int) ($row->active ?? 0),
                'new_this_month' => (int) ($row->new_this_month ?? 0),
                'on_routes' => (int) ($row->on_routes ?? 0),
                'outstanding_balance' => (float) ($row->outstanding_balance ?? 0),
            ];
        };

        if ($orgId) {
            $branchKey = $this->access()->branchId($user) ?? 'all';

            return response()->json(
                OrganizationCache::remember(
                    $orgId,
                    'customers.summary:'.$branchKey,
                    $ttl,
                    $build,
                ),
            );
        }

        return response()->json($build());
    }

    /** GET /customers/{customerNum}/sales — orders; line items optional via with_items=1 */
    public function sales(Request $request, string $customer)
    {
        $model = $this->findScopedModel($request, $customer);

        $query = Sale::query()
            ->where('customer_num', $model->customer_num)
            ->where('organization_id', $model->organization_id)
            ->whereNull('deleted_at')
            ->orderByDesc('id');

        if ($request->boolean('with_items')) {
            $query->with(['items.product']);
        }

        $perPage = min((int) $request->input('per_page', 20), 100);

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $fields = array_values(array_filter(
            $this->fillableFields(),
            fn (string $field) => $field !== 'customer_num',
        ));
        $data = $this->normalizeCustomerPayload($request->validate(
            $this->customerRules($fields, request: $request),
        ));
        $gate = $this->erp->gateForUser($user);
        $data = $this->customerRoutePolicy->applyDistributionCustomerRules($data, $gate);
        $organizationId = (int) ($this->access()->organizationId($user, $request) ?? $data['organization_id'] ?? 0);
        $this->customerUniqueness->assertUnique(
            $organizationId,
            $data['phone_number'] ?? null,
            $data['additional_phone'] ?? null,
            $data['kra_pin'] ?? null,
        );

        $customer = DB::transaction(function () use ($data, $organizationId) {
            if (empty($data['customer_num'])) {
                $data['customer_num'] = app(CustomerNumberAllocator::class)->nextForOrganization($organizationId);
            }

            $data['organization_id'] = $organizationId;

            return Customer::create($data);
        });

        return response()->json($customer->fresh(), 201);
    }

    public function update(Request $request, string $id)
    {
        $customer = $this->findScopedModel($request, $id);
        $data = $this->normalizeCustomerPayload($request->validate(
            $this->customerRules($this->fillableFields(), partial: true, request: $request),
        ));
        $gate = $this->erp->gateForUser($request->user());
        $data = $this->customerRoutePolicy->applyDistributionCustomerRules($data, $gate, $customer);

        $this->customerUniqueness->assertUnique(
            (int) $customer->organization_id,
            $data['phone_number'] ?? $customer->phone_number,
            $data['additional_phone'] ?? $customer->additional_phone,
            $data['kra_pin'] ?? $customer->kra_pin,
            (int) $customer->customer_num,
        );

        $customer->update($data);

        return response()->json($customer->fresh());
    }

    /** GET /customers/{customerNum}/shop-image/file — authenticated image bytes */
    public function shopImageFile(Request $request, string $customer)
    {
        $model = $this->findScopedModel($request, $customer);

        if (! StoredPublicFile::exists($model->shop_image)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return StoredPublicFile::response($model->shop_image, 'image/jpeg');
    }

    /** POST /customers/{customerNum}/shop-image — multipart shop photo */
    public function uploadShopImage(Request $request, string $customer)
    {
        $model = $this->findScopedModel($request, $customer);

        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        if ($model->shop_image) {
            Storage::disk('public')->delete($model->shop_image);
        }

        $stored = app(UploadedImageProcessor::class)->storePublicImage(
            $request->file('image'),
            \App\Support\OrganizationPublicStorage::path($model->organization_id ?? $request->user()?->organization_id, 'customers', (string) $model->customer_num),
        );

        $model->update(['shop_image' => $stored['path']]);

        return response()->json($model->fresh());
    }

    /** DELETE /customers/{customerNum}/shop-image */
    public function deleteShopImage(Request $request, string $customer)
    {
        $model = $this->findScopedModel($request, $customer);

        if ($model->shop_image) {
            Storage::disk('public')->delete($model->shop_image);
            $model->update(['shop_image' => null]);
        }

        return response()->json($model->fresh());
    }

    protected function customerRules(array $fields, bool $partial = false, ?Request $request = null): array
    {
        $prefix = $partial ? 'sometimes|' : '';

        $rules = [];
        foreach ($fields as $field) {
            if ($field === 'route_id') {
                continue;
            }
            $rules[$field] = $prefix.'nullable';
        }

        $rules['latitude'] = ($partial ? 'sometimes|' : '').'nullable|numeric|between:-90,90';
        $rules['longitude'] = ($partial ? 'sometimes|' : '').'nullable|numeric|between:-180,180';

        if (in_array('route_id', $fields, true)) {
            $orgId = $request
                ? (int) ($this->access()->organizationId($request->user(), $request) ?? 0)
                : 0;
            $rules['route_id'] = $partial
                ? array_merge(['sometimes'], TenantRouteRules::nullable($orgId ?: null))
                : TenantRouteRules::nullable($orgId ?: null);
        }

        return $rules;
    }

    protected function normalizeCustomerPayload(array $data): array
    {
        $latProvided = array_key_exists('latitude', $data);
        $lngProvided = array_key_exists('longitude', $data);

        if (! $latProvided && ! $lngProvided) {
            return $data;
        }

        $lat = $data['latitude'] ?? null;
        $lng = $data['longitude'] ?? null;
        $latSet = $lat !== null && $lat !== '';
        $lngSet = $lng !== null && $lng !== '';

        if ($latSet xor $lngSet) {
            throw ValidationException::withMessages([
                'latitude' => ['Latitude and longitude must both be provided together.'],
                'longitude' => ['Latitude and longitude must both be provided together.'],
            ]);
        }

        if (! $latSet) {
            $data['latitude'] = null;
            $data['longitude'] = null;
        } else {
            $data['latitude'] = round((float) $lat, 7);
            $data['longitude'] = round((float) $lng, 7);
        }

        return $data;
    }
}
