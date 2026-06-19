<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Reports\ReportBuilderService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ReportBuilderTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected ReportBuilderService $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
        $this->builder = app(ReportBuilderService::class);
    }

    /** @return array<string, mixed> */
    protected function reportSources(): array
    {
        return config('report_builder.sources', []);
    }

    protected function allowedSourceCount(?string $workspaceId): int
    {
        return count($this->builder->allowedSourceKeys($workspaceId));
    }

    public function test_builder_schema_is_scoped_to_backoffice_by_default(): void
    {
        $response = $this->getJson('/api/v1/reports/builder/schema')
            ->assertOk()
            ->assertJsonStructure(['workspace_id', 'sources', 'modules', 'aggregates', 'chart_types']);

        $sources = $response->json('sources');
        $this->assertSame('backoffice', $response->json('workspace_id'));
        $this->assertCount($this->allowedSourceCount('backoffice'), $sources);
        $this->assertNull($response->json('max_columns'));
        $this->assertNull($response->json('max_group_by'));

        $modules = collect($sources)->pluck('module')->unique()->values()->all();
        $this->assertContains('Sales', $modules);
        $this->assertContains('Inventory', $modules);
        $this->assertNotContains('HR', $modules);
        $this->assertNotContains('Accounting', $modules);
    }

    public function test_builder_schema_is_scoped_to_workspace(): void
    {
        $hrResponse = $this->getJson('/api/v1/reports/builder/schema?workspace_id=hr')
            ->assertOk();

        $this->assertSame('hr', $hrResponse->json('workspace_id'));
        $this->assertCount($this->allowedSourceCount('hr'), $hrResponse->json('sources'));

        $modules = collect($hrResponse->json('sources'))->pluck('module')->unique()->values()->all();
        $this->assertSame(['HR'], $modules);

        $accountingResponse = $this->getJson('/api/v1/reports/builder/schema?workspace_id=accounting')
            ->assertOk();

        $this->assertSame('accounting', $accountingResponse->json('workspace_id'));
        $accountingModules = collect($accountingResponse->json('sources'))->pluck('module')->unique()->values()->all();
        $this->assertSame(['Accounting'], $accountingModules);
    }

    public function test_builder_sources_catalog_is_grouped_by_module(): void
    {
        $response = $this->getJson('/api/v1/reports/builder/sources?workspace_id=backoffice')
            ->assertOk()
            ->assertJsonStructure(['workspace_id', 'modules', 'source_count', 'sources_by_module']);

        $this->assertSame('backoffice', $response->json('workspace_id'));
        $this->assertSame($this->allowedSourceCount('backoffice'), $response->json('source_count'));
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
            'workspace_id' => 'backoffice',
        ])->assertOk();
    }

    public function test_backoffice_can_preview_operations_sources(): void
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
                'source' => 'lpo_orders',
                'columns' => [
                    ['field' => 'supplier_name', 'label' => 'Supplier'],
                    ['field' => 'total_amount', 'label' => 'Total', 'aggregate' => 'sum', 'alias' => 'total'],
                ],
                'group_by' => ['supplier_name'],
            ],
        ];

        foreach ($specs as $spec) {
            if (! isset($this->reportSources()[$spec['source']])) {
                continue;
            }

            $this->postJson('/api/v1/reports/builder/preview', [
                'spec' => $spec,
                'per_page' => 5,
                'workspace_id' => 'backoffice',
            ])->assertOk()->assertJsonStructure(['data']);
        }
    }

    public function test_hr_source_is_rejected_in_accounting_workspace(): void
    {
        if (! isset($this->reportSources()['employees'])) {
            $this->markTestSkipped('employees source not configured');
        }

        $spec = [
            'source' => 'employees',
            'columns' => [
                ['field' => 'department_name', 'label' => 'Department'],
                ['field' => 'employee_count', 'label' => 'Headcount', 'aggregate' => 'count', 'alias' => 'headcount'],
            ],
            'group_by' => ['department_name'],
        ];

        $this->postJson('/api/v1/reports/builder/preview', [
            'spec' => $spec,
            'per_page' => 5,
            'workspace_id' => 'accounting',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['source']);
    }

    public function test_hr_workspace_can_preview_hr_sources(): void
    {
        if (! isset($this->reportSources()['employees'])) {
            $this->markTestSkipped('employees source not configured');
        }

        $spec = [
            'source' => 'employees',
            'columns' => [
                ['field' => 'department_name', 'label' => 'Department'],
                ['field' => 'employee_count', 'label' => 'Headcount', 'aggregate' => 'count', 'alias' => 'headcount'],
            ],
            'group_by' => ['department_name'],
        ];

        $this->postJson('/api/v1/reports/builder/preview', [
            'spec' => $spec,
            'per_page' => 5,
            'workspace_id' => 'hr',
        ])->assertOk()->assertJsonStructure(['data']);
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
                'workspace_id' => 'backoffice',
            ])->assertCreated();
        }

        $this->getJson('/api/v1/reports/builder/templates?workspace_id=backoffice')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_builder_schema_includes_blend_dimensions(): void
    {
        $response = $this->getJson('/api/v1/reports/builder/schema?workspace_id=backoffice')
            ->assertOk();

        $this->assertGreaterThan(0, count($response->json('blend_dimensions')));
        $this->assertSame(4, $response->json('max_sources'));
    }

    public function test_can_preview_blended_multi_source_report(): void
    {
        if (! isset($this->reportSources()['sales'], $this->reportSources()['returns'])) {
            $this->markTestSkipped('sales/returns sources not configured');
        }

        $spec = [
            'sources' => ['sales', 'returns'],
            'source' => 'sales',
            'blend_by' => 'month',
            'columns' => [
                [
                    'source' => 'sales',
                    'field' => 'order_total',
                    'label' => 'Sales total',
                    'aggregate' => 'sum',
                    'alias' => 'total_sales',
                ],
                [
                    'source' => 'returns',
                    'field' => 'amount',
                    'label' => 'Returns total',
                    'aggregate' => 'sum',
                    'alias' => 'total_returns',
                ],
            ],
            'group_by' => [],
        ];

        $this->postJson('/api/v1/reports/builder/preview', [
            'spec' => $spec,
            'per_page' => 5,
            'workspace_id' => 'backoffice',
        ])->assertOk()->assertJsonStructure(['data']);
    }

    public function test_can_preview_joined_sales_and_products_report(): void
    {
        if (! isset($this->reportSources()['sales'], $this->reportSources()['products'])) {
            $this->markTestSkipped('sales/products sources not configured');
        }

        $spec = [
            'sources' => ['sales', 'products'],
            'source' => 'sales',
            'columns' => [
                [
                    'source' => 'products',
                    'field' => 'product_name',
                    'label' => 'Product',
                    'alias' => 'product_name',
                ],
                [
                    'source' => 'sales',
                    'field' => 'order_total',
                    'label' => 'Sales total',
                    'aggregate' => 'sum',
                    'alias' => 'total_sales',
                ],
            ],
            'group_by' => [
                ['source' => 'products', 'field' => 'product_name'],
            ],
        ];

        $this->postJson('/api/v1/reports/builder/preview', [
            'spec' => $spec,
            'per_page' => 5,
            'workspace_id' => 'backoffice',
        ])->assertOk()->assertJsonStructure(['data']);
    }

    public function test_can_preview_joined_sales_and_stock_report(): void
    {
        if (! isset($this->reportSources()['sales'], $this->reportSources()['stock'])) {
            $this->markTestSkipped('sales/stock sources not configured');
        }

        $spec = [
            'sources' => ['sales', 'stock'],
            'source' => 'sales',
            'columns' => [
                ['source' => 'sales', 'field' => 'order_num', 'alias' => 'sales_order_num'],
                ['source' => 'stock', 'field' => 'product_name', 'alias' => 'stock_product_name'],
                ['source' => 'stock', 'field' => 'product_code', 'alias' => 'stock_product_code'],
                ['source' => 'stock', 'field' => 'shop_quantity', 'alias' => 'stock_shop_quantity'],
            ],
        ];

        $this->postJson('/api/v1/reports/builder/preview', [
            'spec' => $spec,
            'per_page' => 5,
            'workspace_id' => 'backoffice',
        ])->assertOk()->assertJsonStructure(['data']);
    }

    public function test_joined_report_supports_products_as_primary_source(): void
    {
        if (! isset($this->reportSources()['sales'], $this->reportSources()['products'])) {
            $this->markTestSkipped('sales/products sources not configured');
        }

        $spec = [
            'sources' => ['sales', 'products'],
            'source' => 'products',
            'columns' => [
                ['source' => 'products', 'field' => 'product_name', 'alias' => 'product_name'],
                ['source' => 'sales', 'field' => 'order_total', 'aggregate' => 'sum', 'alias' => 'total_sales'],
            ],
            'group_by' => [
                ['source' => 'products', 'field' => 'product_name'],
            ],
        ];

        $this->postJson('/api/v1/reports/builder/preview', [
            'spec' => $spec,
            'per_page' => 5,
            'workspace_id' => 'backoffice',
        ])->assertOk()->assertJsonStructure(['data']);
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
            'workspace_id' => 'backoffice',
        ])->assertOk()->assertJsonStructure(['data']);

        $create = $this->postJson('/api/v1/reports/builder/templates', [
            'name' => 'Sales by channel (custom)',
            'description' => 'Test template',
            'spec' => $spec,
            'is_shared' => true,
            'workspace_id' => 'backoffice',
        ])->assertCreated();

        $id = $create->json('id');
        $this->assertNotNull($id);

        $this->getJson("/api/v1/reports/builder/templates/{$id}/run?per_page=5&workspace_id=backoffice")
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->getJson("/api/v1/reports/builder/templates/{$id}?workspace_id=backoffice")
            ->assertOk()
            ->assertJsonPath('definition.title', 'Sales by channel (custom)');

        $this->getJson('/api/v1/reports/builder/templates?workspace_id=backoffice')
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'Sales by channel (custom)',
                'category_id' => 'sales',
                'category_label' => 'Sales Reports',
                'report_module' => 'sales.reports',
                'primary_source' => 'sales',
            ]);
    }
}
