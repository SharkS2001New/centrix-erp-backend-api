<?php

namespace App\Http\Controllers\Api\V1\CentrixPayments;

use App\Http\Controllers\Controller;
use App\Models\MpesaIncomingPayment;
use App\Models\MpesaStkRequest;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\SalePayment;
use App\Services\Auth\UserAccessService;
use App\Services\Payments\CentrixPaymentsAvailabilityService;
use App\Services\Payments\PaymentAccountService;
use App\Services\Payments\PaymentProviderRegistry;
use App\Services\Payments\PaymentTransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class PaymentAccountController extends Controller
{
    public function __construct(
        protected UserAccessService $access,
        protected PaymentAccountService $accounts,
        protected PaymentProviderRegistry $providers,
    ) {}

    public function index(Request $request)
    {
        $orgId = $this->orgId($request);
        if (! Schema::hasTable('payment_accounts')) {
            return response()->json(['data' => []]);
        }

        $this->accounts->syncOrganization($orgId);
        $provider = $request->query('provider');
        $rows = $this->accounts->listForOrganization($orgId, is_string($provider) ? $provider : null);

        return response()->json([
            'data' => $rows->map(fn (PaymentAccount $row) => $this->accounts->toApiArray($row))->values(),
        ]);
    }

    public function show(Request $request, int $id)
    {
        $orgId = $this->orgId($request);
        $account = $this->accounts->findForOrganization($orgId, $id);
        if (! $account) {
            abort(404);
        }

        return response()->json($this->accounts->toApiArray($account));
    }

    public function store(Request $request)
    {
        $orgId = $this->orgId($request);
        $data = $request->validate([
            'provider' => ['required', Rule::in([
                PaymentAccount::PROVIDER_MPESA,
                PaymentAccount::PROVIDER_EQUITY,
                PaymentAccount::PROVIDER_BANK,
            ])],
            'account_type' => ['required', 'string', 'max:40'],
            'account_name' => ['required', 'string', 'max:160'],
            'account_number' => ['nullable', 'string', 'max:80'],
            'shortcode' => ['nullable', 'string', 'max:40'],
            'branch_id' => ['nullable', 'integer'],
            'is_default' => ['sometimes', 'boolean'],
            'auto_match_payments' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'array'],
        ]);

        if (! empty($data['is_default'])) {
            PaymentAccount::query()
                ->where('organization_id', $orgId)
                ->where('provider', $data['provider'])
                ->update(['is_default' => false]);
        }

        $account = PaymentAccount::query()->create([
            'organization_id' => $orgId,
            'branch_id' => $data['branch_id'] ?? null,
            'provider' => $data['provider'],
            'account_type' => $data['account_type'],
            'account_name' => $data['account_name'],
            'account_number' => $data['account_number'] ?? null,
            'shortcode' => $data['shortcode'] ?? null,
            'provider_account_type' => PaymentAccount::class,
            'provider_account_id' => 0,
            'status' => PaymentAccount::STATUS_ACTIVE,
            'is_default' => (bool) ($data['is_default'] ?? false),
            'auto_match_payments' => (bool) ($data['auto_match_payments'] ?? true),
            'metadata' => $data['metadata'] ?? [],
            'created_by' => $request->user()?->id,
        ]);

        return response()->json($this->accounts->toApiArray($account), 201);
    }

    public function update(Request $request, int $id)
    {
        $orgId = $this->orgId($request);
        $account = $this->accounts->findForOrganization($orgId, $id);
        if (! $account) {
            abort(404);
        }

        $data = $request->validate([
            'account_name' => ['sometimes', 'string', 'max:160'],
            'account_number' => ['sometimes', 'nullable', 'string', 'max:80'],
            'shortcode' => ['sometimes', 'nullable', 'string', 'max:40'],
            'branch_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', Rule::in([
                PaymentAccount::STATUS_ACTIVE,
                PaymentAccount::STATUS_INACTIVE,
                PaymentAccount::STATUS_PENDING,
            ])],
            'is_default' => ['sometimes', 'boolean'],
            'auto_match_payments' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'array'],
        ]);

        if (! empty($data['is_default'])) {
            PaymentAccount::query()
                ->where('organization_id', $orgId)
                ->where('provider', $account->provider)
                ->where('id', '!=', $account->id)
                ->update(['is_default' => false]);
        }

        $account->fill($data)->save();

        return response()->json($this->accounts->toApiArray($account->fresh()));
    }

    public function destroy(Request $request, int $id)
    {
        $orgId = $this->orgId($request);
        $account = $this->accounts->findForOrganization($orgId, $id);
        if (! $account) {
            abort(404);
        }

        if ($account->provider !== PaymentAccount::PROVIDER_BANK || $account->provider_account_id <= 0) {
            return response()->json([
                'message' => 'Only standalone bank payment accounts can be deleted here. Disable linked M-Pesa or Equity accounts from their provider screens.',
            ], 422);
        }

        $account->delete();

        return response()->json(['deleted' => true]);
    }

    public function testConnection(Request $request, int $id)
    {
        $orgId = $this->orgId($request);
        $account = $this->accounts->findForOrganization($orgId, $id);
        if (! $account) {
            abort(404);
        }

        $result = $this->providers->forAccount($account)->validateConfiguration($account);
        $status = $result['status'] ?? ($result['ok'] ? 'connected' : 'failed');
        if ($status === 'connected') {
            $account->update(['status' => PaymentAccount::STATUS_ACTIVE]);
        }

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function sync(Request $request)
    {
        $orgId = $this->orgId($request);
        $count = $this->accounts->syncOrganization($orgId);

        return response()->json(['synced' => $count]);
    }

    protected function orgId(Request $request): int
    {
        $orgId = (int) ($this->access->organizationId($request->user(), $request) ?? 0);
        if ($orgId <= 0) {
            abort(403, 'Your account is not linked to an organization.');
        }

        return $orgId;
    }
}
