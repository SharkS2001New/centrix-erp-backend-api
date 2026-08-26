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
