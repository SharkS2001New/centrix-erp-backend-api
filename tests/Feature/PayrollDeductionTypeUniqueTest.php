<?php

namespace Tests\Feature;

use App\Models\PlatformSubscription;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PayrollDeductionTypeUniqueTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        if ($this->user->organization_id) {
            PlatformSubscription::query()->firstOrCreate(
                ['organization_id' => $this->user->organization_id],
                [
                    'status' => 'active',
                    'current_period_start' => now()->subMonth()->toDateString(),
                    'current_period_end' => now()->addYear()->toDateString(),
                    'renewal_price' => 0,
                    'amount' => 0,
                    'currency' => 'KES',
                ],
            );
        }
        Sanctum::actingAs($this->user);
    }

    public function test_duplicate_deduction_code_returns_validation_error_instead_of_500(): void
    {
        $payload = [
            'deduction_code' => 'CASH-SHORT-DUP',
            'name' => 'cash shortage',
            'calc_type' => 'fixed',
            'default_amount' => 0,
            'is_active' => true,
            'applies_to_all' => false,
            'frequency' => 'per_cycle',
        ];

        $this->postJson('/api/v1/payroll-deduction-types', $payload)->assertCreated();

        $this->postJson('/api/v1/payroll-deduction-types', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['deduction_code']);
    }
}
