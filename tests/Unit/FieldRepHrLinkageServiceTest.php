<?php

namespace Tests\Unit;

use App\Services\Attendance\FieldRepHrLinkageService;
use App\Models\Employee;
use App\Models\User;
use PHPUnit\Framework\TestCase;

class FieldRepHrLinkageServiceTest extends TestCase
{
    public function test_describe_user_link_when_no_employee(): void
    {
        $service = new FieldRepHrLinkageService(
            new \App\Services\Auth\UserAccessService(),
        );

        $user = new User([
            'id' => 10,
            'organization_id' => 1,
            'username' => 'rep1',
            'full_name' => 'Field Rep',
        ]);

        $link = $service->describeUserLink($user, null);

        $this->assertFalse($link['counts_toward_payroll']);
        $this->assertSame(FieldRepHrLinkageService::STATUS_NO_EMPLOYEE, $link['status']);
        $this->assertNotEmpty($link['hint']);
    }

    public function test_describe_user_link_when_inactive_employee(): void
    {
        $service = new FieldRepHrLinkageService(
            new \App\Services\Auth\UserAccessService(),
        );

        $user = new User(['id' => 10, 'organization_id' => 1]);
        $employee = new Employee();
        $employee->forceFill([
            'id' => 5,
            'full_name' => 'Inactive Rep',
            'is_active' => false,
            'employment_status' => 'terminated',
        ]);

        $link = $service->describeUserLink($user, $employee);

        $this->assertFalse($link['counts_toward_payroll']);
        $this->assertSame(FieldRepHrLinkageService::STATUS_INACTIVE_EMPLOYEE, $link['status']);
        $this->assertSame(5, $link['employee_id']);
    }

    public function test_describe_user_link_when_active_employee(): void
    {
        $service = new FieldRepHrLinkageService(
            new \App\Services\Auth\UserAccessService(),
        );

        $user = new User(['id' => 10, 'organization_id' => 1]);
        $employee = new Employee();
        $employee->forceFill([
            'id' => 5,
            'full_name' => 'Active Rep',
            'is_active' => true,
            'employment_status' => 'active',
        ]);

        $link = $service->describeUserLink($user, $employee);

        $this->assertTrue($link['counts_toward_payroll']);
        $this->assertSame(FieldRepHrLinkageService::STATUS_LINKED, $link['status']);
        $this->assertNull($link['hint']);
    }
}
