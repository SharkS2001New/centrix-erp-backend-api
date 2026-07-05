<?php

namespace Tests\Unit;

use App\Models\CustomerReturn;
use App\Models\Organization;
use App\Models\User;
use App\Services\Sales\CustomerReturnNumberAllocator;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CustomerReturnNumberAllocatorTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_next_sequence_is_scoped_per_organization(): void
    {
        $allocator = app(CustomerReturnNumberAllocator::class);
        $demoOrg = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $branchId = (int) $admin->branch_id;

        $secondOrg = Organization::create([
            'company_code' => 'RETORG2',
            'org_name' => 'Return Org Two',
            'org_email' => 'retorg2@test.com',
            'primary_tel' => '0700111222',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
        ]);

        $demoMax = (int) CustomerReturn::query()
            ->where('organization_id', $demoOrg->id)
            ->max('return_seq');

        DB::transaction(function () use ($allocator, $demoOrg, $secondOrg, $admin, $branchId, $demoMax) {
            $this->assertSame($demoMax + 1, $allocator->nextForOrganization((int) $demoOrg->id));
            $this->assertSame(1, $allocator->nextForOrganization((int) $secondOrg->id));

            CustomerReturn::create([
                'return_no' => $allocator->formatReturnNo(1),
                'return_seq' => 1,
                'organization_id' => $secondOrg->id,
                'branch_id' => $branchId,
                'return_date' => now()->toDateString(),
                'status' => 'pending',
                'total_amount' => 0,
                'returned_by' => $admin->id,
            ]);

            $this->assertSame(2, $allocator->nextForOrganization((int) $secondOrg->id));
        });
    }

    public function test_format_return_no_pads_sequence(): void
    {
        $allocator = app(CustomerReturnNumberAllocator::class);

        $this->assertSame('RET-0001', $allocator->formatReturnNo(1));
        $this->assertSame('RET-0042', $allocator->formatReturnNo(42));
    }
}
