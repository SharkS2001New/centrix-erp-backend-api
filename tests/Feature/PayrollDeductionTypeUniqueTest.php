<?php

namespace Tests\Feature;

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

        $first = $this->postJson('/api/v1/payroll-deduction-types', $payload);
        if ($first->status() !== 201) {
            file_put_contents(
                '/tmp/payroll-deduction-unique-fail.json',
                json_encode([
                    'status' => $first->status(),
                    'json' => $first->json(),
                    'body' => $first->getContent(),
                ], JSON_PRETTY_PRINT),
            );
        }
        $first->assertCreated();

        $this->postJson('/api/v1/payroll-deduction-types', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['deduction_code']);
    }
}
