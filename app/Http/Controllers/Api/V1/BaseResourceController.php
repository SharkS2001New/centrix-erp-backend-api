<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Auth\UserAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

abstract class BaseResourceController extends Controller
{
    abstract protected function modelClass(): string;

    protected function access(): UserAccessService
    {
        return app(UserAccessService::class);
    }

    protected function routeKeyColumn(): string
    {
        return 'id';
    }

    /** When true, list/show/update/delete are limited to the authenticated user's organization. */
    protected function scopesByOrganization(): bool
    {
        return true;
    }

    /** When true, branch-limited users only see rows for their branch (if the model has branch_id). */
    protected function scopesByBranch(): bool
    {
        return false;
    }

    protected function fillableFields(): array
    {
        return (new ($this->modelClass()))->getFillable();
    }

    protected function filterableColumns(): array
    {
        $cols = $this->fillableFields();
        $cols[] = 'id';
        $key = $this->routeKeyColumn();
        if ($key !== 'id' && ! in_array($key, $cols, true)) {
            $cols[] = $key;
        }

        return $cols;
    }

    protected function baseQuery(Request $request)
    {
        $query = ($this->modelClass())::query();
        $user = $request->user();

        if ($user && $this->scopesByOrganization() && in_array('organization_id', $this->fillableFields(), true)) {
            $this->access()->scopeOrganization($query, $user);
        }

        if ($user && $this->scopesByBranch() && in_array('branch_id', $this->fillableFields(), true)) {
            $this->access()->scopeBranchIfLimited($query, $user);
        }

        return $query;
    }

    protected function findScopedModel(Request $request, string $id): Model
    {
        return $this->baseQuery($request)
            ->where($this->routeKeyColumn(), $id)
            ->firstOrFail();
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request);
        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }
        if ($q = $request->input('q')) {
            $searchCol = $this->routeKeyColumn() !== 'id'
                ? $this->routeKeyColumn()
                : ($this->fillableFields()[0] ?? 'id');
            $query->where($searchCol, 'like', "%{$q}%");
        }
        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $data = $request->validate($rules);
        if ($request->user() && in_array('organization_id', $this->fillableFields(), true)) {
            $data['organization_id'] = $request->user()->organization_id;
        }
        $model = ($this->modelClass())::create($data);

        return response()->json($model, 201);
    }

    public function show(Request $request, string $id)
    {
        return response()->json($this->findScopedModel($request, $id));
    }

    public function update(Request $request, string $id)
    {
        $model = $this->findScopedModel($request, $id);
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $data = $request->validate($rules);
        unset($data['organization_id']);
        $model->update($data);

        return response()->json($model);
    }

    public function destroy(Request $request, string $id)
    {
        $this->findScopedModel($request, $id)->delete();

        return response()->json(null, 204);
    }
}
