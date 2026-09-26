<?php

namespace Tests\Feature;

use App\Models\LpoMst;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\AiActionExecutor;
use App\Services\Purchasing\LpoWorkflowService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiLpoDocumentWorkflowTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function ensureLpoStatuses(): void
    {
        foreach ([
            0 => 'Awaiting check',
            1 => 'Awaiting approval',
            2 => 'Awaiting send',
            3 => 'Awaiting receive',
            4 => 'Partially received',
            5 => 'Fully received',
            6 => 'Cleared',
            7 => 'Cancelled / returned',
        ] as $code => $name) {
            DB::table('lpo_statuses')->updateOrInsert(
                ['status_code' => $code],
                ['status_name' => $name],
            );
        }
    }

    public function test_lpo_pdf_endpoint_returns_pdf(): void
    {
        $this->ensureLpoStatuses();
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $product = Product::firstOrFail();

        $create = $this->postJson('/api/v1/lpo-mst/full', [
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_code' => $product->product_code,
                    'ordered_qty' => 2,
                    'cost_price' => 100,
                ],
            ],
        ])->assertCreated();

        $lpoNo = (int) $create->json('lpo_no');

        $response = $this->get("/api/v1/lpo-mst/{$lpoNo}/pdf");
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // HTML builder (used by Dompdf) must match org print document title + procurement layout.
        $service = app(\App\Services\Purchasing\LpoDocumentPdfService::class);
        $summary = app(\App\Services\LpoModuleService::class)->summary($lpoNo, (int) $user->organization_id, $user);
        $org = Organization::query()->find((int) $user->organization_id);
        $ref = new \ReflectionClass($service);
        $method = $ref->getMethod('buildHtml');
        $method->setAccessible(true);
        $html = $method->invoke(
            $service,
            $summary['lpo'] ?? [],
            $summary['lines'] ?? [],
            app(\App\Services\Background\ReportBrandingService::class)->forOrganization($org),
            \App\Services\Purchasing\ProcurementSettingsResolver::forOrganization($org),
            $supplier,
            $org,
        );
        $this->assertStringContainsString('LOCAL PURCHASE ORDER', $html);
        $this->assertStringContainsString('Item Description', $html);
        $this->assertStringContainsString('Authorised by', $html);
    }

    public function test_list_lpos_awaiting_receive_excludes_fully_received_and_cleared(): void
    {
        $this->ensureLpoStatuses();
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $product = Product::firstOrFail();

        $make = function (int $status) use ($supplier, $product) {
            $create = $this->postJson('/api/v1/lpo-mst/full', [
                'supplier_id' => $supplier->id,
                'lines' => [
                    [
                        'product_code' => $product->product_code,
                        'ordered_qty' => 1,
                        'cost_price' => 50,
                    ],
                ],
            ])->assertCreated();
            $lpoNo = (int) $create->json('lpo_no');
            LpoMst::query()->where('lpo_no', $lpoNo)->update(['lpo_status_code' => $status]);

            return $lpoNo;
        };

        $awaiting = $make(3);
        $partial = $make(4);
        $fully = $make(5);
        $cleared = $make(6);
        $check = $make(0);

        $this->assertSame(3, (int) LpoMst::query()->where('lpo_no', $awaiting)->value('lpo_status_code'));
        $this->assertSame(4, (int) LpoMst::query()->where('lpo_no', $partial)->value('lpo_status_code'));

        $registry = app(\App\Services\Ai\AiToolRegistry::class);
        $this->assertTrue($registry->has('list_lpos'), 'list_lpos tool must be registered');

        $result = app(\App\Services\Ai\Tools\ListLposTool::class)->execute($user, [
            'filter' => 'awaiting_receive',
            'limit' => 50,
        ]);
        // Normalize in case the runtime wraps the payload (ArrayAccess / JsonSerializable).
        $payload = json_decode(json_encode($result), true);
        $this->assertIsArray($payload, get_debug_type($result));
        $this->assertFalse($payload['error'] ?? false, json_encode($payload));
        $orders = $payload['purchase_orders'] ?? [];
        $this->assertIsArray($orders);
        $this->assertCount(2, $orders, json_encode($payload));
        $this->assertSame(2, (int) ($payload['total_matching'] ?? -1));

        $ids = [];
        $statusCodes = [];
        foreach ($orders as $row) {
            $ids[] = (int) ($row['lpo_no'] ?? 0);
            $statusCodes[] = (int) ($row['status_code'] ?? -1);
            $this->assertTrue((bool) ($row['open_for_receive'] ?? false));
        }
        sort($statusCodes);

        $this->assertSame([3, 4], $statusCodes);
        $this->assertContainsEquals((int) $awaiting, $ids);
        $this->assertContainsEquals((int) $partial, $ids);
        $this->assertNotContainsEquals((int) $fully, $ids);
        $this->assertNotContainsEquals((int) $cleared, $ids);
        $this->assertNotContainsEquals((int) $check, $ids);
    }

    public function test_get_lpo_details_latest_returns_most_recent_with_pdf_link(): void
    {
        $this->ensureLpoStatuses();
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $product = Product::firstOrFail();

        $older = (int) $this->postJson('/api/v1/lpo-mst/full', [
            'supplier_id' => $supplier->id,
            'lines' => [
                ['product_code' => $product->product_code, 'ordered_qty' => 1, 'cost_price' => 10],
            ],
        ])->assertCreated()->json('lpo_no');

        $newer = (int) $this->postJson('/api/v1/lpo-mst/full', [
            'supplier_id' => $supplier->id,
            'lines' => [
                ['product_code' => $product->product_code, 'ordered_qty' => 2, 'cost_price' => 20],
            ],
        ])->assertCreated()->json('lpo_no');

        $this->assertGreaterThan($older, $newer);

        $tool = app(\App\Services\Ai\Tools\GetLpoDetailsTool::class);
        $byFlag = $tool->execute($user, ['latest' => true]);
        $byQuery = $tool->execute($user, ['query' => 'last LPO we created, need to download']);

        foreach ([$byFlag, $byQuery] as $result) {
            $this->assertFalse($result['error'] ?? false, json_encode($result));
            $this->assertSame($newer, (int) ($result['lpo_no'] ?? 0));
            $this->assertTrue((bool) ($result['is_latest'] ?? false));
            $links = $result['document_links'] ?? [];
            $this->assertIsArray($links);
            $pdf = collect($links)->firstWhere('kind', 'pdf');
            $this->assertNotNull($pdf);
            $this->assertSame('/lpo-mst/'.$newer.'/pdf', $pdf['api_path'] ?? null);
            $this->assertTrue((bool) ($pdf['download'] ?? false));
        }
    }

    public function test_ai_can_submit_approve_mark_sent_and_receive(): void
    {
        $this->ensureLpoStatuses();
        $org = Organization::query()->where('company_code', 'DEMO')->firstOrFail();
        $settings = $org->module_settings ?? [];
        $settings['procurement'] = array_merge($settings['procurement'] ?? [], [
            'require_lpo_approval' => true,
        ]);
        $org->forceFill(['module_settings' => $settings])->save();

        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $product = Product::firstOrFail();

        $create = $this->postJson('/api/v1/lpo-mst/full', [
            'supplier_id' => $supplier->id,
            'branch_id' => $user->branch_id,
            'lines' => [
                [
                    'product_code' => $product->product_code,
                    'ordered_qty' => 3,
                    'cost_price' => 40,
                ],
            ],
        ])->assertCreated();

        $lpoNo = (int) $create->json('lpo_no');
        $executor = app(AiActionExecutor::class);

        $submitted = $executor->execute($user, [
            'type' => 'submit_lpo_for_approval',
            'params' => ['lpo_no' => $lpoNo],
        ]);
        $this->assertTrue($submitted['success'] ?? false);
        $this->assertSame(
            LpoWorkflowService::STATUS_AWAITING_APPROVAL,
            (int) LpoMst::query()->where('lpo_no', $lpoNo)->value('lpo_status_code'),
        );

        $approved = $executor->execute($user, [
            'type' => 'approve_lpo',
            'params' => ['lpo_no' => $lpoNo],
        ]);
        $this->assertTrue($approved['success'] ?? false);
        $this->assertNotEmpty($approved['result']['document_links'] ?? []);

        $sent = $executor->execute($user, [
            'type' => 'mark_lpo_sent',
            'params' => ['lpo_no' => $lpoNo],
        ]);
        $this->assertTrue($sent['success'] ?? false);

        $received = $executor->execute($user, [
            'type' => 'receive_lpo_goods',
            'params' => ['lpo_no' => $lpoNo, 'receive_all' => true],
        ]);
        $this->assertTrue($received['success'] ?? false, $received['message'] ?? 'receive failed');
        $this->assertNotEmpty($received['result']['receipts'] ?? []);
    }
}
