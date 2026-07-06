<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\UserAccessService;
use App\Support\ReferentialIntegrityMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

abstract class BaseResourceController extends Controller
{
    abstract protected function modelClass(): string;

    protected function access(): UserAccessService
    {
        return app(UserAccessService::class);
    }

    protected function routeKeyColumn(): string
    {
        return $this->modelKeyName();
    }

    protected function modelKeyName(): string
    {
        return (new ($this->modelClass()))->getKeyName();
    }

    protected function defaultListOrderColumn(): ?string
    {
        return $this->routeKeyColumn();
    }

    protected function defaultListOrderDirection(): string
    {
        return 'desc';
    }

    /** When true, list/show/update/delete are limited to the authenticated user's organization. */
    protected function scopesByOrganization(): bool
    {
        return true;
    }

    /** When true, branch-limited users only see rows for their branch (if the model has branch_id). */
    protected function scopesByBranch(): bool
    {
        return in_array('branch_id', $this->fillableFields(), true);
    }

    protected function auditable(): bool
    {
        return $this->modelClass() !== AuditLog::class;
    }

    protected function auditLogger(): AuditLogger
    {
        return app(AuditLogger::class);
    }

    protected function fillableFields(): array
    {
        return (new ($this->modelClass()))->getFillable();
    }

    protected function filterableColumns(): array
    {
        $cols = $this->fillableFields();
        $key = $this->routeKeyColumn();
        if (! in_array($key, $cols, true)) {
            $cols[] = $key;
        }

        return $cols;
    }

    /** @return list<string> */
    protected function searchColumns(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function sortableColumns(): array
    {
        return [];
    }

    protected function applyListOrdering(
        Request $request,
        $query,
        ?string $defaultColumn = null,
        string $defaultDirection = 'desc',
    ): void {
        $allowed = $this->sortableColumns();
        $sort = (string) $request->input('sort', '');
        $direction = strtolower((string) $request->input('sort_dir', '')) === 'desc' ? 'desc' : 'asc';

        if ($sort !== '' && in_array($sort, $allowed, true)) {
            $query->orderBy($sort, $direction);

            return;
        }

        if ($defaultColumn !== null) {
            $query->orderBy($defaultColumn, $defaultDirection === 'asc' ? 'asc' : 'desc');
        }
    }

    protected function baseQuery(Request $request)
    {
        $query = ($this->modelClass())::query();
        $user = $request->user();

        if (! $user) {
            return $query;
        }

        $fillable = $this->fillableFields();
        $hasOrganization = in_array('organization_id', $fillable, true);
        $hasBranch = in_array('branch_id', $fillable, true);

        if ($this->scopesByOrganization()) {
            if ($hasOrganization) {
                $this->access()->scopeOrganization($query, $user, 'organization_id', $request);
            } elseif ($hasBranch) {
                $this->access()->scopeOrganizationViaBranch($query, $user, 'branch_id', $request);
            }
        }

        if ($this->scopesByBranch() && $hasBranch) {
            $this->access()->scopeBranchIfLimited($query, $user);
        }

        return $query;
    }

    /**
     * Resolve the resource id for show/update/destroy. Nested platform routes under
     * admin/organizations/{organization}/... pass the organization id first.
     */
    protected function resolveResourceId(string $id, ?string $nestedId = null): string
    {
        return $nestedId ?? $id;
    }

    protected function findScopedModel(Request $request, string $id, ?string $nestedId = null): Model
    {
        $resourceId = $this->resolveResourceId($id, $nestedId);

        return $this->baseQuery($request)
            ->where($this->routeKeyColumn(), $resourceId)
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
        if ($q = trim((string) $request->input('q', ''))) {
            $columns = $this->searchColumns();
            if ($columns !== []) {
                $query->where(function ($inner) use ($columns, $q) {
                    foreach ($columns as $index => $col) {
                        if ($index === 0) {
                            $inner->where($col, 'like', "%{$q}%");
                        } else {
                            $inner->orWhere($col, 'like', "%{$q}%");
                        }
                    }
                });
            } else {
                $searchCol = $this->routeKeyColumn() !== 'id'
                    ? $this->routeKeyColumn()
                    : ($this->fillableFields()[0] ?? 'id');
                $query->where($searchCol, 'like', "%{$q}%");
            }
        }
        $this->applyCreatedAtDateRange($query, $request);
        $perPage = min((int) $request->input('per_page', 25), 200);
        $this->applyListOrdering(
            $request,
            $query,
            $this->defaultListOrderColumn(),
            $this->defaultListOrderDirection(),
        );

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $data = $request->validate($rules);
        $user = $request->user();
        if ($user && in_array('organization_id', $this->fillableFields(), true)) {
            $orgId = $this->access()->organizationId($user, $request);
            if ($orgId) {
                $data['organization_id'] = $orgId;
            }
        }
        if ($user) {
            $this->applyBranchScopeToWriteData($user, $data, $request);
        }
        $model = ($this->modelClass())::create($data);

        if ($user && $this->auditable()) {
            $this->auditLogger()->logModel($user, 'create', $model, request: $request);
        }

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
        if ($request->user()) {
            $this->applyBranchScopeToWriteData($request->user(), $data, $request);
        }
        $oldValues = $model->getAttributes();
        $model->update($data);
        $model->refresh();

        if ($request->user() && $this->auditable()) {
            $this->auditLogger()->logModel(
                $request->user(),
                'update',
                $model,
                $oldValues,
                $model->getAttributes(),
                $request,
            );
        }

        return response()->json($model);
    }

    public function destroy(Request $request, string $id)
    {
        $model = $this->findScopedModel($request, $id);
        $user = $request->user();

        if ($user && $this->auditable()) {
            $this->auditLogger()->logModel(
                $user,
                'delete',
                $model,
                $model->getAttributes(),
                null,
                $request,
            );
        }

        try {
            $model->delete();
        } catch (QueryException $e) {
            $message = ReferentialIntegrityMessage::forDelete($e);
            if ($message !== null) {
                throw ValidationException::withMessages([
                    'record' => [$message],
                ]);
            }

            throw $e;
        }

        return response()->json(null, 204);
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<mixed>  $query */
    protected function applyCreatedAtDateRange($query, Request $request): void
    {
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }
    }

    /** @param  array<string, mixed>  $data */
    protected function applyBranchScopeToWriteData(User $user, array &$data, ?Request $request = null): void
    {
        if (in_array('branch_id', $this->fillableFields(), true)
            && array_key_exists('branch_id', $data)
            && $data['branch_id'] !== null) {
            $this->access()->assertBranchInOrganization($user, (int) $data['branch_id'], $request);
        }

        if (! in_array('branch_id', $this->fillableFields(), true)) {
            return;
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
}
