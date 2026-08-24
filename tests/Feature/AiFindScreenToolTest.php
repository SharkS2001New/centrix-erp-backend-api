<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\AiToolRegistry;
use App\Services\Ai\Tools\FindScreenTool;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiFindScreenToolTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_find_screen_returns_supplier_and_inventory_paths(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        /** @var FindScreenTool $tool */
        $tool = app(FindScreenTool::class);

        $suppliers = $tool->execute($admin, ['query' => 'suppliers']);
        $this->assertNotEmpty($suppliers['screens']);
        $paths = collect($suppliers['screens'])->pluck('path')->all();
        $this->assertTrue(
            collect($paths)->contains(fn ($path) => str_contains((string) $path, 'supplier')),
            'Expected a suppliers-related path, got: '.implode(', ', $paths),
        );

        $grn = $tool->execute($admin, ['query' => 'GRN stock receipts']);
        $grnPaths = collect($grn['screens'])->pluck('path')->all();
        $this->assertTrue(
            collect($grnPaths)->contains('/inventory/receipts')
                || collect($grn['modules'] ?? [])->isNotEmpty(),
            'Expected GRN/receipts guidance',
        );
    }

    public function test_tool_registry_includes_documentation_tools(): void
    {
        $registry = app(AiToolRegistry::class);
        $names = array_map(fn ($tool) => $tool->name(), $registry->all());

        $this->assertContains('find_screen', $names);
        $this->assertContains('get_stock_summary', $names);
        $this->assertContains('get_product_details', $names);
        $this->assertContains('search_training_notes', $names);
        $this->assertContains('get_purchasing_overview', $names);
        $this->assertContains('get_sales_summary', $names);
        $this->assertContains('get_sales_by_cashier', $names);
        $this->assertContains('get_employee_attendance', $names);
        $this->assertContains('create_custom_report', $names);
    }

    public function test_find_screen_returns_attendance_and_report_builder_paths(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        /** @var FindScreenTool $tool */
        $tool = app(FindScreenTool::class);

        $attendance = $tool->execute($admin, ['query' => 'employee attendance history']);
        $paths = collect($attendance['screens'])->pluck('path')->all();
        $this->assertTrue(
            collect($paths)->contains('/hr/attendance')
                || collect($paths)->contains('/hr/attendance/history'),
            'Expected attendance paths, got: '.implode(', ', $paths),
        );

        $builder = $tool->execute($admin, ['query' => 'report builder']);
        $builderPaths = collect($builder['screens'])->pluck('path')
            ->merge(collect($builder['modules'] ?? [])->flatMap(fn ($m) => $m['paths'] ?? []))
            ->merge(collect($builder['workflows'] ?? [])->pluck('path'))
            ->filter()
            ->values()
            ->all();
        $this->assertTrue(
            collect($builderPaths)->contains(fn ($path) => str_contains((string) $path, '/reports')),
            'Expected a reports path, got: '.implode(', ', $builderPaths),
        );

        $field = $tool->execute($admin, ['query' => 'field attendance']);
        $fieldPaths = collect($field['screens'])->pluck('path')->all();
        $this->assertTrue(
            collect($fieldPaths)->contains('/sales/field-attendance')
                || collect($field['modules'] ?? [])->isNotEmpty(),
            'Expected /sales/field-attendance, got: '.implode(', ', $fieldPaths),
        );
    }
}
