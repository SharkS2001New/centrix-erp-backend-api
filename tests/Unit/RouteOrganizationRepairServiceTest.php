<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\RouteModel;
use App\Services\Organization\RouteOrganizationRepairService;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class RouteOrganizationRepairServiceTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_letter_routes_move_to_letter_org_and_others_to_default_org(): void
    {
        $letterOrg = $this->makeOrg('Letter Org');
        $defaultOrg = $this->makeOrg('Default Org');

        $routeA = RouteModel::create([
            'organization_id' => $defaultOrg->id,
            'route_name' => 'A',
            'route_markup_price' => 0,
            'direction' => 'north',
            'is_active' => true,
        ]);
        $routeNorth = RouteModel::create([
            'organization_id' => $letterOrg->id,
            'route_name' => 'North',
            'route_markup_price' => 0,
            'direction' => 'north',
            'is_active' => true,
        ]);

        $service = app(RouteOrganizationRepairService::class);
        $stats = $service->run($letterOrg->id, $defaultOrg->id, false);

        $this->assertSame(2, $stats['routes_reassigned']);
        $this->assertSame($letterOrg->id, RouteModel::findOrFail($routeA->id)->organization_id);
        $this->assertSame($defaultOrg->id, RouteModel::findOrFail($routeNorth->id)->organization_id);
    }

    public function test_route_customers_align_to_route_organization_and_head_office_branch(): void
    {
        $letterOrg = $this->makeOrg('Letter Org 2');
        $defaultOrg = $this->makeOrg('Default Org 2');

        $letterBranch = Branch::create([
            'organization_id' => $letterOrg->id,
            'branch_code' => 'HQ',
            'branch_name' => 'Head Office',
            'branch_type' => 'retail',
        ]);
        $defaultBranch = Branch::create([
            'organization_id' => $defaultOrg->id,
            'branch_code' => 'HQ',
            'branch_name' => 'Head Office',
            'branch_type' => 'retail',
        ]);

        $routeB = RouteModel::create([
            'organization_id' => $defaultOrg->id,
            'route_name' => 'B',
            'route_markup_price' => 0,
            'direction' => 'east',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'organization_id' => $defaultOrg->id,
            'branch_id' => $defaultBranch->id,
            'customer_num' => 900001,
            'customer_name' => 'Route Customer',
            'customer_type' => 'debtor',
            'route_id' => $routeB->id,
            'created_by' => \App\Models\User::where('username', 'admin')->value('id'),
        ]);

        app(RouteOrganizationRepairService::class)->run($letterOrg->id, $defaultOrg->id, true);

        $customer->refresh();
        $this->assertSame($letterOrg->id, (int) $customer->organization_id);
        $this->assertSame($letterBranch->id, (int) $customer->branch_id);
        $this->assertSame($letterOrg->id, (int) RouteModel::findOrFail($routeB->id)->organization_id);
    }

    public function test_is_letter_route_name_matches_only_single_letters_a_through_e(): void
    {
        $service = app(RouteOrganizationRepairService::class);

        $this->assertTrue($service->isLetterRouteName('A'));
        $this->assertTrue($service->isLetterRouteName('e'));
        $this->assertFalse($service->isLetterRouteName('F'));
        $this->assertFalse($service->isLetterRouteName('AB'));
        $this->assertFalse($service->isLetterRouteName('Route A'));
    }

    protected function makeOrg(string $name): Organization
    {
        $admin = \App\Models\User::where('username', 'admin')->firstOrFail();
        $template = Organization::findOrFail($admin->organization_id);

        return Organization::create([
            'company_code' => strtoupper(substr(preg_replace('/\s+/', '', $name), 0, 6)).substr(uniqid(), -3),
            'org_name' => $name,
            'org_email' => strtolower(str_replace(' ', '-', $name)).'@example.com',
            'primary_tel' => '0700'.random_int(100000, 999999),
            'org_address' => 'Nairobi',
            'deployment_profile' => $template->deployment_profile,
            'enabled_modules' => $template->enabled_modules,
            'module_settings' => $template->module_settings,
        ]);
    }
}
