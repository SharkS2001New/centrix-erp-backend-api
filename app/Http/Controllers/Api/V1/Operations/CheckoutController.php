<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesCartAccess;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesCartPayments;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesMpesaPayments;
use App\Models\LoyaltyCard;
use App\Models\Voucher;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CheckoutRequest;
use App\Models\CartLine;
use App\Models\Customer;
use App\Services\Sales\SaleRouteResolver;
use App\Models\KraResponse;
use App\Models\PaymentMethod;
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
use App\Jobs\FinalizeSaleAfterCheckoutJob;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Erp\SalePaymentColumnMapper;
use App\Services\Kra\KraDeviceFailure;
use App\Services\Kra\KraDeviceService;
use App\Services\Kra\KraFiscalPolicy;
use App\Services\Sales\DiscountApprovalService;
use App\Services\Sales\CentrixSalesScope;
use App\Services\Sales\MobileCheckoutLocationService;
use App\Services\Sales\MobileCheckoutSettings;
use App\Services\Sales\MobileRouteMarkupCheckoutService;
use App\Services\Sales\PosCashRounding;
use App\Services\Sales\PosCashRoundingSettings;
use App\Services\Sales\OrderNumberAllocator;
use App\Services\Sales\PosDailyOrderNumberAllocator;
use App\Services\Sales\PosOfflineCheckoutIdempotency;
use App\Support\CustomerCreditLimit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CheckoutController extends Controller
{
    use HandlesCartAccess;
    use HandlesCartPayments;
    use HandlesMpesaPayments;
    use HandlesInventory;

    public function __construct(protected ErpContext $erp) {}

    public function fromCart(CheckoutRequest $request, int|string $cartId)
    {
        $cart = $this->findOwnedCart($cartId, $request->user());
        $gate = $this->erp->gateForUser($request->user());
        $channel = (string) $cart->channel;
        try {
            $result = $this->checkoutFromCart($cart, $request->user(), $gate, $request->validated());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'checkout' => $e->getMessage(),
            ]);
        }

        $sale = $result['sale'];
        $deductStock = (bool) ($result['deduct_stock'] ?? false);
        $runSideEffects = (bool) ($result['run_side_effects'] ?? false);

        // Stock ledger, journals, SMS/email, trip assignment, and cache invalidation
        // run after the HTTP response so POS can print immediately.
        if ($deductStock || $runSideEffects) {
            FinalizeSaleAfterCheckoutJob::dispatch(
                (int) $sale->id,
                (int) $request->user()->id,
                $deductStock,
                $runSideEffects,
            )->afterResponse();
        }

        $labels = config('erp.order_status_labels', []);
        $statusName = $labels[$sale->status]
            ?? ucfirst(str_replace('_', ' ', (string) $sale->status));

        // Mobile only needs confirmation fields; skip full toArray of items/payments.
        if ($channel === 'mobile') {
            return response()->json([
                'id' => (int) $sale->id,
                'order_num' => (int) $sale->order_num,
                'order_total' => round((float) $sale->order_total, 2),
                'status' => $sale->status,
                'status_name' => $statusName,
                'payment_status' => $sale->payment_status,
                'amount_paid' => round((float) $sale->amount_paid, 2),
            ], 201);
        }

        // Preserve fiscal payload for immediate POS/thermal print — cashiers often lack
        // admin.kra_responses.view, so print must not rely on a separate lookup.
        $sale = $sale->fresh([
            'items.product.unit',
            'payments.paymentMethod',
            'kraResponse',
            'cashier:id,username,full_name',
        ]);

        return response()->json(array_merge($sale->toArray(), [
            'status_name' => $statusName,
        ]), 201);
    }

    public function quoteFromCart(\Illuminate\Http\Request $request, int|string $cartId)
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

    /**
     * Persist the sale (and optional KRA) synchronously; stock ledger / journals /
     * notifications are finalized after the HTTP response.
     *
     * @return array{sale: Sale, deduct_stock: bool, run_side_effects: bool}
     */
    protected function checkoutFromCart(TemporaryCart $cart, User $user, CapabilityGate $gate, array $input): array
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

        $salesSettings = $gate->moduleSettings('sales');

        return DB::transaction(function () use ($cart, $user, $gate, $input, $lines, $salesSettings) {
            $idempotency = app(PosOfflineCheckoutIdempotency::class);
            $existing = $idempotency->findExisting($user, $input);
            if ($existing) {
                $existing->loadMissing([
                    'items.product.unit',
                    'payments.paymentMethod',
                    'kraResponse',
                    'cashier:id,username,full_name',
                ]);

                return [
                    'sale' => $existing,
                    'deduct_stock' => false,
                    'run_side_effects' => false,
                ];
            }

            $customerNum = $input['customer_num'] ?? null;
            $loyaltyCardIdEarly = $cart->loyalty_card_id ? (int) $cart->loyalty_card_id : null;
            if (! $customerNum && $loyaltyCardIdEarly) {
                $customerNum = LoyaltyCard::find($loyaltyCardIdEarly)?->customer_num;
            }
            $orderNum = $this->resolveCheckoutOrderNum($cart, $user, $input);
            $posOrderFields = $this->resolvePosDailyOrderFields($cart, $user, $input);

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
            $scaled = CentrixSalesScope::scaleVatForOrderDiscount($lineNet, $vat, $orderDiscount);
            $orderDiscount = $scaled['order_discount'];
            $total = $scaled['order_total'];
            $vat = $scaled['total_vat'];

            $customSales = is_array($gate->organization()?->module_settings['sales'] ?? null)
                ? $gate->organization()->module_settings['sales']
                : [];
            if (
                in_array((string) $cart->channel, ['pos', 'backend'], true)
                && PosCashRoundingSettings::enabled($salesSettings, $customSales)
            ) {
                $roundedNet = 0.0;
                foreach ($lines as $line) {
                    $roundedNet += PosCashRounding::roundLightStoresAmount((float) $line->amount);
                }
                $total = PosCashRounding::roundLightStoresAmount(max(0, $roundedNet - $orderDiscount));
            }

            $mpesaOnCart = max(0, (float) ($cart->mpesa_payment_amount ?? 0));

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

            $cashDue = max(0, $total - $voucherPayment - $pointsPayment - $mpesaOnCart);
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
            $amountPaid = $payNow + $voucherPayment + $pointsPayment + $mpesaOnCart;
            if (! $customerNum && $loyaltyCardId) {
                $customerNum = LoyaltyCard::find($loyaltyCardId)?->customer_num;
            }

            $workflow = OrderWorkflowService::forGate($gate);
            $channelWorkflow = $workflow->forChannel($cart->channel);
            $allowPartialPayment = false;
            $paymentMethodCode = (string) ($input['payment_method_code'] ?? 'CASH');

            $isSaveOnly = $payNow <= 0 && $amountPaid <= 0.01 && ! $isCredit && ! empty($input['save_only']);
            if ($isSaveOnly) {
                $requested = isset($input['status']) && is_string($input['status']) ? $input['status'] : null;
                if ($requested === 'held') {
                    $orderStatus = 'held';
                } else {
                    $orderStatus = $workflow->resolveSaveStatus($cart->channel);
                }
            } elseif ($amountPaid > 0.01 || $payNow > 0 || $isCredit) {
                $orderStatus = $workflow->resolveCheckoutStatus(
                    $cart->channel,
                    $isCredit,
                    $amountPaid,
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
                (int) $user->organization_id,
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

            $customerNameOverride = $customer
                ? trim((string) ($customer->customer_name ?? ''))
                : trim((string) ($input['customer_name_override'] ?? ''));

            // Previous-order edit / offline sync: TemporaryCart has no customer columns, so
            // restore the buyer from the sale being superseded when the request omitted them.
            if (
                ($customerNum === null || $customerNameOverride === '')
                && $cart->superseded_sale_id
            ) {
                $priorSale = Sale::query()->find((int) $cart->superseded_sale_id);
                if ($priorSale) {
                    if ($customerNum === null && $priorSale->customer_num) {
                        $customerNum = (int) $priorSale->customer_num;
                        $customer = app(UserMobileOrderScopeService::class)->findCheckoutCustomer(
                            $user,
                            (int) $customerNum,
                            (string) $cart->channel,
                        );
                        if ($customer) {
                            $customerNameOverride = trim((string) ($customer->customer_name ?? ''));
                        }
                    }
                    if ($customerNameOverride === '') {
                        $fromPrior = trim((string) ($priorSale->customer_name_override ?? ''));
                        if ($fromPrior === '') {
                            $priorSale->loadMissing('customer:customer_num,customer_name,organization_id');
                            $fromPrior = trim((string) ($priorSale->customer?->customer_name ?? ''));
                        }
                        if ($fromPrior !== '') {
                            $customerNameOverride = $fromPrior;
                        }
                    }
                }
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
            $fulfillmentMeta = app(PosOfflineCheckoutIdempotency::class)->stampFulfillmentMeta(
                $fulfillmentMeta,
                $input,
            );

            $sale = $this->createSaleWithOrderNum($orderNum, [
                'order_num' => $orderNum,
                'pos_order_num' => $posOrderFields['pos_order_num'] ?? null,
                'pos_order_date' => $posOrderFields['pos_order_date'] ?? null,
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
            ], (int) $user->organization_id);

            if ($workflow->isTerminalStatus($orderStatus, (string) $cart->channel)) {
                $sale->update(['completed_at' => now()]);
            }

            $deductStockRequested = (bool) ($input['deduct_stock'] ?? true);
            $shouldDeductNow = $deductStockRequested
                && $gate->shouldDeductStockAtCheckout($workflow, $orderStatus, (string) $cart->channel);
            // Ledger posting is deferred after the HTTP response. Keep soft holds so
            // available stock stays blocked (same as cart reservations) until then.
            $pendingStockDeduct = $shouldDeductNow;

            foreach ($lines->values() as $i => $line) {
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_code' => $line->product_code,
                    // Always renumber — cart line_no can collide under concurrent POS adds.
                    'line_no' => $i + 1,
                    'item_code' => (string) ($i + 1),
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
            }

            if ($pendingStockDeduct || $gate->shouldHoldStockOnCheckout($workflow, $orderStatus, (string) $cart->channel)) {
                $transferred = StockReservation::query()
                    ->where('cart_id', $cart->id)
                    ->whereNull('released_at')
                    ->exists();
                if ($transferred) {
                    $this->transferCartReservationsToSale((int) $cart->id, (int) $sale->id);
                } else {
                    $this->reserveSaleStockIfNeeded($sale->fresh(['items']), $user, $gate);
                }
                if ($pendingStockDeduct) {
                    $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
                    $meta['pending_stock_deduct'] = true;
                    $sale->update(['fulfillment_meta' => $meta]);
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
                $splits = $this->normalizeCheckoutPaymentSplits($input['payment_splits'] ?? null);
                $mpesaRecordedInSplits = false;
                if ($splits !== []) {
                    $splitTotal = round(array_sum(array_column($splits, 'amount')), 2);
                    $expectedSplitTotal = round($payNow + $mpesaOnCart, 2);
                    if (
                        abs($splitTotal - $expectedSplitTotal) > 0.02
                        && abs($splitTotal - $payNow) > 0.02
                    ) {
                        throw new InvalidArgumentException('Payment splits must add up to the amount paid now.');
                    }
                    foreach ($splits as $split) {
                        $methodCode = (string) $split['method_code'];
                        if (strtoupper($methodCode) === 'MPESA') {
                            $mpesaRecordedInSplits = true;
                        }
                        $method = $this->resolveCheckoutPaymentMethod(
                            (int) $sale->organization_id,
                            $methodCode,
                        );
                        if (! $method) {
                            throw new InvalidArgumentException("Payment method {$methodCode} is not configured.");
                        }
                        SalePayment::create([
                            'sale_id' => $sale->id,
                            'float_session_id' => $floatSessionId,
                            'payment_method_id' => $method->id,
                            'amount' => $split['amount'],
                            'reference_number' => $split['reference_number'] ?? null,
                            'paid_at' => $input['payment_date'] ?? now(),
                        ]);
                        SalePaymentColumnMapper::applyToSale($sale->fresh(), $methodCode, (float) $split['amount']);
                    }
                } else {
                    $method = $this->resolveCheckoutPaymentMethod(
                        (int) $sale->organization_id,
                        (string) $sale->payment_method_code,
                    );
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
                    $mpesaRecordedInSplits = strtoupper((string) $paymentMethodCode) === 'MPESA';
                }

                // Cart-applied M-Pesa is included in amount_paid; ensure a payment row exists
                // when splits only covered the remaining cash due.
                if ($mpesaOnCart > 0 && ! $mpesaRecordedInSplits) {
                    $mpesaMethod = $this->resolveCheckoutPaymentMethod(
                        (int) $sale->organization_id,
                        'MPESA',
                    );
                    if ($mpesaMethod) {
                        SalePayment::create([
                            'sale_id' => $sale->id,
                            'float_session_id' => $floatSessionId,
                            'payment_method_id' => $mpesaMethod->id,
                            'amount' => $mpesaOnCart,
                            'reference_number' => $cart->mpesa_transaction_code
                                ?? ($input['payment_reference'] ?? null),
                            'paid_at' => $input['payment_date'] ?? now(),
                        ]);
                        SalePaymentColumnMapper::applyToSale($sale->fresh(), 'MPESA', $mpesaOnCart);
                    }
                }
            } elseif ($mpesaOnCart > 0) {
                // Fully paid via cart-applied M-Pesa (STK) — pay_now is capped to cash due (0).
                $mpesaMethod = $this->resolveCheckoutPaymentMethod(
                    (int) $sale->organization_id,
                    'MPESA',
                );
                if ($mpesaMethod) {
                    SalePayment::create([
                        'sale_id' => $sale->id,
                        'float_session_id' => $floatSessionId,
                        'payment_method_id' => $mpesaMethod->id,
                        'amount' => $mpesaOnCart,
                        'reference_number' => $cart->mpesa_transaction_code
                            ?? ($input['payment_reference'] ?? null),
                        'paid_at' => $input['payment_date'] ?? now(),
                    ]);
                    SalePaymentColumnMapper::applyToSale($sale->fresh(), 'MPESA', $mpesaOnCart);
                }
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

            $invoice = null;
            if ($orderStatus === 'pending_approval') {
                $discountApproval->attachCheckoutToSale(
                    $sale,
                    $cart,
                    $user,
                    isset($input['discount_approval_reason']) ? (string) $input['discount_approval_reason'] : null,
                );
            } elseif (! in_array($orderStatus, ['held', 'draft'], true)) {
                $invoice = app(CustomerInvoiceService::class)->ensureForSale($sale, $user, $total, $amountPaid);
            }

            // Link cart-applied STK/till payments to this sale so reconciliation
            // shows the M-Pesa transaction against the sold order number.
            if ($mpesaOnCart > 0) {
                $this->linkCartMpesaPaymentsToSale(
                    $cart,
                    $sale,
                    $user,
                    $invoice?->id,
                );
            }

            $this->releaseCartReservations($cart->id);
            CartLine::where('cart_id', $cart->id)->delete();
            $cart->delete();

            $sale = $sale->fresh(['items.product.unit', 'payments.paymentMethod']);

            // Held/draft parks are unfinished — do not fiscalize, invoice, journal, or notify.
            $isParkedOrder = in_array($orderStatus, ['held', 'draft'], true);

            $finance = $gate->moduleSettings('finance');
            $explicitSubmit = array_key_exists('submit_kra', $input)
                ? (bool) $input['submit_kra']
                : null;

            $submitKra = ! $isParkedOrder
                && $orderStatus !== 'pending_approval'
                && KraFiscalPolicy::shouldFiscalizeSale(
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

            $runSideEffects = ! $isParkedOrder && $orderStatus !== 'pending_approval';

            return [
                'sale' => $sale,
                'deduct_stock' => $pendingStockDeduct,
                'run_side_effects' => $runSideEffects,
            ];
        });
    }

    /**
     * Daily per-cashier POS ticket # for thermal Cash Sales # (independent of S00xx).
     *
     * @return array{pos_order_num?: int, pos_order_date?: string}
     */
    protected function resolvePosDailyOrderFields(TemporaryCart $cart, User $user, array $input = []): array
    {
        if (! $this->isPosCheckoutChannel($cart)) {
            return [];
        }

        $allocator = app(PosDailyOrderNumberAllocator::class);

        if ($cart->superseded_sale_id) {
            $superseded = Sale::query()->find((int) $cart->superseded_sale_id);
            if ($superseded) {
                $taken = $allocator->takeFromSale($superseded);
                if ($taken !== null) {
                    return $taken;
                }
            }
        }

        $clientPos = isset($input['pos_order_num']) ? (int) $input['pos_order_num'] : 0;
        $clientDateRaw = trim((string) ($input['pos_order_date'] ?? ''));
        $clientDate = $clientDateRaw !== ''
            ? (app(PosDailyOrderNumberAllocator::class)->normalizeBusinessDate($clientDateRaw) ?? '')
            : '';
        if (
            $clientPos > 0
            && $clientDate !== ''
            && filter_var($input['offline_order'] ?? false, FILTER_VALIDATE_BOOLEAN)
        ) {
            $claimed = $allocator->claimReservedForCheckout(
                (int) $user->organization_id,
                (int) $user->id,
                $clientPos,
                $clientDate,
            );
            if ($claimed) {
                return [
                    'pos_order_num' => $clientPos,
                    'pos_order_date' => $clientDate,
                ];
            }
        }

        $allocated = $allocator->allocateForCheckout(
            (int) $user->organization_id,
            (int) $user->id,
        );

        return $allocated ?? [];
    }

    protected function isPosCheckoutChannel(TemporaryCart $cart): bool
    {
        $channel = strtolower(trim((string) ($cart->channel ?? '')));
        $source = strtolower(trim((string) ($cart->order_source ?? '')));

        return $channel === 'pos' || $source === 'pos';
    }

    /**
     * Pick an order number for checkout, freeing stale held/cancelled rows that still
     * occupy a number the cart intends to reuse (POS edit / double-submit races).
     *
     * @param  array<string, mixed>  $input
     */
    protected function resolveCheckoutOrderNum(TemporaryCart $cart, User $user, array $input): int
    {
        $orgId = (int) $user->organization_id;
        $allocator = app(OrderNumberAllocator::class);
        $requested = isset($input['order_num'])
            ? (int) $input['order_num']
            : ($cart->held_order_num ? (int) $cart->held_order_num : null);

        if ($requested === null || $requested <= 0) {
            return $allocator->nextForOrganization($orgId);
        }

        $existing = Sale::query()
            ->where('organization_id', $orgId)
            ->where('order_num', $requested)
            ->first();

        if (! $existing) {
            // Claim the watermark so concurrent allocators skip this number.
            $allocator->reserveSpecificForOrganization($orgId, $requested);

            return $requested;
        }

        $supersededId = $cart->superseded_sale_id ? (int) $cart->superseded_sale_id : null;
        $canFree = (int) $existing->id === $supersededId
            || in_array((string) $existing->status, ['held', 'draft', 'cancelled'], true);

        if ($canFree) {
            $existing->update([
                'order_num' => $allocator->tombstoneForSupersededSale((int) $existing->id),
                'status' => in_array((string) $existing->status, ['held', 'draft'], true)
                    ? 'cancelled'
                    : $existing->status,
                'cancelled_at' => $existing->cancelled_at ?? now(),
                'cancelled_by' => $existing->cancelled_by ?? $user->id,
                'archived' => 1,
            ]);
            $allocator->reserveSpecificForOrganization($orgId, $requested);

            return $requested;
        }

        // Live sale already owns this number — allocate a fresh one instead of 500ing.
        return $allocator->nextForOrganization($orgId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createSaleWithOrderNum(int $orderNum, array $attributes, int $organizationId): Sale
    {
        $allocator = app(OrderNumberAllocator::class);
        $attributes['order_num'] = $orderNum;
        $lastError = null;

        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                return Sale::create($attributes);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $lastError = $e;
                $message = $e->getMessage();
                if (str_contains($message, 'uq_org_order_num')) {
                    $attributes['order_num'] = $allocator->nextForOrganization($organizationId);
                    continue;
                }
                if (
                    str_contains($message, 'uq_pos_daily_order_num')
                    && ! empty($attributes['cashier_id'])
                    && ! empty($attributes['organization_id'])
                ) {
                    $pos = app(PosDailyOrderNumberAllocator::class)->allocateForCheckout(
                        (int) $attributes['organization_id'],
                        (int) $attributes['cashier_id'],
                        isset($attributes['pos_order_date']) ? (string) $attributes['pos_order_date'] : null,
                    );
                    if ($pos === null) {
                        throw $e;
                    }
                    $attributes['pos_order_num'] = $pos['pos_order_num'];
                    $attributes['pos_order_date'] = $pos['pos_order_date'];
                    continue;
                }
                throw $e;
            }
        }

        throw $lastError ?? new InvalidArgumentException('Could not allocate a unique order number.');
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

    /** @return list<array{method_code: string, amount: float, reference_number: ?string}> */
    protected function normalizeCheckoutPaymentSplits(mixed $splits): array
    {
        if (! is_array($splits)) {
            return [];
        }

        $normalized = [];
        foreach ($splits as $split) {
            if (! is_array($split)) {
                continue;
            }
            $amount = round((float) ($split['amount'] ?? 0), 2);
            $methodCode = strtoupper(trim((string) ($split['method_code'] ?? '')));
            if ($methodCode === '' || $amount <= 0) {
                continue;
            }
            $reference = trim((string) ($split['reference_number'] ?? ''));
            $normalized[] = [
                'method_code' => $methodCode,
                'amount' => $amount,
                'reference_number' => $reference !== '' ? $reference : null,
            ];
        }

        return $normalized;
    }

    protected function resolveCheckoutPaymentMethod(int $organizationId, string $methodCode): ?PaymentMethod
    {
        $method = PaymentMethod::query()
            ->where('organization_id', $organizationId)
            ->where('method_code', $methodCode)
            ->first();
        if ($method) {
            return $method;
        }

        $aliases = match (strtoupper($methodCode)) {
            'EQUITY', 'KCB', 'OTHER' => ['BANK', 'BANK_TRANSFER'],
            'M-PESA', 'M_PESA' => ['MPESA'],
            default => [],
        };
        if ($aliases === []) {
            return null;
        }

        return PaymentMethod::query()
            ->where('organization_id', $organizationId)
            ->whereIn('method_code', $aliases)
            ->first();
    }
}
