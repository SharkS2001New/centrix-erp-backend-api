<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Customer;
use App\Models\Sale;
use App\Services\Customers\CustomerUniquenessValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CustomerController extends BaseResourceController
{
    public function __construct(
        protected CustomerUniquenessValidator $customerUniqueness,
    ) {}

    protected function modelClass(): string
    {
        return Customer::class;
    }

    protected function routeKeyColumn(): string
    {
        return 'customer_num';
    }

    /** GET /customers/{customerNum}/sales — orders with line items */
    public function sales(Request $request, string $customer)
    {
        Customer::where('customer_num', $customer)->firstOrFail();

        $query = Sale::query()
            ->with(['items.product'])
            ->where('customer_num', $customer)
            ->whereNull('deleted_at')
            ->orderByDesc('id');

        $perPage = min((int) $request->input('per_page', 20), 100);

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $fields = array_values(array_filter(
            $this->fillableFields(),
            fn (string $field) => $field !== 'customer_num',
        ));
        $data = $this->normalizeCustomerPayload($request->validate(
            $this->customerRules($fields),
        ));

        $user = $request->user();
        $this->customerUniqueness->assertUnique(
            (int) ($user?->organization_id ?? $data['organization_id'] ?? 0),
            $data['phone_number'] ?? null,
            $data['additional_phone'] ?? null,
            $data['kra_pin'] ?? null,
        );

        $customer = DB::transaction(function () use ($data) {
            if (empty($data['customer_num'])) {
                $max = Customer::query()->lockForUpdate()->max('customer_num');
                $data['customer_num'] = ((int) $max) + 1;
            }

            return Customer::create($data);
        });

        return response()->json($customer->fresh(), 201);
    }

    public function update(Request $request, string $id)
    {
        $customer = Customer::where($this->routeKeyColumn(), $id)->firstOrFail();
        $data = $this->normalizeCustomerPayload($request->validate(
            $this->customerRules($this->fillableFields(), partial: true),
        ));

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
    public function shopImageFile(string $customer)
    {
        $model = Customer::where('customer_num', $customer)->firstOrFail();

        if (! $model->shop_image || ! Storage::disk('public')->exists($model->shop_image)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $absolute = Storage::disk('public')->path($model->shop_image);
        $mime = Storage::disk('public')->mimeType($model->shop_image) ?: 'image/jpeg';

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** POST /customers/{customerNum}/shop-image — multipart shop photo */
    public function uploadShopImage(Request $request, string $customer)
    {
        $model = Customer::where('customer_num', $customer)->firstOrFail();

        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        if ($model->shop_image) {
            Storage::disk('public')->delete($model->shop_image);
        }

        $path = $request->file('image')->store(
            'customers/'.$model->customer_num,
            'public',
        );

        $model->update(['shop_image' => $path]);

        return response()->json($model->fresh());
    }

    /** DELETE /customers/{customerNum}/shop-image */
    public function deleteShopImage(string $customer)
    {
        $model = Customer::where('customer_num', $customer)->firstOrFail();

        if ($model->shop_image) {
            Storage::disk('public')->delete($model->shop_image);
            $model->update(['shop_image' => null]);
        }

        return response()->json($model->fresh());
    }

    protected function customerRules(array $fields, bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes|' : '';

        $rules = [];
        foreach ($fields as $field) {
            $rules[$field] = $prefix.'nullable';
        }

        $rules['latitude'] = ($partial ? 'sometimes|' : '').'nullable|numeric|between:-90,90';
        $rules['longitude'] = ($partial ? 'sometimes|' : '').'nullable|numeric|between:-180,180';

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
