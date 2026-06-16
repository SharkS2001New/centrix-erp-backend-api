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

    /** @return array<string, mixed> */
    protected function reportSources(): array
    {
        return config('report_builder.sources', []);
    }

    protected function reportSourceCount(): int
    {
        return count($this->reportSources());
    }

    public function test_builder_schema_is_available(): void
    {
        $response = $this->getJson('/api/v1/reports/builder/schema')
            ->assertOk()
            ->assertJsonStructure(['sources', 'modules', 'aggregates', 'chart_types']);

        $sources = $response->json('sources');
        $this->assertCount($this->reportSourceCount(), $sources);
        $this->assertNull($response->json('max_columns'));
        $this->assertNull($response->json('max_group_by'));

        $modules = collect($sources)->pluck('module')->unique()->values()->all();
        $this->assertContains('Sales', $modules);
        $this->assertContains('Inventory', $modules);
        $this->assertContains('HR', $modules);
    }

    public function test_builder_sources_catalog_is_grouped_by_module(): void
    {
        $response = $this->getJson('/api/v1/reports/builder/sources')
            ->assertOk()
            ->assertJsonStructure(['modules', 'source_count', 'sources_by_module']);

        $this->assertSame($this->reportSourceCount(), $response->json('source_count'));
        $this->assertArrayHasKey('Sales', $response->json('sources_by_module'));
    }

    public function test_no_column_limit_on_custom_report(): void
    {
        $columns = [];
        for ($i = 0; $i < 15; $i++) {
            $columns[] = ['field' => 'channel', 'label' => "Channel {$i}", 'alias' => "channel_{$i}"];
        }
        $columns[] = ['field' => 'order_total', 'label' => 'Total', 'aggregate' => 'sum', 'alias' => 'total_sales'];

        $spec = [
            'source' => 'sales',
            'columns' => $columns,
            'group_by' => ['channel', 'sale_day', 'branch_name', 'payment_status', 'order_num'],
        ];

        $this->postJson('/api/v1/reports/builder/preview', [
            'spec' => $spec,
            'per_page' => 5,
        ])->assertOk();
    }

    public function test_can_preview_cross_module_sources(): void
    {
        $specs = [
            [
                'source' => 'products',
                'columns' => [
                    ['field' => 'product_name', 'label' => 'Product'],
                    ['field' => 'unit_price', 'label' => 'Price', 'aggregate' => 'avg', 'alias' => 'avg_price'],
                ],
                'group_by' => ['product_name'],
            ],
            [
                'source' => 'expenses',
                'columns' => [
                    ['field' => 'expense_group', 'label' => 'Group'],
                    ['field' => 'expense_amount', 'label' => 'Total', 'aggregate' => 'sum', 'alias' => 'total'],
                ],
                'group_by' => ['expense_group'],
            ],
            [
                'source' => 'employees',
                'columns' => [
                    ['field' => 'department_name', 'label' => 'Department'],
                    ['field' => 'employee_count', 'label' => 'Headcount', 'aggregate' => 'count', 'alias' => 'headcount'],
                ],
                'group_by' => ['department_name'],
            ],
            [
                'source' => 'dispatch_trips',
                'columns' => [
                    ['field' => 'status', 'label' => 'Status'],
                    ['field' => 'trip_count', 'label' => 'Trips', 'aggregate' => 'count', 'alias' => 'trips'],
                ],
                'group_by' => ['status'],
            ],
            [
                'source' => 'till_sessions',
                'columns' => [
                    ['field' => 'cashier', 'label' => 'Cashier'],
                    ['field' => 'cash_sales', 'label' => 'Cash sales', 'aggregate' => 'sum', 'alias' => 'cash'],
                ],
                'group_by' => ['cashier'],
            ],
        ];

        foreach ($specs as $spec) {
            if (! isset($this->reportSources()[$spec['source']])) {
                continue;
            }

            $this->postJson('/api/v1/reports/builder/preview', [
                'spec' => $spec,
                'per_page' => 5,
            ])->assertOk()->assertJsonStructure(['data']);
        }
    }

    public function test_can_save_unlimited_report_templates(): void
    {
        $spec = [
            'source' => 'sales',
            'columns' => [
                ['field' => 'channel', 'label' => 'Channel'],
                ['field' => 'order_total', 'label' => 'Total', 'aggregate' => 'sum', 'alias' => 'total_sales'],
            ],
            'group_by' => ['channel'],
        ];

        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/v1/reports/builder/templates', [
                'name' => "Sales by channel {$i}",
                'description' => 'Test template',
                'spec' => $spec,
                'is_shared' => true,
            ])->assertCreated();
        }

        $this->getJson('/api/v1/reports/builder/templates')
            ->assertOk()
            ->assertJsonCount(3, 'data');
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
