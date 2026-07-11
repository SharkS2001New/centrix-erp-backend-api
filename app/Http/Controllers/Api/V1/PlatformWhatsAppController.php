<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\WhatsApp\WhatsAppPlatformPreviewService;
use App\Services\WhatsApp\WhatsAppSettingsResolver;
use Illuminate\Http\Request;

class PlatformWhatsAppController extends Controller
{
    public function __construct(
        protected WhatsAppPlatformPreviewService $preview,
    ) {}

    public function show()
    {
        return response()->json(WhatsAppSettingsResolver::describePlatform());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'webhook_verify_token' => 'sometimes|nullable|string|max:120',
            'graph_api_version' => 'sometimes|nullable|string|max:16',
        ]);

        return response()->json(WhatsAppSettingsResolver::savePlatform($data));
    }

    public function previewContext(Request $request)
    {
        $data = $request->validate([
            'organization_id' => 'required|integer|exists:organizations,id',
        ]);

        $organization = $this->tenantOrganization((int) $data['organization_id']);

        return response()->json($this->preview->context($organization, $request->user()));
    }

    public function previewCatalog(Request $request)
    {
        $data = $request->validate([
            'organization_id' => 'required|integer|exists:organizations,id',
            'customer_num' => 'nullable|string|max:64',
            'q' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1|max:100',
        ]);

        $organization = $this->tenantOrganization((int) $data['organization_id']);

        return response()->json(
            $this->preview->catalog(
                $organization,
                $data['customer_num'] ?? null,
                (string) ($data['q'] ?? ''),
                (int) ($data['page'] ?? 1),
                $request->user(),
            )
        );
    }

    public function previewSimulate(Request $request)
    {
        $data = $request->validate([
            'organization_id' => 'required|integer|exists:organizations,id',
            'message' => 'required|string|max:2000',
            'customer_num' => 'nullable|string|max:64',
            'phone' => 'nullable|string|max:40',
            'session_id' => 'nullable|string|max:64',
            'reset' => 'sometimes|boolean',
        ]);

        $organization = $this->tenantOrganization((int) $data['organization_id']);
        $actor = $request->user();

        if (! empty($data['reset'])) {
            $this->preview->resetSession(
                $actor?->id,
                $organization->id,
                $data['session_id'] ?? null,
                $data['customer_num'] ?? null,
                $data['phone'] ?? null,
            );
        }

        return response()->json(
            $this->preview->simulate(
                $organization,
                $data['message'],
                $data['customer_num'] ?? null,
                $data['phone'] ?? null,
                [
                    'session_id' => $data['session_id'] ?? null,
                ],
                $actor?->id,
                $actor,
            )
        );
    }

    protected function tenantOrganization(int $id): Organization
    {
        $organization = Organization::query()->findOrFail($id);
        $platformCode = (string) config('erp.platform_company_code', 'PLATFORM');
        if (strcasecmp((string) $organization->company_code, $platformCode) === 0) {
            abort(422, 'Choose a tenant organization to preview WhatsApp ordering.');
        }

        return $organization;
    }
}
