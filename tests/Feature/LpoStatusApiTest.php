<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class LpoStatusApiTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_lpo_statuses_index_orders_by_status_code_not_id(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/lpo-statuses?per_page=50')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    ['status_code', 'status_name'],
                ],
            ])
            ->assertJsonPath('data.0.status_code', 1);
    }
}
