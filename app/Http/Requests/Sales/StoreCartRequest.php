<?php

namespace App\Http\Requests\Sales;

use App\Services\Auth\UserAccessService;
use App\Support\TenantRouteRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $orgId = app(UserAccessService::class)->organizationId($this->user(), $this);

        return [
            'channel' => 'required|in:pos,mobile,backend,whatsapp',
            'order_source' => 'nullable|in:pos,mobile,backoffice,backend,whatsapp',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'till_id' => 'nullable|integer|exists:tills,id',
            'float_session_id' => 'nullable|integer|min:1',
            'route_id' => TenantRouteRules::nullable($orgId),
        ];
    }
}
