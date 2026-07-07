<?php


/**
 * Local-only tests — excluded from CI / default `composer test`.
 * Run manually: composer test:local
 */

namespace Tests\Local\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Ai\AiActionExecutor;
use App\Services\Ai\AiSystemContextBuilder;
use App\Services\Ai\AiTopicGuard;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        $this->org = Organization::findOrFail($this->user->organization_id);
        Sanctum::actingAs($this->user);
    }

    public function test_ai_status_when_org_disabled(): void
    {
        config(['ai.platform_enabled' => false]);

        $this->getJson('/api/v1/ai/status')
            ->assertOk()
            ->assertJsonPath('organization_enabled', false)
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('scope', 'organization');
    }

    public function test_ai_chat_when_org_disabled_returns_helpful_message(): void
    {
        $this->postJson('/api/v1/ai/chat', [
            'context' => 'erp',
            'message' => 'Which products are low on stock?',
        ])
            ->assertOk()
            ->assertJsonStructure(['reply', 'tools_used']);
    }

    public function test_off_topic_message_is_declined_without_calling_llm(): void
    {
        $this->patchJson('/api/v1/erp/settings/ai', [
            'enabled' => true,
            'api_key' => 'sk-test-org-key-123456',
            'model' => 'gpt-4o-mini',
        ])->assertOk();

        Http::fake();

        $this->postJson('/api/v1/ai/chat', [
            'context' => 'erp',
            'message' => 'What is the weather forecast for Nairobi today?',
        ])
            ->assertOk()
            ->assertJsonPath('declined_off_topic', true)
            ->assertJsonFragment([
                'reply' => app(AiTopicGuard::class)->declineMessage(),
            ]);

        Http::assertNothingSent();
    }

    public function test_hr_context_uses_department_and_shift_column_names(): void
    {
        $context = app(AiSystemContextBuilder::class)->build(
            $this->user,
            'How do I add a new employee to HR?',
        );

        $this->assertArrayHasKey('hr_reference', $context);
        $this->assertArrayHasKey('departments', $context['hr_reference']);
        $this->assertArrayHasKey('shifts', $context['hr_reference']);

        foreach ($context['hr_reference']['departments'] as $department) {
            $this->assertArrayHasKey('id', $department);
            $this->assertArrayHasKey('name', $department);
        }
    }

    public function test_product_creation_questions_are_in_scope(): void
    {
        $guard = app(AiTopicGuard::class);

        $this->assertTrue($guard->isErpRelated('Help me create a new product in the catalog'));
        $this->assertTrue($guard->isErpRelated('I need to add a product with code WIDGET-01'));
    }

    public function test_context_includes_module_catalog_and_workflows(): void
    {
        $context = app(AiSystemContextBuilder::class)->build(
            $this->user,
            'What can this system do?',
        );

        $this->assertNotEmpty($context['module_catalog']);
        $this->assertArrayHasKey('create_product', $context['workflows']);
        $this->assertArrayHasKey('create_sales_order', $context['workflows']);
    }

    public function test_admin_context_includes_user_access_and_sales_summary(): void
    {
        $context = app(AiSystemContextBuilder::class)->build(
            $this->user,
            'Show me sales data for this month',
        );

        $this->assertTrue($context['user_access']['is_admin']);
        $this->assertTrue($context['user_access']['has_full_permissions']);
        $this->assertTrue($context['user_access']['modules']['sales']['user_has_permission']);
        $this->assertArrayHasKey('sales_summary', $context);
    }

    public function test_available_actions_use_permission_aliases_for_capability_codes(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $context = app(AiSystemContextBuilder::class)->build(
            $cashier,
            'Hello',
        );

        $types = collect($context['available_actions'])->pluck('type')->all();
        $this->assertContains('record_customer_payment', $types);
    }

    public function test_ai_action_executor_creates_sales_order(): void
    {
        $product = Product::firstOrFail();

        $outcome = app(AiActionExecutor::class)->execute($this->user, [
            'type' => 'create_sales_order',
            'params' => [
                'customer_num' => 5002,
                'lines' => [
                    ['product_code' => $product->product_code, 'quantity' => 1],
                ],
                'payment_method_code' => 'CASH',
            ],
        ]);

        $this->assertTrue($outcome['success']);
        $this->assertNotNull($outcome['result']['sale_id'] ?? null);
        $this->assertNotEquals('held', $outcome['result']['status'] ?? null);
    }

    public function test_ai_action_executor_creates_product(): void
    {
        $code = 'AI-TEST-'.uniqid();

        $outcome = app(AiActionExecutor::class)->execute($this->user, [
            'type' => 'create_product',
            'params' => [
                'product_code' => $code,
                'product_name' => 'AI Test Widget',
                'unit_price' => 99.5,
            ],
        ]);

        $this->assertTrue($outcome['success']);
        $this->assertSame($code, $outcome['result']['product_code'] ?? null);
        $this->assertDatabaseHas('products', [
            'product_code' => $code,
            'product_name' => 'AI Test Widget',
        ]);
    }

    public function test_ai_action_executor_auto_generates_product_code(): void
    {
        $outcome = app(AiActionExecutor::class)->execute($this->user, [
            'type' => 'create_product',
            'params' => [
                'product_name' => 'Auto Code Widget',
                'unit_price' => 50,
            ],
        ]);

        $this->assertTrue($outcome['success']);
        $this->assertMatchesRegularExpression('/^PRD#\d+$/', (string) ($outcome['result']['product_code'] ?? ''));
    }

    public function test_schemas_endpoint_returns_product_entity(): void
    {
        $this->getJson('/api/v1/ai/schemas?entity=product')
            ->assertOk()
            ->assertJsonPath('entity', 'product')
            ->assertJsonStructure(['schema' => ['fields' => ['product_code', 'product_name', 'unit_id']]]);
    }

    public function test_form_spec_includes_select_options_for_product(): void
    {
        $spec = app(\App\Services\Ai\AiFormSpecBuilder::class)->forAction($this->user, [
            'type' => 'create_product',
            'params' => ['product_name' => 'Test'],
        ]);

        $this->assertNotNull($spec);
        $unitField = collect($spec['fields'])->firstWhere('name', 'unit_id');
        $this->assertNotEmpty($unitField['options'] ?? []);
    }

    public function test_customer_form_spec_matches_manual_customer_fields(): void
    {
        $spec = app(\App\Services\Ai\AiFormSpecBuilder::class)->forAction($this->user, [
            'type' => 'create_customer',
            'params' => ['customer_name' => 'Test Shop'],
        ]);

        $this->assertNotNull($spec);
        $names = collect($spec['fields'])->pluck('name')->all();
        $this->assertContains('branch_id', $names);
        $this->assertContains('customer_name', $names);
        $this->assertContains('customer_type', $names);
        $this->assertContains('route_id', $names);

        $typeField = collect($spec['fields'])->firstWhere('name', 'customer_type');
        $typeValues = collect($typeField['options'] ?? [])->pluck('value')->all();
        $this->assertContains('regular', $typeValues);
        $this->assertContains('debtor', $typeValues);
        $this->assertContains('route', $typeValues);
    }

    public function test_regular_user_cannot_teach_via_api(): void
    {
        $this->postJson('/api/v1/ai/teach', [
            'topic' => 'Pricing policy',
            'content' => 'All sugar products use VAT standard rate.',
        ])
            ->assertForbidden();
    }

    public function test_remember_that_directs_users_to_platform_training(): void
    {
        $this->postJson('/api/v1/ai/chat', [
            'message' => 'Remember that walk-in POS sales do not need a customer number.',
        ])
            ->assertOk()
            ->assertJsonFragment([
                'reply' => 'Training notes are managed platform-wide and apply to every organization. Ask your platform administrator to add notes under Platform → AI training.',
            ]);
    }

    public function test_explore_page_returns_draft_for_confirmation(): void
    {
        $this->postJson('/api/v1/ai/explore', ['path' => '/products'])
            ->assertOk()
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonStructure(['draft' => ['id'], 'analysis' => ['summary', 'entities']]);
    }

    public function test_chat_infers_product_form_when_llm_does_not_emit_action(): void
    {
        $this->patchJson('/api/v1/erp/settings/ai', [
            'enabled' => true,
            'api_key' => 'sk-test-org-key-123456',
            'model' => 'gpt-4o-mini',
        ])->assertOk();

        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => 'Now, I will fetch the available options for subcategory, unit, and VAT. Please hold on.',
                    ],
                ]],
            ]),
        ]);

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Help me create a new product called Test Widget',
        ])
            ->assertOk()
            ->assertJsonPath('pending_action.type', 'create_product')
            ->assertJsonStructure([
                'form_spec' => ['fields', 'title'],
            ]);

        $fieldNames = collect($response->json('form_spec.fields'))->pluck('name')->all();
        $this->assertContains('product_name', $fieldNames);
        $this->assertContains('unit_id', $fieldNames);
    }

    public function test_chat_rejects_image_content(): void
    {
        $this->postJson('/api/v1/ai/chat', [
            'message' => 'Check this data:image/png;base64,abc',
        ])
            ->assertStatus(422);
    }

    public function test_debtors_context_includes_top_debtors_and_open_invoices(): void
    {
        $context = app(AiSystemContextBuilder::class)->build(
            $this->user,
            'Who are our top debtors and what do they owe?',
        );

        $this->assertArrayHasKey('receivables_summary', $context);
        $this->assertArrayHasKey('top_debtors', $context['receivables_summary']);
        $this->assertArrayHasKey('open_invoices', $context['receivables_summary']);
    }

    public function test_ai_action_executor_records_partial_customer_payment(): void
    {
        $sale = $this->createCreditSaleWithBalance();

        $balance = round((float) $sale->order_total - (float) $sale->amount_paid, 2);
        $partial = round($balance / 2, 2);
        $paymentMethodId = \App\Models\PaymentMethod::where('method_code', 'CASH')->value('id');

        $outcome = app(AiActionExecutor::class)->execute($this->user, [
            'type' => 'record_customer_payment',
            'params' => [
                'sale_id' => $sale->id,
                'amount' => $partial,
                'payment_method_id' => $paymentMethodId,
            ],
        ]);

        $this->assertTrue($outcome['success']);
        $this->assertSame('partial', $outcome['result']['payment_status'] ?? null);

        $sale->refresh();
        $this->assertEqualsWithDelta($partial, (float) $sale->amount_paid, 0.02);
    }

    public function test_ai_action_executor_records_full_customer_payment(): void
    {
        $sale = $this->createCreditSaleWithBalance();
        $paymentMethodId = \App\Models\PaymentMethod::where('method_code', 'CASH')->value('id');

        $outcome = app(AiActionExecutor::class)->execute($this->user, [
            'type' => 'record_customer_payment',
            'params' => [
                'sale_id' => $sale->id,
                'mark_paid_full' => true,
                'payment_method_id' => $paymentMethodId,
            ],
        ]);

        $this->assertTrue($outcome['success']);
        $this->assertSame('paid', $outcome['result']['payment_status'] ?? null);
    }

    public function test_chat_declines_payment_action_without_permission(): void
    {
        $this->patchJson('/api/v1/erp/settings/ai', [
            'enabled' => true,
            'api_key' => 'sk-test-org-key-123456',
            'model' => 'gpt-4o-mini',
        ])->assertOk();

        $viewOnlyRole = Role::create([
            'role_name' => 'AI view only '.uniqid(),
            'scope' => 'branch',
        ]);
        $viewPermId = \App\Models\Permission::where('permission_code', 'sales.order_queue_all.view')->value('id');
        $aiPermId = \App\Models\Permission::where('permission_code', 'ai.assist.create')->value('id');
        foreach (array_filter([$viewPermId, $aiPermId]) as $permId) {
            \Illuminate\Support\Facades\DB::table('role_permissions')->insert([
                'role_id' => $viewOnlyRole->id,
                'permission_id' => $permId,
            ]);
        }

        $restricted = User::create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->user->branch_id,
            'role_id' => $viewOnlyRole->id,
            'username' => 'ai_no_payments_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'User Without Payments',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);
        Sanctum::actingAs($restricted);

        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => 'I will record that payment for you.',
                    ],
                ]],
            ]),
        ]);

        $this->postJson('/api/v1/ai/chat', [
            'message' => 'Record a partial payment for customer invoice',
        ])
            ->assertOk()
            ->assertJsonMissing(['pending_action'])
            ->assertJsonFragment([
                'reply' => app(AiActionExecutor::class)->permissionDeclineMessage('record_customer_payment'),
            ]);
    }

    /** @return \App\Models\Sale */
    protected function createCreditSaleWithBalance(): \App\Models\Sale
    {
        $customer = \App\Models\Customer::firstOrFail();
        $customer->update(['credit_limit' => 50000, 'current_balance' => 0]);

        $productCode = Product::firstOrFail()->product_code;

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $productCode,
            'quantity' => 1,
        ])->assertCreated();

        $saleId = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'is_credit_sale' => true,
            'pay_now' => 0,
            'payment_method_code' => 'CREDIT',
            'customer_num' => $customer->customer_num,
        ])->assertCreated()->json('id');

        return \App\Models\Sale::findOrFail($saleId);
    }

    public function test_hr_question_declined_in_accounting_workspace_without_llm(): void
    {
        $this->patchJson('/api/v1/erp/settings/ai', [
            'enabled' => true,
            'api_key' => 'sk-test-org-key-123456',
            'model' => 'gpt-4o-mini',
        ])->assertOk();

        Http::fake();

        $scope = app(\App\Services\Ai\AiWorkspaceScope::class);

        $this->postJson('/api/v1/ai/chat', [
            'context' => 'erp',
            'workspace_id' => 'accounting',
            'pathname' => '/accounting',
            'message' => 'How do I add a new employee to payroll?',
        ])
            ->assertOk()
            ->assertJsonPath('declined_off_topic', true)
            ->assertJsonPath('active_workspace', 'accounting')
            ->assertJsonFragment([
                'reply' => $scope->declineMessage(
                    app(\App\Services\Ai\AiWorkspaceScope::class)->resolve(
                        $this->user,
                        app(\App\Services\Erp\ErpContext::class)->gateForUser($this->user),
                        'accounting',
                        '/accounting',
                    ),
                ),
            ]);

        Http::assertNothingSent();
    }

    public function test_context_is_scoped_to_active_workspace(): void
    {
        $gate = app(\App\Services\Erp\ErpContext::class)->gateForUser($this->user);
        $scope = app(\App\Services\Ai\AiWorkspaceScope::class)->resolve(
            $this->user,
            $gate,
            'hr',
            '/hr/employees',
        );

        $context = app(AiSystemContextBuilder::class)->build(
            $this->user,
            'How do I add an employee?',
            $scope,
        );

        $this->assertSame('hr', $context['active_workspace']['id']);
        $this->assertNotEmpty($context['navigation']);
        $this->assertTrue(
            collect($context['navigation'])->contains(fn ($section) => ($section['id'] ?? null) === 'hr'),
        );
        $this->assertTrue(
            collect($context['available_actions'])->contains(fn ($action) => ($action['type'] ?? null) === 'create_employee'),
        );
    }
}
