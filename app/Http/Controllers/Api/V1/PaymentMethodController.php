<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\PaymentMethod;
use App\Services\Erp\ErpContext;
use App\Services\Organization\OrganizationReferenceDataService;
use App\Services\Sales\OrgPaymentMethodFilter;
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
        $orgId = 0;
        if ($user) {
            $orgId = (int) ($this->access()->organizationId($user, $request) ?? 0);
            if ($orgId > 0) {
                $this->referenceData->ensurePaymentMethods($orgId);
            }
        }

        // Centrix AS Mobile / checkout: only methods enabled under
        // Settings → Sales → Recording payments (not the full catalog).
        if ($request->boolean('recording_payments') && $orgId > 0) {
            return $this->indexRecordingPayments($request, $orgId);
        }

        return parent::index($request);
    }

    protected function indexRecordingPayments(Request $request, int $orgId)
    {
        $gate = app(ErpContext::class)->gateForRequest($request);
        $sales = $gate->moduleSettings('sales');

        $methods = PaymentMethod::query()
            ->where('organization_id', $orgId)
            ->where(function ($query) {
                $query->where('is_active', true)->orWhere('is_active', 1);
            })
            ->orderBy('method_name')
            ->get();

        $filtered = OrgPaymentMethodFilter::filter($methods, $sales, [
            'include_credit' => $request->boolean('include_credit', true),
            'mpesa_platform' => $gate->mpesaStkPlatformEnabled(),
            'equity_platform' => $gate->equityBankPlatformEnabled(),
        ]);

        return response()->json([
            'data' => $filtered->values()->all(),
        ]);
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
