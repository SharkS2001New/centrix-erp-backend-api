<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\MpesaPaybillAccount;
use App\Models\RouteModel;
use App\Models\Till;
use App\Services\Erp\ErpContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MpesaPaybillAccountController extends Controller
{
    public function __construct(protected ErpContext $erp) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $orgId = (int) $this->erp->access()->organizationId($user, $request);
        if ($orgId <= 0 || ! Schema::hasTable('mpesa_paybill_accounts')) {
            return response()->json(['data' => []]);
        }

        $rows = MpesaPaybillAccount::query()
            ->where('organization_id', $orgId)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $orgId = (int) $this->erp->access()->organizationId($user, $request);
        $data = $this->validated($request, $orgId);

        $this->assertShortCodeAvailable($data['primary_short_code'], $orgId);

        if (! empty($data['is_default'])) {
            MpesaPaybillAccount::query()
                ->where('organization_id', $orgId)
                ->update(['is_default' => false]);
        }

        $account = MpesaPaybillAccount::query()->create(array_merge($data, [
            'organization_id' => $orgId,
        ]));

        $this->syncScopeLinks($account);

        return response()->json($account->fresh(), 201);
    }

    public function update(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = (int) $this->erp->access()->organizationId($user, $request);
        $account = MpesaPaybillAccount::query()
            ->where('organization_id', $orgId)
            ->findOrFail($id);

        $data = $this->validated($request, $orgId, $account);
        $this->assertShortCodeAvailable($data['primary_short_code'], $orgId, (int) $account->id);

        if (! empty($data['is_default'])) {
            MpesaPaybillAccount::query()
                ->where('organization_id', $orgId)
                ->where('id', '!=', $account->id)
                ->update(['is_default' => false]);
        }

        $account->fill($data)->save();
        $this->syncScopeLinks($account->fresh());

        return response()->json($account->fresh());
    }

    public function destroy(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = (int) $this->erp->access()->organizationId($user, $request);
        $account = MpesaPaybillAccount::query()
            ->where('organization_id', $orgId)
            ->findOrFail($id);

        if ($account->is_default) {
            throw ValidationException::withMessages([
                'id' => ['Cannot delete the default paybill. Set another account as default first.'],
            ]);
        }

        if (Schema::hasColumn('routes', 'mpesa_paybill_account_id')) {
            RouteModel::query()
                ->where('mpesa_paybill_account_id', $account->id)
                ->update(['mpesa_paybill_account_id' => null]);
        }
        if (Schema::hasColumn('branches', 'mpesa_paybill_account_id')) {
            Branch::query()
                ->where('mpesa_paybill_account_id', $account->id)
                ->update(['mpesa_paybill_account_id' => null]);
        }

        $account->delete();

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, int $orgId, ?MpesaPaybillAccount $existing = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'primary_short_code' => 'required|string|max:20',
            'shortcode' => 'nullable|string|max:20',
            'till_number' => 'nullable|string|max:20',
            'child_storecode' => 'nullable|string|max:20',
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('organization_id', $orgId)),
            ],
            'route_id' => [
                'nullable',
                'integer',
                Rule::exists('routes', 'id')->where(fn ($q) => $q->where('organization_id', $orgId)),
            ],
            'pos_till_id' => [
                'nullable',
                'integer',
                Rule::exists('tills', 'id')->where(fn ($q) => $q->where('organization_id', $orgId)),
            ],
            'is_default' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'enable_stk_push' => 'sometimes|nullable|boolean',
            'sort_order' => 'sometimes|integer|min:0|max:9999',
        ]);

        $data['primary_short_code'] = trim((string) $data['primary_short_code']);
        foreach (['shortcode', 'till_number', 'child_storecode', 'name'] as $key) {
            if (array_key_exists($key, $data) && is_string($data[$key])) {
                $data[$key] = trim($data[$key]) ?: null;
            }
        }
        if (($data['child_storecode'] ?? null) === null) {
            $data['child_storecode'] = $data['primary_short_code'];
        }
        if (! array_key_exists('is_active', $data) && ! $existing) {
            $data['is_active'] = true;
        }
        if (array_key_exists('enable_stk_push', $data) && $data['enable_stk_push'] === '') {
            $data['enable_stk_push'] = null;
        }

        return $data;
    }

    protected function assertShortCodeAvailable(string $primary, int $orgId, ?int $ignoreId = null): void
    {
        $conflict = MpesaPaybillAccount::query()
            ->where(function ($query) use ($primary) {
                $query->where('primary_short_code', $primary)
                    ->orWhere('child_storecode', $primary)
                    ->orWhere('till_number', $primary)
                    ->orWhere('shortcode', $primary);
            })
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->first();

        if ($conflict && (int) $conflict->organization_id !== $orgId) {
            throw ValidationException::withMessages([
                'primary_short_code' => ["Shortcode {$primary} is already used by another organization."],
            ]);
        }

        if ($conflict && (int) $conflict->id !== (int) ($ignoreId ?? 0)) {
            throw ValidationException::withMessages([
                'primary_short_code' => ["Shortcode {$primary} is already used by another paybill in this organization."],
            ]);
        }
    }

    protected function syncScopeLinks(MpesaPaybillAccount $account): void
    {
        if ($account->route_id && Schema::hasColumn('routes', 'mpesa_paybill_account_id')) {
            RouteModel::query()
                ->where('id', (int) $account->route_id)
                ->where('organization_id', (int) $account->organization_id)
                ->update(['mpesa_paybill_account_id' => $account->id]);
        }

        if ($account->branch_id && Schema::hasColumn('branches', 'mpesa_paybill_account_id')) {
            Branch::query()
                ->where('id', (int) $account->branch_id)
                ->where('organization_id', (int) $account->organization_id)
                ->update(['mpesa_paybill_account_id' => $account->id]);
        }

        if ($account->pos_till_id && Schema::hasColumn('tills', 'mpesa_paybill_account_id')) {
            Till::query()
                ->where('id', (int) $account->pos_till_id)
                ->where('organization_id', (int) $account->organization_id)
                ->update(['mpesa_paybill_account_id' => $account->id]);
        }
    }
}
