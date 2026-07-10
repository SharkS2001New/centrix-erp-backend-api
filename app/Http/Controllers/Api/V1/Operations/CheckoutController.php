<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesCartAccess;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesCartPayments;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\LoyaltyCard;
use App\Models\Voucher;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CheckoutRequest;
use App\Models\CartLine;
use App\Models\Customer;
use App\Services\Sales\SaleRouteResolver;
use App\Models\KraResponse;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockReservation;
use App\Models\SalePayment;
use App\Models\TemporaryCart;
use App\Models\User;
use App\Services\Auth\UserMobileOrderScopeService;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use App\Services\Erp\FloatSessionValidator;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\SaleJournalService;
use App\Services\Erp\SalePaymentColumnMapper;
use App\Services\Fulfillment\AutoTripAssignmentService;
use App\Services\Kra\KraDeviceFailure;
use App\Services\Kra\KraDeviceService;
use App\Services\Kra\KraFiscalPolicy;
use App\Services\Notifications\CustomerNotificationService;
use App\Services\Sales\DiscountApprovalService;
use App\Services\Sales\MobileCheckoutLocationService;
use App\Services\Sales\MobileCheckoutSettings;
use App\Services\Sales\MobileRouteMarkupCheckoutService;
use App\Services\Sales\OrderNumberAllocator;
use App\Support\CustomerCreditLimit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CheckoutController extends Controller
{
    use HandlesCartAccess;
    use HandlesCartPayments;
    use HandlesInventory;

    public function __construct(protected ErpContext $erp) {}

    public function fromCart(CheckoutRequest $request, int $cartId)
    {
        $cart = $this->findOwnedCart($cartId, $request->user());
        $gate = $this->erp->gateForUser($request->user());
        try {
            $sale = $this->checkoutFromCart($cart, $request->user(), $gate, $request->validated());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'checkout' => $e->getMessage(),
            ]);
        }

        if ($sale->status !== 'pending_approval') {
            app(AutoTripAssignmentService::class)->tryAssignSale($sale, $request->user());
        }

        $sale = $sale->fresh(['items', 'payments.paymentMethod']);
        $labels = config('erp.order_status_labels', []);

        return response()->json(array_merge($sale->toArray(), [
            'status_name' => $labels[$sale->status]
                ?? ucfirst(str_replace('_', ' ', (string) $sale->status)),
        ]), 201);
    }

    public function quoteFromCart(\Illuminate\Http\Request $request, int $cartId)
    {
        $cart = $this->findOwnedCart($cartId, $request->user());
        $gate = $this->erp->gateForUser($request->user());
        $data = $request->validate([
            'customer_num' => 'required|integer|min:1',
        ]);

        $lines = CartLine::where('cart_id', $cart->id)->get();
        if ($lines->isEmpty()) {
            throw new InvalidArgumentException('Cart is empty.');
        }

        $user = $request->user();
        if ((string) $cart->channel === 'mobile') {
            app(UserMobileOrderScopeService::class)->findCheckoutCustomer(
                $user,
                (int) $data['customer_num'],
                (string) $cart->channel,
            );
        }

        $routeId = $this->resolveCheckoutRouteId(
            $cart,
            (int) $data['customer_num'],
            $gate,
        );

        $prepared = app(MobileRouteMarkupCheckoutService::class)->prepareCheckoutLines(
            $cart,
            $lines,
            $routeId,
            $gate,
        );

        $salesSettings = $gate->moduleSettings('sales');
        $lineNet = (float) $prepared['order_total'];
        $orderDiscount = 0.0;
        if (app(DiscountApprovalService::class)->allowsOrderDiscount(
            $salesSettings,
            $request->user(),
            (string) ($cart->channel ?? $cart->order_source ?? 'backend'),
        )) {
            $orderDiscount = min(max(0, (float) ($cart->order_discount ?? 0)), $lineNet);
        }

        return response()->json([
            'order_total' => max(0, round($lineNet - $orderDiscount, 2)),
            'line_total' => $lineNet,
            'order_discount' => $orderDiscount,
            'route_id' => $routeId,
            'route_markup_applied' => $prepared['meta'] !== null,
            'route_markup' => $prepared['meta'],
        ]);
    }

    protected function checkoutFromCart(TemporaryCart $cart, User $user, CapabilityGate $gate, array $input): Sale
    {
        $lines = CartLine::where('cart_id', $cart->id)->get();
        if ($lines->isEmpty()) {
            throw new InvalidArgumentException('Cart is empty.');
        }

        app(DiscountApprovalService::class)->assertCheckoutAllowed(
            $cart,
            $user,
            $gate,
            isset($input['discount_approval_reason']) ? (string) $input['discount_approval_reason'] : null,
        );

        $inventorySettings = $gate->moduleSettings('inventory');
        $salesSettings = $gate->moduleSettings('sales');
        $txnType = $this->saleTransactionType($cart->channel);

        $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);

        return DB::transaction(function () use ($cart, $user, $gate, $input, $lines, $inventorySettings, $salesSettings, $txnType, $allowBelowStock) {
            $stockDeducted = false;
            $customerNum = $input['customer_num'] ?? null;
            $loyaltyCardIdEarly = $cart->loyalty_card_id ? (int) $cart->loyalty_card_id : null;
            if (! $customerNum && $loyaltyCardIdEarly) {
                $customerNum = LoyaltyCard::find($loyaltyCardIdEarly)?->customer_num;
            }
            $orderNum = isset($input['order_num'])
                ? (int) $input['order_num']
                : ($cart->held_order_num
                    ? (int) $cart->held_order_num
                    : app(OrderNumberAllocator::class)->nextForOrganization((int) $user->organization_id));

            $routeId = $this->resolveCheckoutRouteId($cart, $customerNum ? (int) $customerNum : null, $gate);
            app(UserMobileOrderScopeService::class)->assertCheckoutRoute($user, (string) $cart->channel, $routeId);

            $prepared = app(MobileRouteMarkupCheckoutService::class)->prepareCheckoutLines(
                $cart,
                $lines,
                $routeId,
                $gate,
            );
            $lines = $prepared['lines'];
            $lineNet = (float) $prepared['order_total'];
            $vat = (float) ($input['total_vat'] ?? $prepared['total_vat']);
            $isCredit = (bool) ($input['is_credit_sale'] ?? false);
            $payNow = (float) ($input['pay_now'] ?? 0);

            $orderDiscount = 0.0;
            $discountService = app(DiscountApprovalService::class);
            $salesChannel = (string) ($cart->channel ?? $cart->order_source ?? 'backend');
            if (! empty($salesSettings['enable_vouchers']) && $cart->discount_voucher_id) {
                $discountVoucher = Voucher::find($cart->discount_voucher_id);
                if ($discountVoucher && $discountVoucher->voucher_kind === 'discount') {
                    $orderDiscount = min(max(0, (float) ($cart->order_discount ?? 0)), $lineNet);
                }
            } elseif (
                $discountService->allowsOrderDiscount($salesSettings, $user, $salesChannel)
                || (
                    (float) ($cart->order_discount ?? 0) > 0.01
                    && $discountService->discountApprovalEnabled($salesSettings, $salesChannel)
                )
            ) {
                // Staff in approval mode cannot free-apply order discounts, but checkout must
                // keep the amount already stored on the cart for pending-approval sales.
                $orderDiscount = min(max(0, (float) ($cart->order_discount ?? 0)), $lineNet);
            }
            $total = max(0, $lineNet - $orderDiscount);

            $voucherPayment = 0.0;
            if (! empty($salesSettings['enable_vouchers']) && $cart->payment_voucher_id) {
                $voucher = Voucher::find($cart->payment_voucher_id);
                if ($voucher) {
                    $voucherPayment = min(
                        max(0, (float) ($cart->voucher_payment_amount ?? 0)),
                        (float) $voucher->balance,
                        $total,
                    );
                }
            }

            $pointsPayment = 0.0;
            $loyaltyCardId = $cart->loyalty_card_id ? (int) $cart->loyalty_card_id : null;
            $pointsRedeemed = 0.0;
            if (! empty($salesSettings['enable_redeemable_points']) && $loyaltyCardId) {
                $card = LoyaltyCard::find($loyaltyCardId);
                if ($card) {
                    $remaining = max(0, $total - $voucherPayment);
                    $maxPointsCash = $this->pointsCashValue($salesSettings, (float) $card->points_balance);
                    $pointsPayment = min(
                        max(0, (float) ($cart->points_payment_amount ?? 0)),
                        $maxPointsCash,
                        $remaining,
                    );
                    $rate = max(0.0001, (float) ($salesSettings['point_cash_value'] ?? 1));
                    $pointsRedeemed = min((float) ($cart->points_redeemed ?? 0), $pointsPayment / $rate);
                } else {
                    $loyaltyCardId = null;
                }
            }

            $cashDue = max(0, $total - $voucherPayment - $pointsPayment);
            $isMobileChannel = (string) $cart->channel === 'mobile';
            $mobileCheckout = app(MobileCheckoutSettings::class);
            $mobileCheckout->applyCheckoutPolicy($salesSettings, $input, (string) $cart->channel);

            if (! $isCredit && $payNow <= 0 && $cashDue > 0 && empty($input['save_only'])) {
                if ($isMobileChannel) {
                    if ($mobileCheckout->shouldDefaultMobileSaveOnly(
                        $salesSettings,
                        (string) $cart->channel,
                        false,
                    )) {
                        $input['save_only'] = true;
                    } elseif ($mobileCheckout->requiresPaymentAtCheckout($salesSettings, (string) $cart->channel)) {
                        throw new InvalidArgumentException(
                            'Enter payment details to complete this order.',
                        );
                    } else {
                        $input['save_only'] = true;
                    }
                } else {
                    $payNow = $cashDue;
                }
            }
            $payNow = min($payNow, $cashDue);
            $amountPaid = $payNow + $voucherPayment + $pointsPayment;
            if (! $customerNum && $loyaltyCardId) {
                $customerNum = LoyaltyCard::find($loyaltyCardId)?->customer_num;
            }

            $workflow = OrderWorkflowService::forGate($gate);
            $channelWorkflow = $workflow->forChannel($cart->channel);
            $allowPartialPayment = false;
            $paymentMethodCode = (string) ($input['payment_method_code'] ?? 'CASH');

            $isSaveOnly = $payNow <= 0 && ! $isCredit && ! empty($input['save_only']);
            if ($isSaveOnly) {
                $requested = isset($input['status']) && is_string($input['status']) ? $input['status'] : null;
                if ($requested === 'held') {
                    $orderStatus = 'held';
                } else {
                    $orderStatus = $workflow->resolveSaveStatus($cart->channel);
                }
            } elseif ($payNow > 0 || $isCredit) {
                $orderStatus = $workflow->resolveCheckoutStatus(
                    $cart->channel,
                    $isCredit,
                    $payNow,
                    $total,
                    $paymentMethodCode,
                    $allowPartialPayment,
                );
            } elseif (isset($input['status']) && is_string($input['status'])) {
                if ($input['status'] === 'held') {
                    throw new InvalidArgumentException('Held status is only allowed when holding an order (save_only).');
                }
                $orderStatus = $workflow->pickEnabledStatus($input['status'], $channelWorkflow);
            } else {
                $orderStatus = $workflow->resolveSaveStatus($cart->channel);
            }

            if (! $workflow->isAllowedStatus($orderStatus, $cart->channel)) {
                throw new InvalidArgumentException("Status [{$orderStatus}] is not allowed for this channel.");
            }

            if ($orderStatus === 'cancelled') {
                throw new InvalidArgumentException('Checkout cannot create a cancelled order.');
            }

            $discountApproval = app(DiscountApprovalService::class);
            $pendingDiscountApproval = $discountApproval->checkoutRequiresPendingApproval($cart, $user, $gate);
            if ($pendingDiscountApproval) {
                $orderStatus = 'pending_approval';
            }

            $floatSessionId = FloatSessionValidator::forUser($user)->resolveForCheckout($cart, $user, $input);

            $creditBalance = $isCredit ? max(0, $total - $amountPaid) : 0;
            CustomerCreditLimit::assertCreditSaleAllowed(
                $customerNum ? (int) $customerNum : null,
                $creditBalance,
                $isCredit,
            );

            $customer = $customerNum
                ? app(UserMobileOrderScopeService::class)->findCheckoutCustomer(
                    $user,
                    (int) $customerNum,
                    (string) $cart->channel,
                )
                : null;
            $locationMeta = app(MobileCheckoutLocationService::class)->assertCheckoutLocation(
                (string) $cart->channel,
                $salesSettings,
                $customer,
                $input,
            );

            $customerNameOverride = trim((string) ($input['customer_name_override'] ?? ''));
            if ($customerNameOverride === '' && $customer) {
                $customerNameOverride = trim((string) ($customer->customer_name ?? ''));
            }

            $fulfillmentMeta = $locationMeta !== [] ? ['location_check' => $locationMeta] : [];
            if ($prepared['meta'] !== null) {
                $fulfillmentMeta['route_markup'] = $prepared['meta'];
            }
            if ($cart->superseded_sale_id) {
                $fulfillmentMeta['supersedes_sale_id'] = (int) $cart->superseded_sale_id;
                $fulfillmentMeta['pos_edit'] = true;
            }
            if (! empty($input['sales_workspace'])) {
                $fulfillmentMeta['sales_workspace'] = (string) $input['sales_workspace'];
            }

            $sale = Sale::create([
                'order_num' => $orderNum,
                'branch_id' => $cart->branch_id ?? $user->branch_id,
                'organization_id' => $user->organization_id,
                'channel' => $cart->channel,
                'order_source' => $cart->order_source ?? $cart->channel,
                'till_id' => $cart->till_id,
                'float_session_id' => $floatSessionId,
                'cashier_id' => $user->id,
                'customer_num' => $customerNum,
                'customer_name_override' => $customerNameOverride !== '' ? $customerNameOverride : null,
                'route_id' => $routeId,
                'status' => $orderStatus,
                'total_vat' => $vat,
                'order_total' => $total,
                'order_discount' => $orderDiscount,
                'voucher_payment_amount' => $voucherPayment,
                'points_payment_amount' => $pointsPayment,
                'loyalty_card_id' => $loyaltyCardId,
                'payment_method_code' => $input['payment_method_code'] ?? 'CASH',
                'is_credit_sale' => $isCredit ? 1 : 0,
                'payment_status' => $this->derivePaymentStatus($total, $amountPaid),
                'amount_paid' => $amountPaid,
                'completed_at' => null,
                'fulfillment_meta' => $fulfillmentMeta !== [] ? $fulfillmentMeta : null,
            ]);

            if ($workflow->isTerminalStatus($orderStatus, (string) $cart->channel)) {
                $sale->update(['completed_at' => now()]);
            }

            $stockDeducted = false;
            $deductStockRequested = (bool) ($input['deduct_stock'] ?? true);
            $shouldDeductNow = $deductStockRequested
                && $gate->shouldDeductStockAtCheckout($workflow, $orderStatus, (string) $cart->channel);

            foreach ($lines as $i => $line) {
                $product = $this->orgProduct((int) $user->organization_id, (string) $line->product_code);
                $location = $product
                    ? $this->resolveSaleLineStockLocation(
                        (string) $cart->channel,
                        $inventorySettings,
                        $salesSettings,
                        $product,
                        (bool) $line->on_wholesale_retail,
                    )
                    : $this->saleLineStockLocation(
                        (string) $cart->channel,
                        $inventorySettings,
                        $salesSettings,
                        (bool) $line->on_wholesale_retail,
                    );

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_code' => $line->product_code,
                    'line_no' => $line->line_no ?: ($i + 1),
                    'item_code' => (string) ($line->line_no ?: ($i + 1)),
                    'quantity' => $line->quantity,
                    'uom' => $line->uom,
                    'selling_price' => $line->unit_price,
                    'display_unit_price' => $line->display_unit_price !== null
                        ? (float) $line->display_unit_price
                        : null,
                    'discount_given' => (float) ($line->discount_given ?? 0),
                    'product_vat' => $line->product_vat ?? 0,
                    'amount' => $line->amount,
                    'on_wholesale_retail' => $line->on_wholesale_retail,
                ]);

                if ($shouldDeductNow) {
                    $unitCost = max(0, (float) ($product?->last_cost_price ?? 0));
                    $this->postStockLedger([
                        'branch_id' => $sale->branch_id,
                        'product_code' => $line->product_code,
                        'stock_location' => $location,
                        'transaction_type' => $txnType,
                        'reference_type' => 'sale',
                        'reference_id' => $sale->id,
                        'quantity_change' => -abs((float) $line->quantity),
                        'unit_cost' => $unitCost > 0 ? $unitCost : null,
                        'created_by' => $user->id,
                    ], $allowBelowStock);
                    $stockDeducted = true;
                }
            }

            if (! empty($stockDeducted)) {
                $sale->update(['stock_balanced' => 1]);
                $this->releaseCartReservations((int) $cart->id);
                $this->releaseSaleReservations((int) $sale->id);
            } elseif ($gate->shouldHoldStockOnCheckout($workflow, $orderStatus, (string) $cart->channel)) {
                $transferred = StockReservation::query()
                    ->where('cart_id', $cart->id)
                    ->whereNull('released_at')
                    ->exists();
                if ($transferred) {
                    $this->transferCartReservationsToSale((int) $cart->id, (int) $sale->id);
                } else {
                    $this->reserveSaleStockIfNeeded($sale->fresh(['items']), $user, $gate);
                }
            } else {
                $this->releaseCartReservations($cart->id);
            }

            if ($voucherPayment > 0 && $cart->payment_voucher_id) {
                $voucher = Voucher::lockForUpdate()->find($cart->payment_voucher_id);
                if ($voucher) {
                    $method = PaymentMethod::where('method_code', 'VOUCHER')->first();
                    if ($method) {
                        SalePayment::create([
                            'sale_id' => $sale->id,
                            'float_session_id' => $floatSessionId,
                            'payment_method_id' => $method->id,
                            'amount' => $voucherPayment,
                            'reference_number' => $voucher->voucher_code,
                            'paid_at' => $input['payment_date'] ?? now(),
                        ]);
                    }
                    $voucher->update([
                        'balance' => max(0, (float) $voucher->balance - $voucherPayment),
                        'redemption_count' => (int) $voucher->redemption_count + 1,
                    ]);
                }
            }

            if ($orderDiscount > 0 && $cart->discount_voucher_id) {
                $discountVoucher = Voucher::lockForUpdate()->find($cart->discount_voucher_id);
                if ($discountVoucher && $discountVoucher->voucher_kind === 'discount') {
                    $discountVoucher->update([
                        'redemption_count' => (int) $discountVoucher->redemption_count + 1,
                    ]);
                }
            }

            if ($pointsPayment > 0 && $loyaltyCardId) {
                $card = LoyaltyCard::lockForUpdate()->find($loyaltyCardId);
                if ($card) {
                    $method = PaymentMethod::where('method_code', 'POINTS')->first();
                    if ($method) {
                        SalePayment::create([
                            'sale_id' => $sale->id,
                            'float_session_id' => $floatSessionId,
                            'payment_method_id' => $method->id,
                            'amount' => $pointsPayment,
                            'reference_number' => $card->card_number,
                            'paid_at' => $input['payment_date'] ?? now(),
                        ]);
                    }
                    $card->update([
                        'points_balance' => max(0, (float) $card->points_balance - $pointsRedeemed),
                    ]);
                }
            }

            if ($payNow > 0) {
                $method = PaymentMethod::where('method_code', $sale->payment_method_code)->first();
                if ($method) {
                    SalePayment::create([
                        'sale_id' => $sale->id,
                        'float_session_id' => $floatSessionId,
                        'payment_method_id' => $method->id,
                        'amount' => $payNow,
                        'reference_number' => $input['payment_reference'] ?? null,
                        'paid_at' => $input['payment_date'] ?? now(),
                    ]);
                }
                SalePaymentColumnMapper::applyToSale($sale, $paymentMethodCode, $payNow);
            }

            if ($workflow->isTerminalStatus($orderStatus, (string) $cart->channel)) {
                $this->awardLoyaltyPointsForCompletedSale(
                    (int) $sale->organization_id,
                    $customerNum ? (int) $customerNum : null,
                    $salesSettings,
                    $total,
                    $loyaltyCardId,
                );
            }

            if ($orderStatus !== 'pending_approval') {
                app(CustomerInvoiceService::class)->ensureForSale($sale, $user, $total, $amountPaid);
            } else {
                $discountApproval->attachCheckoutToSale(
                    $sale,
                    $cart,
                    $user,
                    isset($input['discount_approval_reason']) ? (string) $input['discount_approval_reason'] : null,
                );
            }

            $this->releaseCartReservations($cart->id);
            CartLine::where('cart_id', $cart->id)->delete();
            $cart->delete();

            $sale = $sale->fresh(['items', 'payments.paymentMethod']);

            $finance = $gate->moduleSettings('finance');
            $explicitSubmit = array_key_exists('submit_kra', $input)
                ? (bool) $input['submit_kra']
                : null;

            $submitKra = $orderStatus !== 'pending_approval' && KraFiscalPolicy::shouldFiscalizeSale(
                $finance,
                (float) $sale->order_total,
                $explicitSubmit,
            );

            $kraResponse = $this->submitKraForSale(
                $sale,
                $lines,
                $gate,
                $submitKra,
                $input['customer_kra_pin'] ?? null,
            );
            if ($kraResponse) {
                $sale->setRelation('kraResponse', $kraResponse);
            }

            if ($orderStatus !== 'pending_approval') {
                app(SaleJournalService::class)->postIfEnabled($sale, $user, $gate);

                $organization = Organization::find($user->organization_id);
                if ($organization) {
                    app(CustomerNotificationService::class)->notifyOrderPlaced($sale, $organization);
                }
            }

            app(\App\Services\Audit\OperationalAuditService::class)->logSaleCheckout($user, $sale);

            return $sale;
        });
    }

    protected function derivePaymentStatus(float $total, float $paid): string
    {
        if ($paid <= 0) {
            return 'unpaid';
        }
        if ($paid + 0.01 >= $total) {
            return 'paid';
        }

        return 'partial';
    }

    protected function nextOrderNum(): int
    {
        $user = request()->user();
        if ($user) {
            return app(OrderNumberAllocator::class)->nextForOrganization((int) $user->organization_id);
        }

        return (int) (Sale::query()
            ->where('order_num', '<', OrderNumberAllocator::LEGACY_IMPORTED_ORDER_NUM_MIN)
            ->max('order_num') ?? 0) + 1;
    }

    protected function submitKraForSale(
        Sale $sale,
        $lines,
        CapabilityGate $gate,
        bool $submit,
        ?string $buyerPin = null,
    ): ?KraResponse {
        if (! $submit) {
            return null;
        }

        $finance = $gate->moduleSettings('finance');
        if (empty($finance['enable_kra_device'])) {
            return null;
        }

        $service = KraDeviceService::fromSettings($finance);
        $invoiceNumber = $service->traderInvoiceForSale($sale, $finance);
        $orderItems = $lines->map(fn ($line) => [
            'product_name' => $line->product_name ?? $line->product_code,
            'product_code' => $line->product_code,
            'quantity' => (float) $line->quantity,
            'amount' => (float) $line->amount,
            'product_vat' => (float) ($line->product_vat ?? 0),
        ])->all();

        $result = $service->sendSale(
            $orderItems,
            (float) $sale->order_total,
            $invoiceNumber,
            $buyerPin,
        );

        KraDeviceFailure::abortUnlessSuccess(
            $result,
            'KRA device submission failed.',
        );

        $mapped = $result['response'] ?? [];

        return KraResponse::create([
            'sale_id' => $sale->id,
            'organization_id' => (int) $sale->organization_id,
            'order_no' => $sale->order_num,
            'invoice_number' => $mapped['invoice_number'] ?? $invoiceNumber,
            'receipt_signature' => $mapped['receipt_signature'] ?? $mapped['signature'] ?? null,
            'signature_link' => $mapped['signature_link'] ?? null,
            'serial_number' => $mapped['serial_number'] ?? null,
            'kra_timestamp' => $mapped['timestamp'] ?? null,
            'request_payload' => $result['payload'] ?? null,
            'response_payload' => $mapped,
            'status' => 'success',
        ]);
    }

    /** @deprecated Use submitKraForSale when KRA device is enabled. */
    protected function queueKraReceipt(Sale $sale, bool $submit = true): ?KraResponse
    {
        if (! $submit) {
            return null;
        }

        return KraResponse::create([
            'sale_id' => $sale->id,
            'organization_id' => (int) $sale->organization_id,
            'order_no' => $sale->order_num,
            'invoice_number' => 'PENDING-' . $sale->order_num,
            'status' => 'pending',
            'request_payload' => [
                'order_num' => $sale->order_num,
                'order_total' => $sale->order_total,
                'total_vat' => $sale->total_vat,
                'channel' => $sale->channel,
            ],
        ]);
    }

    protected function resolveCheckoutRouteId(
        TemporaryCart $cart,
        ?int $customerNum,
        CapabilityGate $gate,
    ): ?int {
        return app(SaleRouteResolver::class)->resolveFromCustomer(
            $customerNum,
            $gate,
            (string) $cart->channel,
            $cart->route_id ? (int) $cart->route_id : null,
        );
    }
}
