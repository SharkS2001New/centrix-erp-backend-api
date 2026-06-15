<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ReportBuilderTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_builder_schema_is_available(): void
    {
        $this->getJson('/api/v1/reports/builder/schema')
            ->assertOk()
            ->assertJsonStructure(['sources', 'aggregates', 'chart_types']);
    }

    public function test_can_save_preview_and_run_custom_report(): void
    {
        $spec = [
            'source' => 'sales',
            'columns' => [
                ['field' => 'channel', 'label' => 'Channel'],
                ['field' => 'order_total', 'label' => 'Total', 'aggregate' => 'sum', 'alias' => 'total_sales'],
            ],
            'group_by' => ['channel'],
            'sort' => ['field' => 'total_sales', 'direction' => 'desc'],
        ];

        $this->postJson('/api/v1/reports/builder/preview', [
            'spec' => $spec,
            'per_page' => 10,
        ])->assertOk()->assertJsonStructure(['data']);

        $create = $this->postJson('/api/v1/reports/builder/templates', [
            'name' => 'Sales by channel (custom)',
            'description' => 'Test template',
            'spec' => $spec,
            'is_shared' => true,
        ])->assertCreated();

        $id = $create->json('id');
        $this->assertNotNull($id);

        $this->getJson("/api/v1/reports/builder/templates/{$id}/run?per_page=5")
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->getJson("/api/v1/reports/builder/templates/{$id}")
            ->assertOk()
            ->assertJsonPath('definition.title', 'Sales by channel (custom)');
    }
}
