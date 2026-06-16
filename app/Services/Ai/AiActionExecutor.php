<?php

namespace App\Services\Ai;

use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\Operations\CartOperationsController;
use App\Http\Controllers\Api\V1\Operations\CheckoutController;
use App\Http\Controllers\Api\V1\Operations\PaymentOperationsController;
use App\Http\Controllers\Api\V1\Operations\ReportBuilderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Requests\Sales\AddCartLineRequest;
use App\Http\Requests\Sales\CheckoutRequest;
use App\Http\Requests\Sales\StoreCartRequest;
use App\Models\CustomReportTemplate;
use App\Models\Employee;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use Illuminate\Support\Facades\DB;
use App\Services\Erp\ErpContext;
use App\Services\Reports\ReportBuilderService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AiActionExecutor
{
    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
        protected ReportBuilderService $reportBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $action
     * @return array{success: bool, message: string, result?: array<string, mixed>}
     */
    public function execute(User $user, array $action): array
    {
        $type = (string) ($action['type'] ?? '');
        $params = is_array($action['params'] ?? null) ? $action['params'] : [];

        return match ($type) {
            'create_sales_order' => $this->createSalesOrder($user, $params, hold: false),
            'create_held_order' => $this->createSalesOrder($user, $params, hold: true),
            'create_product' => $this->createProduct($user, $params),
            'create_employee' => $this->createEmployee($user, $params),
            'create_report_template' => $this->createReportTemplate($user, $params),
            'record_customer_payment' => $this->recordCustomerPayment($user, $params),
            default => [
                'success' => false,
                'message' => 'Unknown action type.',
            ],
        };
    }

    public function canExecute(User $user, string $type): bool
    {
        if ($type === '') {
            return false;
        }

        $config = collect(config('ai_navigation.actions', []))->firstWhere('type', $type);
        if (! is_array($config)) {
            return false;
        }

        if (! empty($config['module'])) {
            $gate = $this->erp->gateForUser($user);
            if (! $gate->enabled($config['module'])) {
                return false;
            }
        }

        if (! empty($config['permission']) && ! $this->permissions->hasPermission($user, $config['permission'])) {
            return false;
        }

        return true;
    }

    public function permissionDeclineMessage(string $type): string
    {
        $label = (string) (collect(config('ai_navigation.actions', []))->firstWhere('type', $type)['label'] ?? $type);

        return "You do not have permission to {$label}. I can only perform actions your account is allowed to do — ask an administrator if you need access.";
    }

    protected function assertPermission(User $user, string $code): void
    {
        if (! $this->permissions->hasPermission($user, $code)) {
            throw ValidationException::withMessages([
                'action' => ["You do not have permission ({$code}) to perform this action."],
            ]);
        }
    }

    protected function assertModule(User $user, string $module): void
    {
        $gate = $this->erp->gateForUser($user);
        if (! $gate->enabled($module)) {
            throw ValidationException::withMessages([
                'action' => ["The {$module} module is not enabled for this organization."],
            ]);
        }
    }

    /** @param  array<string, mixed>  $params */
    protected function createSalesOrder(User $user, array $params, bool $hold): array
    {
        $channel = (string) ($params['channel'] ?? 'backend');
        $module = $channel === 'pos' ? 'sales.pos' : 'sales.backend';
        $permission = $channel === 'pos' ? 'pos.checkout.create' : 'sales.orders.create';

        $this->assertModule($user, $module);
        $this->assertPermission($user, $permission);

        $customerNum = isset($params['customer_num']) ? (int) $params['customer_num'] : 0;
        $lines = $params['lines'] ?? [];

        if ($channel !== 'pos' && $customerNum <= 0) {
            throw ValidationException::withMessages(['customer_num' => ['Customer is required for backend orders.']]);
        }
        if (! is_array($lines) || $lines === []) {
            throw ValidationException::withMessages(['lines' => ['At least one line item is required.']]);
        }

        $cartReq = AiFormRequestHelper::prepare(
            StoreCartRequest::create('/sales/carts', 'POST', [
                'channel' => $channel,
                'branch_id' => $params['branch_id'] ?? $user->branch_id,
            ]),
            $user,
        );
        $cart = app(CartOperationsController::class)->store($cartReq)->getData(true);
        $cartId = (int) ($cart['id'] ?? 0);

        foreach ($lines as $line) {
            $productCode = (string) ($line['product_code'] ?? '');
            $qty = (float) ($line['quantity'] ?? 0);
            if ($productCode === '' || $qty <= 0) {
                continue;
            }

            Product::query()
                ->where('organization_id', $user->organization_id)
                ->whereNull('deleted_at')
                ->where('product_code', $productCode)
                ->first() ?? throw ValidationException::withMessages([
                    'lines' => ["Product [{$productCode}] was not found in your catalog."],
                ]);

            $lineReq = AiFormRequestHelper::prepare(
                AddCartLineRequest::create("/sales/carts/{$cartId}/lines", 'POST', [
                    'product_code' => $productCode,
                    'quantity' => $qty,
                ]),
                $user,
            );
            app(CartOperationsController::class)->addLine($lineReq, $cartId);
        }

        $checkoutPayload = [
            'customer_num' => $customerNum > 0 ? $customerNum : null,
            'customer_name_override' => $params['customer_name_override'] ?? null,
        ];

        if ($hold) {
            $checkoutPayload['save_only'] = true;
            $checkoutPayload['pay_now'] = 0;
            $checkoutPayload['status'] = $params['status'] ?? 'held';
        } else {
            $checkoutPayload['status'] = $params['status'] ?? 'completed';
            $checkoutPayload['payment_method_code'] = $params['payment_method_code'] ?? 'CASH';
            if (array_key_exists('pay_now', $params)) {
                $checkoutPayload['pay_now'] = (float) $params['pay_now'];
            }
            if (! empty($params['is_credit_sale'])) {
                $checkoutPayload['is_credit_sale'] = true;
            }
        }

        $checkoutReq = AiFormRequestHelper::prepare(
            CheckoutRequest::create("/sales/carts/{$cartId}/checkout", 'POST', $checkoutPayload),
            $user,
        );
        $response = app(CheckoutController::class)->fromCart($checkoutReq, $cartId);
        $sale = json_decode($response->getContent(), true) ?? [];

        $message = $hold
            ? 'Held order saved successfully.'
            : 'Sales order created successfully.';

        return [
            'success' => true,
            'message' => $message,
            'result' => [
                'order_num' => $sale['order_num'] ?? null,
                'sale_id' => $sale['id'] ?? null,
                'status' => $sale['status'] ?? null,
                'path' => isset($sale['id']) ? '/sales/orders/'.$sale['id'] : '/sales/orders',
            ],
        ];
    }

    /** @param  array<string, mixed>  $params */
    protected function createProduct(User $user, array $params): array
    {
        $this->assertPermission($user, 'catalogue.products.create');

        $productCode = trim((string) ($params['product_code'] ?? ''));
        $productName = trim((string) ($params['product_name'] ?? ''));
        if ($productName === '') {
            throw ValidationException::withMessages(['product_name' => ['Product name is required.']]);
        }

        $payload = array_filter([
            'product_code' => $productCode !== '' ? $productCode : null,
            'product_name' => $productName,
            'unit_price' => $params['unit_price'] ?? 0,
            'unit_id' => $params['unit_id'] ?? null,
            'subcategory_id' => $params['subcategory_id'] ?? null,
            'last_cost_price' => $params['last_cost_price'] ?? null,
            'reorder_point' => $params['reorder_point'] ?? null,
            'vat_id' => $params['vat_id'] ?? null,
            'sell_on_retail' => $params['sell_on_retail'] ?? null,
            'organization_id' => $user->organization_id,
        ], fn ($v) => $v !== null && $v !== '');

        $payload = array_merge($this->defaultProductFields($user), $payload);

        foreach (['unit_id', 'subcategory_id', 'vat_id', 'supplier_id'] as $intField) {
            if (isset($payload[$intField]) && $payload[$intField] !== '') {
                $payload[$intField] = (int) $payload[$intField];
            }
        }
        if (isset($payload['unit_price'])) {
            $payload['unit_price'] = (float) $payload['unit_price'];
        }

        if (empty($payload['subcategory_id']) || empty($payload['unit_id']) || empty($payload['vat_id'])) {
            throw ValidationException::withMessages([
                'form' => ['Subcategory, unit of measure, and VAT rate are required.'],
            ]);
        }

        $req = Request::create('/products', 'POST', $payload);
        $req->setUserResolver(fn () => $user);

        $response = app(ProductController::class)->store($req);
        /** @var array<string, mixed> $product */
        $product = json_decode($response->getContent(), true) ?? [];
        $code = (string) ($product['product_code'] ?? $productCode);

        if ($code === '') {
            throw ValidationException::withMessages([
                'product' => ['Product could not be created. Check required fields and try again.'],
            ]);
        }

        return [
            'success' => true,
            'message' => 'Product created successfully.',
            'result' => [
                'product_code' => $code,
                'product_name' => $product['product_name'] ?? $productName,
                'path' => '/products/'.rawurlencode($code),
            ],
        ];
    }

    /** @param  array<string, mixed>  $params */
    protected function createEmployee(User $user, array $params): array
    {
        $this->assertModule($user, 'hr_payroll');
        $this->assertPermission($user, 'hr.employees.create');

        $payload = [
            'organization_id' => $user->organization_id,
            'branch_id' => $params['branch_id'] ?? $user->branch_id,
            'first_name' => $params['first_name'] ?? null,
            'last_name' => $params['last_name'] ?? null,
            'email' => $params['email'] ?? null,
            'phone' => $params['phone'] ?? null,
            'job_title' => $params['job_title'] ?? null,
            'department_id' => $params['department_id'] ?? null,
            'shift_id' => $params['shift_id'] ?? null,
            'base_salary' => $params['base_salary'] ?? 0,
            'hire_date' => $params['hire_date'] ?? now()->toDateString(),
            'employment_status' => 'active',
            'employment_type' => $params['employment_type'] ?? 'permanent',
        ];

        $req = Request::create('/employees', 'POST', array_filter(
            $payload,
            fn ($v) => $v !== null && $v !== '',
        ));
        $req->setUserResolver(fn () => $user);

        /** @var Employee $employee */
        $employee = app(EmployeeController::class)->store($req)->getData(true);

        return [
            'success' => true,
            'message' => 'Employee created successfully.',
            'result' => [
                'employee_id' => $employee['id'] ?? null,
                'employee_code' => $employee['employee_code'] ?? null,
                'full_name' => $employee['full_name'] ?? null,
                'path' => isset($employee['id']) ? '/hr/employees/'.$employee['id'] : '/hr/employees',
            ],
        ];
    }

    /** @param  array<string, mixed>  $params */
    protected function createReportTemplate(User $user, array $params): array
    {
        $this->assertModule($user, 'reports');
        $this->assertPermission($user, 'reports.builder.create');

        $spec = is_array($params['spec'] ?? null) ? $params['spec'] : null;
        if (! $spec) {
            throw ValidationException::withMessages(['spec' => ['Report specification is required.']]);
        }

        $req = Request::create('/reports/builder/templates', 'POST', [
            'name' => $params['name'] ?? 'AI report',
            'description' => $params['description'] ?? 'Created by AI assistant',
            'spec' => $spec,
            'is_shared' => (bool) ($params['is_shared'] ?? false),
        ]);
        $req->setUserResolver(fn () => $user);

        /** @var CustomReportTemplate $template */
        $template = app(ReportBuilderController::class)->storeTemplate($req)->getData(true);

        return [
            'success' => true,
            'message' => 'Report template saved.',
            'result' => [
                'template_id' => $template['id'] ?? null,
                'name' => $template['name'] ?? null,
                'path' => isset($template['id']) ? '/reports/custom/'.$template['id'] : '/reports/builder',
            ],
        ];
    }

    /** @param  array<string, mixed>  $params */
    protected function recordCustomerPayment(User $user, array $params): array
    {
        $this->assertModule($user, 'payments');
        $this->assertPermission($user, 'payments.manage');

        $sale = $this->resolveSaleForPayment($user, $params);
        $balance = round((float) $sale->order_total - (float) $sale->amount_paid, 2);

        if ($balance <= 0) {
            throw ValidationException::withMessages([
                'sale_id' => ['This sale is already fully paid.'],
            ]);
        }

        $amount = isset($params['amount']) && $params['amount'] !== '' && $params['amount'] !== null
            ? round((float) $params['amount'], 2)
            : $balance;

        if (! empty($params['mark_paid_full'])) {
            $amount = $balance;
        }

        if ($amount <= 0 || $amount > $balance + 0.01) {
            throw ValidationException::withMessages([
                'amount' => ["Payment amount must be between 0.01 and {$balance} KES."],
            ]);
        }

        $paymentMethodId = $params['payment_method_id'] ?? null;
        if (! $paymentMethodId) {
            $code = (string) ($params['payment_method_code'] ?? 'CASH');
            $paymentMethodId = PaymentMethod::query()
                ->where('method_code', $code)
                ->value('id');
        }

        if (! $paymentMethodId) {
            throw ValidationException::withMessages([
                'payment_method_id' => ['Payment method is required.'],
            ]);
        }

        $req = Request::create("/sales/{$sale->id}/payments", 'POST', [
            'payment_method_id' => (int) $paymentMethodId,
            'amount' => $amount,
            'reference_number' => $params['reference_number'] ?? null,
        ]);
        $req->setUserResolver(fn () => $user);

        $response = app(PaymentOperationsController::class)->paySale($req, $sale->id);
        /** @var array<string, mixed> $updated */
        $updated = json_decode($response->getContent(), true) ?? [];

        $paidInFull = $amount + 0.01 >= $balance;

        return [
            'success' => true,
            'message' => $paidInFull
                ? 'Payment recorded — sale marked as paid.'
                : 'Partial payment recorded successfully.',
            'result' => [
                'sale_id' => $sale->id,
                'order_num' => $updated['order_num'] ?? $sale->order_num,
                'amount_paid' => $amount,
                'payment_status' => $updated['payment_status'] ?? null,
                'path' => '/sales/orders/'.$sale->id,
            ],
        ];
    }

    /** @param  array<string, mixed>  $params */
    protected function resolveSaleForPayment(User $user, array $params): Sale
    {
        $orgId = $user->organization_id;

        if (! empty($params['sale_id'])) {
            return Sale::query()
                ->where('organization_id', $orgId)
                ->findOrFail((int) $params['sale_id']);
        }

        if (! empty($params['order_num'])) {
            $sale = Sale::query()
                ->where('organization_id', $orgId)
                ->where('order_num', trim((string) $params['order_num']))
                ->first();

            if ($sale) {
                return $sale;
            }
        }

        throw ValidationException::withMessages([
            'sale_id' => ['Select an order with an outstanding balance, or provide a valid order number.'],
        ]);
    }

    /** @return array<string, mixed>|null */
    public static function parseActionBlock(string $reply): ?array
    {
        if (preg_match('/```action\s*([\s\S]*?)```/i', $reply, $m)) {
            $decoded = json_decode(trim($m[1]), true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    public static function stripActionBlock(string $reply): string
    {
        return trim(preg_replace('/```action\s*[\s\S]*?```/i', '', $reply) ?? $reply);
    }

    public function isConfirmation(string $message): bool
    {
        return (bool) preg_match('/^(yes|yeah|yep|confirm|proceed|go ahead|do it|create it|ok|okay)\b/i', trim($message));
    }

    /** @return array<string, mixed> */
    protected function defaultProductFields(User $user): array
    {
        $sample = Product::query()
            ->where('organization_id', $user->organization_id)
            ->whereNull('deleted_at')
            ->first(['subcategory_id', 'unit_id', 'vat_id']);

        if ($sample) {
            return [
                'subcategory_id' => $sample->subcategory_id,
                'unit_id' => $sample->unit_id,
                'vat_id' => $sample->vat_id,
            ];
        }

        return [
            'subcategory_id' => (int) DB::table('subcategories')->orderBy('id')->value('id'),
            'unit_id' => (int) DB::table('uoms')->orderBy('id')->value('id'),
            'vat_id' => (int) DB::table('vats')->orderBy('id')->value('id'),
        ];
    }
}
