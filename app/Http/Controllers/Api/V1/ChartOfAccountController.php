<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ChartOfAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChartOfAccountController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return ChartOfAccount::class;
    }

    public function index(Request $request)
    {
        $orgId = (int) $request->user()->organization_id;
        $query = ChartOfAccount::query()->where('organization_id', $orgId);

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('account_code', 'like', "%{$q}%")
                    ->orWhere('account_name', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);
        $accounts = $query->orderBy('account_code')->paginate($perPage);
        $accountIds = collect($accounts->items())->pluck('id')->filter()->all();

        $rawBalances = $accountIds === []
            ? collect()
            : DB::table('journal_entry_lines as jel')
                ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
                ->where('je.organization_id', $orgId)
                ->where('je.status', 'posted')
                ->whereIn('jel.account_id', $accountIds)
                ->groupBy('jel.account_id')
                ->selectRaw('jel.account_id, SUM(jel.debit) as total_debit, SUM(jel.credit) as total_credit')
                ->get()
                ->keyBy('account_id');

        $accounts->getCollection()->transform(function ($account) use ($rawBalances) {
            $raw = $rawBalances->get($account->id);
            $account->balance = $this->displayBalance($account, $raw);

            return $account;
        });

        return response()->json($accounts);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'organization_id' => 'required|integer',
            'account_code' => 'required|string|max:20',
            'account_name' => 'required|string|max:200',
            'account_type' => 'required|in:asset,liability,equity,revenue,expense',
            'parent_id' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        if ((int) $data['organization_id'] !== (int) $request->user()->organization_id) {
            abort(403);
        }

        $model = ChartOfAccount::create($data);

        return response()->json($model, 201);
    }

    public function show(Request $request, string $id)
    {
        $model = ChartOfAccount::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where($this->routeKeyColumn(), $id)
            ->firstOrFail();

        return response()->json($model);
    }

    public function update(Request $request, string $id)
    {
        $model = ChartOfAccount::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where($this->routeKeyColumn(), $id)
            ->firstOrFail();

        $data = $request->validate([
            'account_code' => 'sometimes|string|max:20',
            'account_name' => 'sometimes|string|max:200',
            'account_type' => 'sometimes|in:asset,liability,equity,revenue,expense',
            'parent_id' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $model->update($data);

        return response()->json($model);
    }

    public function destroy(Request $request, string $id)
    {
        $model = ChartOfAccount::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where($this->routeKeyColumn(), $id)
            ->firstOrFail();

        $model->delete();

        return response()->json(null, 204);
    }

    protected function displayBalance(ChartOfAccount $account, $raw): float
    {
        if (! $raw) {
            return 0.0;
        }

        $debit = (float) $raw->total_debit;
        $credit = (float) $raw->total_credit;

        if (in_array($account->account_type, ['asset', 'expense'], true)) {
            return round($debit - $credit, 2);
        }

        return round($credit - $debit, 2);
    }
}
