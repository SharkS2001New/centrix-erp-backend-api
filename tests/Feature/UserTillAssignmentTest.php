<?php

namespace Tests\Feature;

use App\Models\Till;
use App\Models\User;
use App\Services\Pos\UserTillAssignmentService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class UserTillAssignmentTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $admin;

    protected UserTillAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('username', 'admin')->firstOrFail();
        $this->service = app(UserTillAssignmentService::class);
        Till::query()->update(['cashier_id' => null]);
    }

    protected function makeCashier(array $overrides = []): User
    {
        return User::create(array_merge([
            'organization_id' => $this->admin->organization_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->role_id,
            'username' => 'cashier_'.uniqid(),
            'email' => null,
            'password' => Hash::make('password'),
            'full_name' => 'Cashier Test',
            'is_admin' => false,
            'access_scope' => 'branch',
            'login_channels' => ['pos'],
            'is_active' => true,
        ], $overrides));
    }

    public function test_auto_till_assigns_first_free_till(): void
    {
        $cashier = $this->makeCashier();

        $till = Till::query()
            ->where('branch_id', $this->admin->branch_id)
            ->whereNull('cashier_id')
            ->orderBy('id')
            ->first();
        $this->assertNotNull($till);

        $assigned = $this->service->sync($cashier, 'auto');

        $this->assertNotNull($assigned);
        $this->assertSame((int) $till->id, (int) $assigned->id);
        $this->assertSame((int) $cashier->id, (int) $till->fresh()->cashier_id);
        $this->assertSame((int) $till->id, (int) $this->service->assignedTillId((int) $cashier->id));
    }

    public function test_second_cashier_auto_creates_next_till_when_first_taken(): void
    {
        $firstTill = Till::query()
            ->where('branch_id', $this->admin->branch_id)
            ->orderBy('id')
            ->firstOrFail();

        $cashierA = $this->makeCashier(['full_name' => 'Cashier A']);
        $cashierB = $this->makeCashier(['full_name' => 'Cashier B']);

        $this->service->sync($cashierA, (int) $firstTill->id);
        $assignedB = $this->service->sync($cashierB, 'auto');

        $this->assertNotNull($assignedB);
        $this->assertNotSame((int) $firstTill->id, (int) $assignedB->id);
        $this->assertSame((int) $cashierB->id, (int) $assignedB->cashier_id);
    }

    public function test_cannot_assign_till_owned_by_another_cashier(): void
    {
        $till = Till::query()
            ->where('branch_id', $this->admin->branch_id)
            ->firstOrFail();

        $owner = $this->makeCashier(['full_name' => 'Owner']);
        $other = $this->makeCashier(['full_name' => 'Other']);

        $this->service->sync($owner, (int) $till->id);

        $this->expectException(ValidationException::class);
        $this->service->sync($other, (int) $till->id);
    }

    public function test_auto_skips_locked_tills_and_uses_next_free_slot(): void
    {
        $branchId = (int) $this->admin->branch_id;
        $owner = $this->makeCashier(['full_name' => 'Locked Owner']);
        $firstTill = Till::query()->where('branch_id', $branchId)->orderBy('id')->firstOrFail();
        $this->service->sync($owner, (int) $firstTill->id);

        // Remove any other free tills so auto must create the next slot.
        Till::query()
            ->where('branch_id', $branchId)
            ->whereNull('cashier_id')
            ->delete();

        $other = $this->makeCashier(['full_name' => 'Auto Cashier']);
        $assigned = $this->service->sync($other, 'auto');

        $this->assertNotSame((int) $firstTill->id, (int) $assigned->id);
        $this->assertSame((int) $owner->id, (int) $firstTill->fresh()->cashier_id);
        $this->assertSame((int) $other->id, (int) $assigned->cashier_id);
        // Locked till must stay with owner — auto never steals it.
        $this->assertSame((int) $owner->id, (int) $firstTill->fresh()->cashier_id);
    }

    public function test_auto_fails_when_till01_to_till10_all_locked(): void
    {
        $branchId = (int) $this->admin->branch_id;

        // Lock every existing branch till, then fill remaining Till01–Till10 slots.
        $existing = Till::query()->where('branch_id', $branchId)->get();
        foreach ($existing as $till) {
            $cashier = $this->makeCashier(['full_name' => 'Lock '.$till->id]);
            $till->update(['cashier_id' => $cashier->id]);
        }

        $used = \App\Services\Pos\TillNumbering::usedNumbers($existing);
        for ($n = 1; $n <= 10; $n++) {
            if (isset($used[$n])) {
                continue;
            }
            $cashier = $this->makeCashier(['full_name' => "Cashier {$n}"]);
            $label = 'Till'.str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            Till::create([
                'organization_id' => $this->admin->organization_id,
                'branch_id' => $branchId,
                'till_number' => $label,
                'till_name' => $label,
                'is_active' => true,
                'cashier_id' => $cashier->id,
            ]);
        }

        $extra = $this->makeCashier(['full_name' => 'No Slot']);
        $this->expectException(ValidationException::class);
        $this->service->sync($extra, 'auto');
    }
}
