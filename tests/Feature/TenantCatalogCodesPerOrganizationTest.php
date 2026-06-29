<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Product;
use App\Models\RouteModel;
use App\Models\SubCategory;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\User;
use App\Models\Vat;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class TenantCatalogCodesPerOrganizationTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_two_organizations_can_share_product_code(): void
    {
        $template = $this->productTemplate();

        Product::create(array_merge($template, [
            'product_code' => 'PRD#0099',
            'organization_id' => Organization::where('company_code', 'DEMO')->value('id'),
        ]));

        $orgB = $this->createOrganization('CATORG2', 'Catalog Org Two');
        Product::create(array_merge($template, [
            'product_code' => 'PRD#0099',
            'organization_id' => $orgB->id,
        ]));

        $this->assertDatabaseHas('products', [
            'organization_id' => $orgB->id,
            'product_code' => 'PRD#0099',
        ]);
    }

    public function test_two_organizations_can_share_supplier_code(): void
    {
        $adminId = User::where('username', 'admin')->value('id');
        $orgA = Organization::where('company_code', 'DEMO')->firstOrFail();

        Supplier::create([
            'supplier_code' => 'SUP-010',
            'supplier_name' => 'Org A Supplier',
            'organization_id' => $orgA->id,
            'created_by' => $adminId,
        ]);

        $orgB = $this->createOrganization('CATORG3', 'Catalog Org Three');
        Supplier::create([
            'supplier_code' => 'SUP-010',
            'supplier_name' => 'Org B Supplier',
            'organization_id' => $orgB->id,
            'created_by' => $adminId,
        ]);

        $this->assertDatabaseHas('suppliers', [
            'organization_id' => $orgB->id,
            'supplier_code' => 'SUP-010',
        ]);
    }

    public function test_two_organizations_can_share_route_name(): void
    {
        $orgA = Organization::where('company_code', 'DEMO')->firstOrFail();
        RouteModel::create([
            'organization_id' => $orgA->id,
            'route_name' => 'North Route',
        ]);

        $orgB = $this->createOrganization('CATORG4', 'Catalog Org Four');
        RouteModel::create([
            'organization_id' => $orgB->id,
            'route_name' => 'North Route',
        ]);

        $this->assertDatabaseHas('routes', [
            'organization_id' => $orgB->id,
            'route_name' => 'North Route',
        ]);
    }

    public function test_route_name_must_be_unique_within_organization(): void
    {
        $orgA = Organization::where('company_code', 'DEMO')->firstOrFail();
        RouteModel::create([
            'organization_id' => $orgA->id,
            'route_name' => 'Duplicate Route',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        RouteModel::create([
            'organization_id' => $orgA->id,
            'route_name' => 'Duplicate Route',
        ]);
    }

    public function test_product_code_must_be_unique_within_organization(): void
    {
        $template = $this->productTemplate();
        $orgId = Organization::where('company_code', 'DEMO')->value('id');
        $template['organization_id'] = $orgId;

        Product::create(array_merge($template, ['product_code' => 'PRD#DUPE']));

        $this->expectException(UniqueConstraintViolationException::class);
        Product::create(array_merge($template, ['product_code' => 'PRD#DUPE']));
    }

    protected function createOrganization(string $companyCode, string $orgName): Organization
    {
        return Organization::create([
            'company_code' => $companyCode,
            'org_name' => $orgName,
            'org_email' => strtolower($companyCode).'@test.com',
            'primary_tel' => '0700444333',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
        ]);
    }

    /** @return array<string, mixed> */
    protected function productTemplate(): array
    {
        $sub = SubCategory::firstOrFail();
        $uom = Uom::firstOrFail();
        $vat = Vat::firstOrFail();

        return [
            'product_name' => 'Shared Code Product',
            'subcategory_id' => $sub->id,
            'unit_id' => $uom->id,
            'unit_price' => 100,
            'vat_id' => $vat->id,
            'branch_id' => Branch::first()->id,
        ];
    }
}
