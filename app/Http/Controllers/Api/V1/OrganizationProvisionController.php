<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OrganizationProvisioningService;
use Illuminate\Http\Request;

class OrganizationProvisionController extends Controller
{
    public function __construct(
        protected OrganizationProvisioningService $provisioning,
    ) {}

    /** POST /api/v1/admin/organizations/provision */
    public function store(Request $request)
    {
        $data = $request->validate([
            'company_code' => 'required|string|max:45|unique:organizations,company_code',
            'org_name' => 'required|string|max:200',
            'org_email' => 'required|email|max:200',
            'primary_tel' => 'required|string|max:45',
            'org_address' => 'required|string|max:400',
            'org_pin' => 'nullable|string|max:45',
            'vat_regno' => 'nullable|string|max:50',
            'deployment_profile' => 'required|in:small_shop,wholesale_retail,distribution',
            'admin_username' => 'required|string|max:50',
            'admin_email' => 'required|email|max:255',
            'admin_password' => 'required|string|min:6',
            'admin_full_name' => 'required|string|max:200',
        ]);

        $result = $this->provisioning->provision($data);

        return response()->json([
            'organization' => $result['organization'],
            'manager' => $result['manager'],
            'branch' => $result['branch'],
            'message' => 'Organization created. The manager can sign in with their username and password.',
        ], 201);
    }
}
