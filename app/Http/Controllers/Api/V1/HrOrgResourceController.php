<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\FindsOrganizationEmployee;
use App\Http\Controllers\Controller;
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

    public function index(Request $request)
    {
        $query = ($this->modelClass())::query();

        if ($orgId = $request->user()?->organization_id) {
            if (in_array('organization_id', (new ($this->modelClass()))->getFillable(), true)) {
                $query->where('organization_id', $orgId);
            }
        }

        if ($request->user() && in_array('branch_id', (new ($this->modelClass()))->getFillable(), true)) {
            app(\App\Services\Auth\UserAccessService::class)
                ->applyBranchListFilter($query, $request->user(), $request);
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
        if ($request->user()?->organization_id && empty($data['organization_id'])
            && in_array('organization_id', (new ($this->modelClass()))->getFillable(), true)) {
            $data['organization_id'] = $request->user()->organization_id;
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
        $model->update($this->validated($request, updating: true));

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
        if ($orgId = request()->user()?->organization_id) {
            $query->where('organization_id', $orgId);
        }

        return $query->firstOrFail();
    }

    /** @param  \Illuminate\Database\Eloquent\Builder  $query */
    protected function applySearch($query, string $q): void
    {
        $query->where('name', 'like', "%{$q}%");
    }

    /** @return array<string, mixed> */
    abstract protected function validated(Request $request, bool $updating = false): array;
}
