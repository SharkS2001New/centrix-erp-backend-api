<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\EquityBankAccount;
use App\Models\RouteModel;
use App\Services\Auth\UserAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EquityBankAccountController extends Controller
{
    public function __construct(protected UserAccessService $access) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $orgId = (int) ($this->access->organizationId($user, $request) ?? 0);
        if ($orgId <= 0 || ! Schema::hasTable('equity_bank_accounts')) {
            return response()->json(['data' => []]);
        }

        $rows = EquityBankAccount::query()
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
        $orgId = (int) ($this->access->organizationId($user, $request) ?? 0);
        $data = $this->validated($request, $orgId);

        $this->assertAccountNumberAvailable($data['primary_account_number'], $orgId);

        if (! empty($data['is_default'])) {
            EquityBankAccount::query()
                ->where('organization_id', $orgId)
                ->update(['is_default' => false]);
        }

        $account = EquityBankAccount::query()->create(array_merge($data, [
            'organization_id' => $orgId,
        ]));

        $this->syncScopeLinks($account);

        return response()->json($account->fresh(), 201);
    }

    public function update(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = (int) ($this->access->organizationId($user, $request) ?? 0);
        $account = EquityBankAccount::query()
            ->where('organization_id', $orgId)
            ->findOrFail($id);

        $data = $this->validated($request, $orgId, $account);
        $this->assertAccountNumberAvailable($data['primary_account_number'], $orgId, (int) $account->id);

        if (! empty($data['is_default'])) {
            EquityBankAccount::query()
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
        $orgId = (int) ($this->access->organizationId($user, $request) ?? 0);
        $account = EquityBankAccount::query()
            ->where('organization_id', $orgId)
            ->findOrFail($id);

        if ($account->is_default) {
            throw ValidationException::withMessages([
                'id' => ['Cannot delete the default Equity account. Set another account as default first.'],
            ]);
        }

        if (Schema::hasColumn('routes', 'equity_bank_account_id')) {
            RouteModel::query()
                ->where('equity_bank_account_id', $account->id)
                ->update(['equity_bank_account_id' => null]);
        }
        if (Schema::hasColumn('branches', 'equity_bank_account_id')) {
            Branch::query()
                ->where('equity_bank_account_id', $account->id)
                ->update(['equity_bank_account_id' => null]);
        }

        $account->delete();

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, int $orgId, ?EquityBankAccount $existing = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'primary_account_number' => 'required|string|max:40',
            'account_number' => 'nullable|string|max:40',
            'paybill_number' => 'nullable|string|max:40',
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
            'is_default' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'callback_url' => 'sometimes|nullable|string|max:500',
            'callback_shared_secret' => 'sometimes|nullable|string|max:2000',
            'sort_order' => 'sometimes|integer|min:0|max:9999',
        ]);

        $data['primary_account_number'] = trim((string) $data['primary_account_number']);
        foreach (['account_number', 'paybill_number', 'name', 'callback_url'] as $key) {
            if (array_key_exists($key, $data) && is_string($data[$key])) {
                $data[$key] = trim($data[$key]) ?: null;
            }
        }
        if (! array_key_exists('is_active', $data) && ! $existing) {
            $data['is_active'] = true;
        }

        if (array_key_exists('callback_shared_secret', $data)) {
            $incoming = trim((string) $data['callback_shared_secret']);
            if ($incoming === '' || $incoming === '********') {
                unset($data['callback_shared_secret']);
            }
        }

        foreach (['callback_url', 'callback_shared_secret'] as $col) {
            if (array_key_exists($col, $data) && ! Schema::hasColumn('equity_bank_accounts', $col)) {
                unset($data[$col]);
            }
        }

        return $data;
    }

    protected function assertAccountNumberAvailable(string $primary, int $orgId, ?int $ignoreId = null): void
    {
        $conflict = EquityBankAccount::query()
            ->where(function ($query) use ($primary) {
                $query->where('primary_account_number', $primary)
                    ->orWhere('paybill_number', $primary)
                    ->orWhere('account_number', $primary);
            })
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->first();

        if ($conflict && (int) $conflict->organization_id !== $orgId) {
            throw ValidationException::withMessages([
                'primary_account_number' => ["Account / paybill {$primary} is already used by another organization."],
            ]);
        }

        if ($conflict && (int) $conflict->id !== (int) ($ignoreId ?? 0)) {
            throw ValidationException::withMessages([
                'primary_account_number' => ["Account / paybill {$primary} is already used by another Equity account in this organization."],
            ]);
        }
    }

    protected function syncScopeLinks(EquityBankAccount $account): void
    {
        if ($account->route_id && Schema::hasColumn('routes', 'equity_bank_account_id')) {
            RouteModel::query()
                ->where('id', (int) $account->route_id)
                ->where('organization_id', (int) $account->organization_id)
                ->update(['equity_bank_account_id' => $account->id]);
        }

        if ($account->branch_id && Schema::hasColumn('branches', 'equity_bank_account_id')) {
            Branch::query()
                ->where('id', (int) $account->branch_id)
                ->where('organization_id', (int) $account->organization_id)
                ->update(['equity_bank_account_id' => $account->id]);
        }
    }
}
