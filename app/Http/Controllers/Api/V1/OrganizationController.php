<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

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

    public function show(Request $request, string $id)
    {
        $model = $this->findScopedModel($request, $id);

        return response()->json([
            'organization' => $this->formatOrganization($model),
        ]);
    }

    /** GET /api/v1/erp/organization/profile — current tenant company profile */
    public function currentProfile(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $orgId = (int) ($user->organization_id ?? 0);

        if ($orgId <= 0) {
            abort(403, 'No organization context.');
        }

        $model = $this->findScopedModel($request, (string) $orgId);

        return response()->json([
            'organization' => $this->formatOrganization($model),
        ]);
    }

    /** PATCH /api/v1/erp/organization/profile — update current tenant company profile */
    public function updateCurrentProfile(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $orgId = (int) ($user->organization_id ?? 0);

        if ($orgId <= 0) {
            abort(403, 'No organization context.');
        }

        return $this->update($request, (string) $orgId);
    }

    public function update(Request $request, string $id)
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->findScopedModel($request, $id);

        if ($user->is_super_admin) {
            $rules = array_fill_keys(Organization::tenantManagedAttributes(), 'nullable');
            $rules['org_name'] = 'sometimes|string|max:200';
            $rules['primary_tel'] = 'sometimes|string|max:45';
            $rules['org_address'] = 'sometimes|string|max:400';
        } else {
            $rules = [
                'org_name' => 'sometimes|string|max:200',
                'primary_tel' => 'sometimes|string|max:45',
                'secondary_tel' => 'nullable|string|max:45',
                'addn_tel1' => 'nullable|string|max:45',
                'addn_tel2' => 'nullable|string|max:45',
                'org_address' => 'sometimes|string|max:400',
                'org_pin' => 'nullable|string|max:45',
                'vat_regno' => 'nullable|string|max:50',
            ];
        }

        $data = $request->validate($rules);

        foreach (array_merge(
            Organization::immutableAttributes(),
            ['logo'],
            $user->is_super_admin ? [] : Organization::platformControlledAttributes(),
        ) as $field) {
            unset($data[$field]);
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

        return response()->json([
            'organization' => $this->formatOrganization($model),
        ]);
    }

    /** POST /organizations/{id}/logo */
    public function uploadLogo(Request $request, string $id)
    {
        $model = $this->findScopedModel($request, $id);

        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:2048',
        ]);

        if (Organization::logoIsStoredFile($model->logo)) {
            Storage::disk('public')->delete($model->logo);
        }

        $path = $request->file('image')->store('organizations/'.$model->id, 'public');
        $model->update(['logo' => $path]);

        return response()->json($this->formatOrganization($model->fresh()));
    }

    /** GET /organizations/{id}/logo/file */
    public function logoFile(Request $request, string $id)
    {
        $model = $this->findScopedModel($request, $id);

        if (! Organization::logoIsStoredFile($model->logo)
            || ! Storage::disk('public')->exists($model->logo)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $absolute = Storage::disk('public')->path($model->logo);
        $mime = Storage::disk('public')->mimeType($model->logo) ?: 'image/png';

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** DELETE /organizations/{id}/logo */
    public function deleteLogo(Request $request, string $id)
    {
        $model = $this->findScopedModel($request, $id);

        if (Organization::logoIsStoredFile($model->logo)) {
            Storage::disk('public')->delete($model->logo);
        }

        $model->update(['logo' => null]);

        return response()->json($this->formatOrganization($model->fresh()));
    }

    /** @return array<string, mixed> */
    protected function formatOrganization(Organization $org): array
    {
        return $org->toProfileArray();
    }
}
