<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\PaymentMethod;
use App\Services\Organization\OrganizationReferenceDataService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentMethodController extends BaseResourceController
{
    public function __construct(
        protected OrganizationReferenceDataService $referenceData,
    ) {
    }

    protected function modelClass(): string
    {
        return PaymentMethod::class;
    }

    /** @return list<string> */
    protected function searchColumns(): array
    {
        return ['method_name', 'method_code'];
    }

    protected function defaultListOrderColumn(): ?string
    {
        return 'method_name';
    }

    protected function defaultListOrderDirection(): string
    {
        return 'asc';
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $orgId = (int) ($this->access()->organizationId($user, $request) ?? 0);
            if ($orgId > 0) {
                $this->referenceData->ensurePaymentMethods($orgId);
            }
        }

        return parent::index($request);
    }

    public function update(Request $request, string $id)
    {
        $model = $this->findScopedModel($request, $id);
        if (
            $this->referenceData->isSystemMethodCode((string) $model->method_code)
            && $request->exists('method_code')
            && $this->referenceData->normalizeMethodCode((string) $request->input('method_code'))
                !== $this->referenceData->normalizeMethodCode((string) $model->method_code)
        ) {
            throw ValidationException::withMessages([
                'method_code' => ['System payment method codes cannot be renamed. Disable the method instead.'],
            ]);
        }

        return parent::update($request, $id);
    }

    public function destroy(Request $request, string $id)
    {
        $model = $this->findScopedModel($request, $id);
        if ($this->referenceData->isSystemMethodCode((string) $model->method_code)) {
            throw ValidationException::withMessages([
                'method_code' => ['System payment methods cannot be deleted. Disable the method if it should not appear at checkout.'],
            ]);
        }

        return parent::destroy($request, $id);
    }
}
