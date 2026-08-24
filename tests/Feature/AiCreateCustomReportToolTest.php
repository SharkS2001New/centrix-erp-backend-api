<?php

namespace Tests\Feature;

use App\Models\CustomReportTemplate;
use App\Models\User;
use App\Services\Ai\Tools\CreateCustomReportTool;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiCreateCustomReportToolTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_asks_for_name_when_missing(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        /** @var CreateCustomReportTool $tool */
        $tool = app(CreateCustomReportTool::class);
        $result = $tool->execute($admin, [
            'instruction' => 'employee attendance with check in and hours',
        ]);

        $this->assertFalse($result['created'] ?? true);
        $this->assertTrue($result['needs_name'] ?? false);
        $this->assertStringContainsString('name', strtolower((string) ($result['ask'] ?? '')));
    }

    public function test_creates_named_attendance_report_with_custom_path(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        /** @var CreateCustomReportTool $tool */
        $tool = app(CreateCustomReportTool::class);
        $result = $tool->execute($admin, [
            'name' => 'August Attendance AI',
            'instruction' => 'employee attendance with check in, check out and hours',
            'workspace_id' => 'hr',
        ]);

        $this->assertTrue($result['created'] ?? false, json_encode($result));
        $this->assertSame('August Attendance AI', $result['name'] ?? null);
        $this->assertMatchesRegularExpression('#^/reports/custom/\d+$#', (string) ($result['path'] ?? ''));

        $id = (int) str_replace('/reports/custom/', '', (string) $result['path']);
        $template = CustomReportTemplate::query()->find($id);
        $this->assertNotNull($template);
        $this->assertSame('August Attendance AI', $template->name);
        $this->assertSame('attendance', $template->spec['source'] ?? null);
    }
}
