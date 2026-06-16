<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;

class OrganizationController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Organization::class;
    }

    protected function scopesByOrganization(): bool
    {
        return false;
    }

    protected function findScopedModel(Request $request, string $id): Organization
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->is_super_admin) {
            return Organization::query()->findOrFail($id);
        }

        if ((int) $id !== (int) $user->organization_id) {
            abort(403, 'You can only view your own organization.');
        }

        return Organization::query()->findOrFail($id);
    }

    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->is_super_admin) {
            return parent::index($request);
        }

        return response()->json(
            Organization::query()->where('id', $user->organization_id)->paginate(1),
        );
    }

    public function update(Request $request, string $id)
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->findScopedModel($request, $id);

        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $data = $request->validate($rules);

        if (! $user->is_super_admin) {
            unset($data['deployment_profile'], $data['enabled_modules']);
        }

        $oldValues = $model->getAttributes();
        $model->update($data);
        $model->refresh();

        if ($this->auditable()) {
            $this->auditLogger()->logModel(
                $user,
                'update',
                $model,
                $oldValues,
                $model->getAttributes(),
                $request,
            );
        }

        return response()->json($model);
    }
}
