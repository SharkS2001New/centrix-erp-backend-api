<?php

namespace Tests\Unit\Ai;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\AiActionExecutor;
use App\Services\Ai\AiCreateLpoParamMerger;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiCreateLpoParamMergerTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
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

    public function test_parses_due_date_terms_and_line_list(): void
    {
        $merger = app(AiCreateLpoParamMerger::class);
        $fields = $merger->extractFields(
            "Change Due date to 30/09/2026\nTerms: 7 days\nLines: Cooking Oil 20L x 10, Sugar 50 KG x 20"
        );

        $this->assertSame('2026-09-30', $fields['due_date'] ?? null);
        $this->assertSame('7 days', $fields['terms'] ?? null);
        $this->assertNotEmpty($fields['lines_raw'] ?? null);
        $this->assertTrue($fields['replace_lines'] ?? false);

        $lines = $merger->parseLineList((string) $fields['lines_raw']);
        $this->assertCount(2, $lines);
        $this->assertSame(10.0, $lines[0]['ordered_qty']);
        $this->assertSame(20.0, $lines[1]['ordered_qty']);
    }

    public function test_looks_like_field_follow_up(): void
    {
        $merger = app(AiCreateLpoParamMerger::class);
        $this->assertTrue($merger->looksLikeFieldFollowUp('Change Due date to 30/09/2026'));
        $this->assertTrue($merger->looksLikeFieldFollowUp("Terms: 7 days\nProduct: Sugar x 10"));
        $this->assertTrue($merger->looksLikeFieldFollowUp('> Supplier: Bidco'));
    }

    public function test_merge_updates_existing_draft_without_wiping_supplier(): void
    {
        $supplier = Supplier::query()
            ->where('organization_id', $this->user->organization_id)
            ->whereNull('deleted_at')
            ->first();
        $this->assertNotNull($supplier);

        $product = Product::query()
            ->where('organization_id', $this->user->organization_id)
            ->whereNull('deleted_at')
            ->first();
        $this->assertNotNull($product);

        $merger = app(AiCreateLpoParamMerger::class);
        $pending = [
            'type' => 'create_lpo',
            'params' => [
                'supplier_id' => (int) $supplier->id,
                'supplier_name' => $supplier->supplier_name,
                'due_date' => '2026-03-30',
                'lines' => [
                    [
                        'product_code' => $product->product_code,
                        'product_name' => $product->product_name,
                        'ordered_qty' => 5,
                        'cost_price' => 10,
                    ],
                ],
            ],
        ];

        $result = $merger->merge(
            $this->user,
            $pending,
            "Change Due date to 30/09/2026\nTerms: 7 days",
        );

        $this->assertTrue($result['changed']);
        $this->assertSame((int) $supplier->id, (int) ($result['pending']['params']['supplier_id'] ?? 0));
        $this->assertSame('2026-09-30', $result['pending']['params']['due_date'] ?? null);
        $this->assertSame('7 days', $result['pending']['params']['terms'] ?? null);
        $this->assertCount(1, $result['pending']['params']['lines'] ?? []);

        $executor = app(AiActionExecutor::class);
        $this->assertTrue($executor->isReadyToConfirm($result['pending']));

        $reply = $merger->statusReply($result['pending'], $result['notes']);
        $this->assertStringContainsString('2026-09-30', $reply);
        $this->assertStringContainsString('confirm', strtolower($reply));
    }

    public function test_help_create_lpo_question_is_not_treated_as_supplier_name(): void
    {
        $merger = app(AiCreateLpoParamMerger::class);
        $pending = [
            'type' => 'create_lpo',
            'params' => [],
        ];

        foreach ([
            'can you help me create an LPO',
            'Can you help me create an LPO?',
            'help me create a purchase order',
            'I want to raise an LPO',
            'please create an LPO',
        ] as $message) {
            $result = $merger->merge($this->user, $pending, $message);
            $params = $result['pending']['params'] ?? [];
            $this->assertSame(0, (int) ($params['supplier_id'] ?? 0), $message);
            $this->assertTrue(
                empty($params['supplier_name'])
                || ! str_contains(strtolower((string) $params['supplier_name']), 'help'),
                $message,
            );
            foreach ($result['notes'] as $note) {
                $this->assertStringNotContainsString('Could not match supplier', $note, $message);
            }
            $reply = $merger->statusReply($result['pending'], $result['notes']);
            $this->assertStringNotContainsString('Could not match supplier', $reply, $message);
            $this->assertStringNotContainsString('Updated draft LPO', $reply, $message);
            $this->assertStringNotContainsString('still needed', $reply, $message);
            $this->assertMatchesRegularExpression('/Sure|supplier/i', $reply, $message);
        }
    }

    public function test_bare_supplier_name_still_accepted(): void
    {
        $supplier = Supplier::query()
            ->where('organization_id', $this->user->organization_id)
            ->whereNull('deleted_at')
            ->first();
        $this->assertNotNull($supplier);

        $merger = app(AiCreateLpoParamMerger::class);
        $result = $merger->merge($this->user, [
            'type' => 'create_lpo',
            'params' => [],
        ], (string) $supplier->supplier_name);

        $this->assertTrue($result['changed']);
        $this->assertSame((int) $supplier->id, (int) ($result['pending']['params']['supplier_id'] ?? 0));

        $reply = $merger->statusReply($result['pending'], $result['notes']);
        $this->assertStringNotContainsString('Updated draft LPO', $reply);
        $this->assertStringContainsString('nothing is saved yet', mb_strtolower($reply));
        $this->assertMatchesRegularExpression('/product/i', $reply);
        $this->assertFalse(app(AiActionExecutor::class)->isReadyToConfirm($result['pending']));
    }

    public function test_resolves_supplier_by_name_on_create(): void
    {
        $supplier = Supplier::query()
            ->where('organization_id', $this->user->organization_id)
            ->whereNull('deleted_at')
            ->first();
        $this->assertNotNull($supplier);

        $product = Product::query()
            ->where('organization_id', $this->user->organization_id)
            ->whereNull('deleted_at')
            ->first();
        $this->assertNotNull($product);

        $executor = app(AiActionExecutor::class);
        $outcome = $executor->execute($this->user, [
            'type' => 'create_lpo',
            'params' => [
                'supplier_name' => $supplier->supplier_name,
                'due_date' => '2026-09-30',
                'terms' => '7 days',
                'lines' => [
                    [
                        'product_code' => $product->product_code,
                        'ordered_qty' => 2,
                    ],
                ],
            ],
        ]);

        $this->assertTrue($outcome['success'] ?? false);
        $this->assertNotEmpty($outcome['result']['lpo_no'] ?? null);
        $links = $outcome['result']['document_links'] ?? [];
        $this->assertNotEmpty($links);
        $print = collect($links)->firstWhere('kind', 'print');
        $this->assertNotNull($print);
        $this->assertTrue((bool) ($print['use_lpo_print'] ?? false));
    }
}
