<?php

namespace App\Services\Ai;

use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\Operations\CartOperationsController;
use App\Http\Controllers\Api\V1\Operations\CheckoutController;
use App\Http\Controllers\Api\V1\Operations\PaymentOperationsController;
use App\Http\Controllers\Api\V1\Operations\ReportBuilderController;
use App\Http\Controllers\Api\V1\LpoMstController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Requests\Sales\AddCartLineRequest;
use App\Http\Requests\Sales\CheckoutRequest;
use App\Http\Requests\Sales\StoreCartRequest;
use App\Models\CustomReportTemplate;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
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
            'create_supplier' => $this->createSupplier($user, $params),
            'create_lpo' => $this->createLpo($user, $params),
            'create_customer' => $this->createCustomer($user, $params),
            'create_employee' => $this->createEmployee($user, $params),
            'create_report_template' => $this->createReportTemplate($user, $params),
            'record_customer_payment' => $this->recordCustomerPayment($user, $params),
            'navigate_orders', 'open_lpo', 'open_customer_collections', 'open_product' => $this->navigateConfirm($type, $params),
            default => [
                'success' => false,
                'message' => 'Unknown action type.',
            ],
        };
    }

    /**
     * Closed-loop insight actions: confirm opens a deep link (no silent mutations).
     *
     * @param  array<string, mixed>  $params
     * @return array{success: bool, message: string, result?: array<string, mixed>}
     */
    protected function navigateConfirm(string $type, array $params): array
    {
        $href = trim((string) ($params['href'] ?? ''));
        if ($href === '' || ! str_starts_with($href, '/')) {
            $href = match ($type) {
                'open_lpo' => '/lpo',
                'open_customer_collections' => ! empty($params['customer_num'])
                    ? '/customers/'.rawurlencode((string) $params['customer_num'])
                    : '/reports/ar-aging',
                'open_product' => ! empty($params['product_code'])
                    ? '/products/'.rawurlencode((string) $params['product_code'])
                    : '/products',
                default => '/sales/orders',
            };
            if ($type === 'navigate_orders' && ! empty($params['q'])) {
                $href .= '?q='.rawurlencode((string) $params['q']);
            }
        }

        $label = match ($type) {
            'open_lpo' => 'Purchase orders (LPO)',
            'open_customer_collections' => 'Customer collections',
            'open_product' => 'Product',
            default => 'Filtered sales orders',
        };

        return [
            'success' => true,
            'message' => "Ready: {$label}. Use the link below to open it.",
            'result' => [
                'navigate' => true,
                'path' => $href,
                'href' => $href,
                'q' => $params['q'] ?? null,
                'note' => $params['note'] ?? null,
            ],
        ];
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
        $permission = $channel === 'pos' ? 'sales.create' : 'sales.orders.create';

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
            'unit_price' => $this->numericParam($params, 'unit_price', 0),
            'unit_id' => $params['unit_id'] ?? null,
            'subcategory_id' => $params['subcategory_id'] ?? null,
            'last_cost_price' => $this->numericParam($params, 'last_cost_price'),
            'product_weight' => $this->numericParam($params, 'product_weight'),
            'reorder_point' => $this->numericParam($params, 'reorder_point'),
            'vat_id' => $params['vat_id'] ?? null,
            'supplier_id' => $params['supplier_id'] ?? null,
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
    protected function createSupplier(User $user, array $params): array
    {
        $this->assertModule($user, 'customers_suppliers');
        $this->assertPermission($user, 'purchasing.suppliers.create');

        $supplierName = trim((string) ($params['supplier_name'] ?? ''));
        if ($supplierName === '') {
            throw ValidationException::withMessages(['supplier_name' => ['Supplier name is required.']]);
        }

        $payload = array_filter([
            'supplier_name' => $supplierName,
            'contact_person' => $params['contact_person'] ?? null,
            'phone' => $params['phone'] ?? null,
            'alternate_phone' => $params['alternate_phone'] ?? null,
            'email' => $params['email'] ?? null,
            'town' => $params['town'] ?? null,
            'tax_pin' => $params['tax_pin'] ?? null,
            'address' => $params['address'] ?? null,
            'is_active' => true,
            'organization_id' => $user->organization_id,
        ], fn ($v) => $v !== null && $v !== '');

        $req = Request::create('/suppliers', 'POST', $payload);
        $req->setUserResolver(fn () => $user);

        /** @var Supplier $supplier */
        $supplier = app(SupplierController::class)->store($req)->getData(true);

        return [
            'success' => true,
            'message' => 'Supplier created successfully.',
            'result' => [
                'supplier_id' => $supplier['id'] ?? null,
                'supplier_name' => $supplier['supplier_name'] ?? $supplierName,
                'path' => isset($supplier['id']) ? '/suppliers/'.$supplier['id'] : '/suppliers',
            ],
        ];
    }

    /**
     * Create a purchase order (LPO) via POST /lpo-mst/full.
     * Lines may be supplied directly, or seeded from a sales order (sale_id / order_num)
     * using each product's last cost price — supplier is still required.
     *
     * @param  array<string, mixed>  $params
     */
    protected function createLpo(User $user, array $params): array
    {
        $this->assertModule($user, 'customers_suppliers');
        $this->assertPermission($user, 'purchasing.lpo.create');

        $supplierId = (int) ($params['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            throw ValidationException::withMessages([
                'supplier_id' => ['Select a supplier for this purchase order.'],
            ]);
        }

        Supplier::query()
            ->where('organization_id', $user->organization_id)
            ->whereKey($supplierId)
            ->first() ?? throw ValidationException::withMessages([
                'supplier_id' => ['Supplier was not found in your organization.'],
            ]);

        $lines = is_array($params['lines'] ?? null) ? $params['lines'] : [];
        $fromSale = null;
        if ($lines === []) {
            $seeded = $this->lpoLinesFromSale($user, $params);
            $lines = $seeded['lines'];
            $fromSale = $seeded['sale'];
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => ['Add at least one product line, or provide a sales order number to copy products from.'],
            ]);
        }

        $normalized = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $productCode = trim((string) ($line['product_code'] ?? ''));
            $qty = (float) ($line['ordered_qty'] ?? $line['quantity'] ?? 0);
            if ($productCode === '' || $qty <= 0) {
                continue;
            }

            $product = Product::query()
                ->where('organization_id', $user->organization_id)
                ->whereNull('deleted_at')
                ->where('product_code', $productCode)
                ->first();
            if (! $product) {
                throw ValidationException::withMessages([
                    'lines' => ["Product [{$productCode}] was not found in your catalog."],
                ]);
            }

            $cost = array_key_exists('cost_price', $line) && $line['cost_price'] !== '' && $line['cost_price'] !== null
                ? (float) $line['cost_price']
                : (float) ($product->last_cost_price ?? 0);

            $normalized[] = [
                'product_code' => $productCode,
                'ordered_qty' => $qty,
                'cost_price' => $cost,
                'uom' => $line['uom'] ?? null,
            ];
        }

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'lines' => ['No valid product lines were provided.'],
            ]);
        }

        $reference = trim((string) ($params['reference_number'] ?? ''));
        if ($reference === '' && $fromSale) {
            $orderLabel = $fromSale->order_num
                ?: $fromSale->pos_order_num
                ?: ('Sale #'.$fromSale->id);
            $reference = 'From order '.$orderLabel;
        }

        $payload = array_filter([
            'supplier_id' => $supplierId,
            'branch_id' => $params['branch_id'] ?? $user->branch_id,
            'reference_number' => $reference !== '' ? $reference : null,
            'due_date' => $params['due_date'] ?? null,
            'delivery_address' => $params['delivery_address'] ?? null,
            'terms' => $params['terms'] ?? null,
            'instructions' => $params['instructions'] ?? null,
            'lines' => $normalized,
        ], fn ($v) => $v !== null && $v !== '');

        $req = Request::create('/lpo-mst/full', 'POST', $payload);
        $req->setUserResolver(fn () => $user);

        $data = app(LpoMstController::class)->storeFull($req)->getData(true);
        $lpoNo = $data['lpo_no'] ?? $data['lpo']['lpo_no'] ?? null;

        return [
            'success' => true,
            'message' => $lpoNo
                ? "Purchase order (LPO) {$lpoNo} created."
                : 'Purchase order (LPO) created.',
            'result' => [
                'lpo_no' => $lpoNo,
                'supplier_id' => $supplierId,
                'line_count' => count($normalized),
                'from_sale_id' => $fromSale?->id,
                'path' => $lpoNo ? '/lpo/'.$lpoNo : '/lpo',
            ],
        ];
    }

    /**
     * Seed LPO lines from a sales order's products (quantities + catalog cost).
     *
     * @param  array<string, mixed>  $params
     * @return array{lines: list<array<string, mixed>>, sale: ?Sale}
     */
    protected function lpoLinesFromSale(User $user, array $params): array
    {
        $saleId = (int) ($params['sale_id'] ?? 0);
        $orderRef = trim((string) ($params['order_num'] ?? $params['order_ref'] ?? $params['sale_ref'] ?? ''));

        $query = Sale::query()
            ->with('items')
            ->where('organization_id', $user->organization_id)
            ->whereNull('deleted_at');

        if ($saleId > 0) {
            $sale = (clone $query)->whereKey($saleId)->first();
        } elseif ($orderRef !== '') {
            $sale = (clone $query)
                ->where(function ($q) use ($orderRef) {
                    $q->where('order_num', $orderRef)
                        ->orWhere('pos_order_num', $orderRef)
                        ->orWhere('id', ctype_digit($orderRef) ? (int) $orderRef : 0);
                })
                ->orderByDesc('id')
                ->first();
        } else {
            return ['lines' => [], 'sale' => null];
        }

        if (! $sale) {
            throw ValidationException::withMessages([
                'order_num' => ['No sales order matched that reference in your organization.'],
            ]);
        }

        $lines = [];
        foreach ($sale->items as $item) {
            $code = trim((string) ($item->product_code ?? ''));
            $qty = (float) ($item->quantity ?? 0);
            if ($code === '' || $qty <= 0) {
                continue;
            }
            $lines[] = [
                'product_code' => $code,
                'ordered_qty' => $qty,
                'uom' => $item->uom,
            ];
        }

        return ['lines' => $lines, 'sale' => $sale];
    }

    /** @param  array<string, mixed>  $params */
    protected function createCustomer(User $user, array $params): array
    {
        $this->assertModule($user, 'customers_suppliers');
        $this->assertPermission($user, 'customers.customers.create');

        $customerName = trim((string) ($params['customer_name'] ?? ''));
        if ($customerName === '') {
            throw ValidationException::withMessages(['customer_name' => ['Customer name is required.']]);
        }

        $payload = array_filter([
            'customer_name' => $customerName,
            'customer_type' => $params['customer_type'] ?? 'debtor',
            'phone_number' => $params['phone_number'] ?? null,
            'additional_phone' => $params['additional_phone'] ?? null,
            'town' => $params['town'] ?? null,
            'route_id' => $params['route_id'] ?? null,
            'credit_limit' => $this->numericParam($params, 'credit_limit'),
            'kra_pin' => $params['kra_pin'] ?? null,
            'terms_of_payment' => $params['terms_of_payment'] ?? null,
            'branch_id' => $params['branch_id'] ?? $user->branch_id,
            'organization_id' => $user->organization_id,
        ], fn ($v) => $v !== null && $v !== '');

        $req = Request::create('/customers', 'POST', $payload);
        $req->setUserResolver(fn () => $user);

        /** @var Customer $customer */
        $customer = app(CustomerController::class)->store($req)->getData(true);

        return [
            'success' => true,
            'message' => 'Customer created successfully.',
            'result' => [
                'customer_num' => $customer['customer_num'] ?? null,
                'customer_name' => $customer['customer_name'] ?? $customerName,
                'path' => isset($customer['customer_num']) ? '/customers/'.$customer['customer_num'] : '/customers',
            ],
        ];
    }

    /** @param  array<string, mixed>  $params */
    protected function numericParam(array $params, string $key, ?float $default = null): ?float
    {
        if (! array_key_exists($key, $params) || $params[$key] === '' || $params[$key] === null) {
            return $default;
        }

        return (float) $params[$key];
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
        $instruction = trim((string) ($params['instruction'] ?? $params['description'] ?? ''));
        $workspaceId = trim((string) ($params['workspace_id'] ?? ''));

        if (! $spec) {
            if ($instruction === '') {
                throw ValidationException::withMessages([
                    'spec' => ['Describe what the report should show, or provide a report specification.'],
                ]);
            }
            if ($workspaceId === '') {
                $workspaceId = $this->workspaceFromInstruction($instruction);
            }
            $draft = app(\App\Services\Reports\ReportBuilderSuggestService::class)
                ->localDraft($user, $instruction, $workspaceId !== '' ? $workspaceId : null);
            $spec = is_array($draft['spec'] ?? null) ? $draft['spec'] : null;
            if (! $spec) {
                throw ValidationException::withMessages([
                    'spec' => ['Could not build a report specification from that description.'],
                ]);
            }
        }

        if ($workspaceId === '') {
            $workspaceId = $this->workspaceFromSpec($spec);
        }

        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => ['Please choose a name for this report.'],
            ]);
        }

        $payload = [
            'name' => $name,
            'description' => $params['description'] ?? ($instruction !== '' ? $instruction : 'Created by AI assistant'),
            'spec' => $spec,
            'is_shared' => (bool) ($params['is_shared'] ?? false),
            'workspace_id' => $workspaceId,
        ];

        $req = Request::create('/reports/builder/templates', 'POST', $payload);
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

    protected function workspaceFromInstruction(string $instruction): string
    {
        $text = mb_strtolower($instruction);
        if (str_contains($text, 'attendance') || str_contains($text, 'payroll') || str_contains($text, 'employee') || str_contains($text, 'leave')) {
            return 'hr';
        }
        if (str_contains($text, 'journal') || str_contains($text, 'ledger') || str_contains($text, 'expense')) {
            return 'accounting';
        }
        if (str_contains($text, 'dispatch') || str_contains($text, 'trip') || str_contains($text, 'driver')) {
            return 'distribution';
        }

        return 'backoffice';
    }

    /** @param  array<string, mixed>  $spec */
    protected function workspaceFromSpec(array $spec): string
    {
        $source = (string) ($spec['source'] ?? (($spec['sources'][0] ?? null)));
        $module = (string) (config("report_builder.sources.{$source}.module") ?? '');

        return match ($module) {
            'HR' => 'hr',
            'Accounting', 'Payments' => 'accounting',
            'Logistics' => 'distribution',
            default => 'backoffice',
        };
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

    /** Read-only deep links — never require a Confirm button. */
    public function isNavigationAction(string $type): bool
    {
        return in_array($type, [
            'navigate_orders',
            'open_lpo',
            'open_customer_collections',
            'open_product',
        ], true);
    }

    /** Write/create actions that may use an inline form or Confirm. */
    public function isWriteAction(string $type): bool
    {
        return str_starts_with($type, 'create_') || $type === 'record_customer_payment';
    }

    /** User explicitly wants the inline confirmation form (not chat-only collection). */
    public function wantsFormUi(string $message): bool
    {
        $text = strtolower(trim($message));
        if (preg_match('/\b(show|open|use|display|bring up|load|give me|i want|prefer)\b.{0,25}\b(form|form ui)\b/i', $text)) {
            return true;
        }
        if (preg_match('/\b(form|form ui)\b.{0,25}\b(please|now|instead|yes|yeah)\b/i', $text)) {
            return true;
        }
        if (preg_match('/\b(yes|yeah|yep|ok|okay)\b.{0,15}\b(form|form ui)\b/i', $text)) {
            return true;
        }

        return (bool) preg_match('/\b(use|with)\s+(?:the\s+)?form\b/i', $text);
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
