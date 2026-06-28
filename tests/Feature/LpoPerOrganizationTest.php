<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LpoMst;
use App\Models\Organization;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\LpoNumberAllocator;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class LpoPerOrganizationTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_lpo_sequence_starts_at_one_for_each_organization(): void
    {
        $demoOrg = Organization::where('company_code', 'DEMO')->firstOrFail();
        $secondOrg = $this->createOrganizationWithSupplier('LPORG2', 'LPO Org Two');

        $allocator = app(LpoNumberAllocator::class);
        $demoMax = (int) LpoMst::query()->where('organization_id', $demoOrg->id)->max('lpo_seq');

        $this->assertSame($demoMax + 1, $allocator->nextForOrganization((int) $demoOrg->id));
        $this->assertSame(1, $allocator->nextForOrganization((int) $secondOrg['organization']->id));
    }

    public function test_two_organizations_can_both_have_lpo_seq_one(): void
    {
        $demoOrg = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $demoSupplier = Supplier::query()->where('organization_id', $demoOrg->id)->firstOrFail();

        LpoMst::create([
            'organization_id' => $demoOrg->id,
            'lpo_seq' => 1,
            'supplier_id' => $demoSupplier->id,
            'lpo_status_code' => 1,
            'total_amount' => 1000,
            'net_amount' => 1000,
            'created_by' => $admin->id,
            'created_at' => now(),
        ]);

        $secondOrg = $this->createOrganizationWithSupplier('LPORG3', 'LPO Org Three');

        LpoMst::create([
            'organization_id' => $secondOrg['organization']->id,
            'lpo_seq' => 1,
            'supplier_id' => $secondOrg['supplier']->id,
            'lpo_status_code' => 1,
            'total_amount' => 500,
            'net_amount' => 500,
            'created_by' => $admin->id,
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('lpo_mst', [
            'organization_id' => $demoOrg->id,
            'lpo_seq' => 1,
        ]);
        $this->assertDatabaseHas('lpo_mst', [
            'organization_id' => $secondOrg['organization']->id,
            'lpo_seq' => 1,
        ]);
    }

    public function test_po_number_display_uses_lpo_seq_not_global_lpo_no(): void
    {
        $module = app(\App\Services\LpoModuleService::class);

        $this->assertSame('LPO-2026-0001', $module->formatPoNumber(1));
        $this->assertSame('LPO-2026-0042', $module->formatPoNumber(42));
    }

    /**
     * @return array{organization: Organization, branch: Branch, supplier: Supplier}
     */
    protected function createOrganizationWithSupplier(string $companyCode, string $orgName): array
    {
        $organization = Organization::create([
            'company_code' => $companyCode,
            'org_name' => $orgName,
            'org_email' => strtolower($companyCode).'@test.com',
            'primary_tel' => '0700888777',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
        ]);

        $branch = Branch::create([
            'organization_id' => $organization->id,
            'branch_code' => 'HQ',
            'branch_name' => 'Head Office',
            'branch_type' => 'retail',
        ]);

        $supplier = Supplier::create([
            'supplier_code' => $companyCode.'-SUP',
            'supplier_name' => $orgName.' Supplier',
            'organization_id' => $organization->id,
            'created_by' => User::where('username', 'admin')->value('id'),
        ]);

        return compact('organization', 'branch', 'supplier');
    }
}
