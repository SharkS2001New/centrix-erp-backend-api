<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\PaymentMethod;
use App\Services\Organization\OrganizationReferenceDataService;
use Illuminate\Http\Request;

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
}
