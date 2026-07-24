<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\FindsOrganizationEmployee;
use App\Http\Controllers\Controller;
use App\Services\Auth\UserAccessService;
use Illuminate\Http\Request;

abstract class HrOrgResourceController extends Controller
{
    use FindsOrganizationEmployee;

    abstract protected function modelClass(): string;

    /** @return list<string> */
    protected function filterableColumns(): array
    {
        $cols = (new ($this->modelClass()))->getFillable();
        $cols[] = 'id';

        return $cols;
    }

    protected function modelHasColumn(string $column): bool
    {
        return in_array($column, (new ($this->modelClass()))->getFillable(), true);
    }

    protected function access(): UserAccessService
    {
        return app(UserAccessService::class);
    }

    public function index(Request $request)
    {
        $query = ($this->modelClass())::query();
        $user = $request->user();

        if ($user && $this->modelHasColumn('organization_id')) {
            $this->access()->scopeOrganization($query, $user, 'organization_id', $request);
        }

        if ($user && $this->modelHasColumn('branch_id')) {
            $this->access()->applyBranchListFilter($query, $user, $request);
        }

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if ($col === 'branch_id') {
                continue;
            }
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($q = $request->input('q')) {
            $this->applySearch($query, $q);
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $user = $request->user();
        if ($user && $this->modelHasColumn('organization_id') && empty($data['organization_id'])) {
            $data['organization_id'] = $user->organization_id;
        }
        if ($user && $this->modelHasColumn('branch_id')) {
            $this->applyBranchScopeToWriteData($user, $data, $request);
        }
        $model = ($this->modelClass())::create($data);

        return response()->json($model, 201);
    }

    public function show(string $id)
    {
        return response()->json($this->findScoped($id));
    }

    public function update(Request $request, string $id)
    {
        $model = $this->findScoped($id);
        $data = $this->validated($request, updating: true);
        $user = $request->user();
        if ($user && $this->modelHasColumn('branch_id')) {
            $this->applyBranchScopeToWriteData($user, $data, $request);
        }
        $model->update($data);

        return response()->json($model->fresh());
    }

    public function destroy(string $id)
    {
        $this->findScoped($id)->delete();

        return response()->json(null, 204);
    }

    protected function findScoped(string $id)
    {
        $query = ($this->modelClass())::query()->where('id', (int) $id);
        $user = request()->user();
        if ($user && $this->modelHasColumn('organization_id')) {
            $this->access()->scopeOrganization($query, $user, 'organization_id', request());
        }
        if ($user && $this->modelHasColumn('branch_id')) {
            $this->access()->scopeBranchIfLimited($query, $user);
        }

        return $query->firstOrFail();
    }

    /** @param  array<string, mixed>  $data */
    protected function applyBranchScopeToWriteData($user, array &$data, ?Request $request = null): void
    {
        if (array_key_exists('branch_id', $data) && $data['branch_id'] !== null) {
            $this->access()->assertBranchInOrganization($user, (int) $data['branch_id'], $request);
        }

        $limitedBranch = $this->access()->branchId($user);
        if ($limitedBranch === null) {
            return;
        }

        if (array_key_exists('branch_id', $data) && $data['branch_id'] !== null
            && (int) $data['branch_id'] !== $limitedBranch) {
            abort(403, 'You can only operate within your assigned branch.');
        }

        $data['branch_id'] = $limitedBranch;
    }

    /** @param  \Illuminate\Database\Eloquent\Builder  $query */
    protected function applySearch($query, string $q): void
    {
        $query->where('name', 'like', "%{$q}%");
    }

    /** @return array<string, mixed> */
    abstract protected function validated(Request $request, bool $updating = false): array;
}
