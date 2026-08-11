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
use App\Services\Sales\SalePaymentAdjustmentService;
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
use App\Services\Sales\SaleInventoryRestorer;
use App\Services\Sales\PosOrderEditService;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\ReferenceJournalReversalService;
use App\Services\Erp\SalePaymentColumnMapper;
use App\Services\Kra\KraFiscalPolicy;
use App\Services\Sales\CheckoutKraSubmissionService;
use App\Services\Notifications\ActionRequestService;
use App\Services\Catalog\ProductCatalogScopeService;
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
use App\Services\Sales\SameDayCustomerOrderService;
use App\Services\Sales\SaleRouteResolver;
use App\Support\CustomerCreditLimit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'checkout' => 'Could not save this sale because an order number collided. Please try sync again.',
            ]);
        }

        $sale = $result['sale'];
        $deductStock = (bool) ($result['deduct_stock'] ?? false);
        $runSideEffects = (bool) ($result['run_side_effects'] ?? false);
        $pendingKra = (bool) ($result['pending_kra'] ?? false);
        $buyerPin = isset($result['buyer_pin']) ? (string) $result['buyer_pin'] : null;
        $buyerPin = $buyerPin !== '' ? $buyerPin : null;

        // Previous-order edit: finish deferred restore side-effects before re-fiscalizing
        // or deducting stock for the revision (background job may still be in flight).
        $priorSaleId = (int) (($sale->fulfillment_meta['supersedes_sale_id'] ?? 0));
        if ($priorSaleId > 0) {
            $priorSale = Sale::query()->find($priorSaleId);
            if ($priorSale) {
                $priorMeta = is_array($priorSale->fulfillment_meta) ? $priorSale->fulfillment_meta : [];
                if ($priorSale->stock_balanced || ! empty($priorMeta['stock_reverse_pending'])) {
                    try {
                        app(SaleInventoryRestorer::class)->reverseForPosEdit($priorSale, $request->user());
                        unset($priorMeta['stock_reverse_pending']);
                        $priorSale->update([
                            'stock_balanced' => 0,
                            'fulfillment_meta' => $priorMeta,
                        ]);
                    } catch (\Throwable $e) {
                        report($e);
                        throw new \InvalidArgumentException(
                            'Could not reverse stock for the previous order before applying this edit. Retry once the prior sale stock is restored.',
                            0,
                            $e,
                        );
                    }
                }
                if ($pendingKra) {
                    app(PosOrderEditService::class)->fiscalVoidBeforeEdit($priorSale->fresh() ?? $priorSale, $request->user(), $gate);
                }
            }
        }

        // Fiscalize after the sale commits (not inside the DB transaction) but still
        // before the HTTP response so the first thermal receipt includes the eTIMS QR.
        if ($pendingKra) {
            $kraResponse = app(CheckoutKraSubmissionService::class)
                ->submitForSale($sale, $gate, $buyerPin);
            if ($kraResponse) {
                $sale->setRelation('kraResponse', $kraResponse);
            }
        }

        // Stock ledger, journals, SMS/email, trip assignment, and cache invalidation
        // run after the HTTP response so POS can print immediately once KRA returns.
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

        // Soft-fail / amount-bypass markers for every channel (POS, backoffice, mobile, WhatsApp).
        $kraSkipMeta = $this->kraSkipMetaFromSale($sale);

        // Mobile only needs confirmation fields; skip full toArray of items/payments.
        if ($channel === 'mobile') {
            $fulfillmentMeta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];

            return response()->json(array_merge([
                'id' => (int) $sale->id,
                'order_num' => (int) $sale->order_num,
                'order_total' => round((float) $sale->order_total, 2),
                'status' => $sale->status,
                'status_name' => $statusName,
                'payment_status' => $sale->payment_status,
                'amount_paid' => round((float) $sale->amount_paid, 2),
                'same_day_customer_append' => ! empty($fulfillmentMeta['same_day_customer_append']),
            ], $kraSkipMeta), 201);
        }

        // Preserve fiscal payload for immediate POS/thermal print — cashiers often lack
        // admin.kra_responses.view, so print must not rely on a separate lookup.
        $sale = $sale->fresh([
            'items.product.unit',
            'payments.paymentMethod',
            'kraResponse',
            'cashier:id,username,full_name',
        ]);

        $payload = array_merge($sale->toArray(), [
            'status_name' => $statusName,
        ], $this->kraSkipMetaFromSale($sale));

        return response()->json($payload, 201);
    }

    /**
     * @return array{kra_skipped?: bool, kra_warning?: string, kra_error_detail?: string}
     */
    protected function kraSkipMetaFromSale(Sale $sale): array
    {
        $kra = $sale->relationLoaded('kraResponse')
            ? $sale->kraResponse
            : $sale->kraResponse()->latest('id')->first();

        if (! $kra) {
            return [];
        }

        $status = (string) $kra->status;
        if (! in_array($status, ['failed', 'skipped'], true)) {
            return [];
        }

        $detail = trim((string) ($kra->error_message ?? ''));
        if ($status === 'skipped') {
            $warning = $detail !== ''
                ? $detail
                : 'Sale created without KRA (skipped).';
        } else {
            $warning = 'Sale created without KRA due to an error with KRA device.';
            if ($detail === '') {
                $detail = 'KRA device submission failed.';
            }
        }

        return [
            'kra_skipped' => true,
            'kra_warning' => $warning,
            'kra_error_detail' => $detail !== '' ? $detail : $warning,
        ];
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
            // Empty previous-order edit → cancel the live sale and record the full return.
            if ((int) ($cart->superseded_sale_id ?? 0) > 0) {
                return $this->checkoutEmptyPreviousOrderEditCancel($cart, $user, $gate, $input);
            }
            throw new InvalidArgumentException('Cart is empty.');
        }

        app(DiscountApprovalService::class)->assertCheckoutAllowed(
            $cart,
            $user,
            $gate,
            isset($input['discount_approval_reason']) ? (string) $input['discount_approval_reason'] : null,
        );

        $salesSettings = $gate->moduleSettings('sales');

        // Reject carts whose lines no longer resolve to sellable catalogue products
        // (soft-deleted, wrong branch, or never existed). Prevents orphan sale_items.
        $productsByCode = $this->assertCheckoutLinesAccessible($cart, $lines, $user);

        return DB::transaction(function () use ($cart, $user, $gate, $input, $lines, $salesSettings, $productsByCode) {
            // Hold the cart row for the whole checkout so concurrent DELETE /lines
            // waits instead of deadlocking on cart_lines / stock_reservations.
            $lockedCart = TemporaryCart::query()->whereKey($cart->id)->lockForUpdate()->first();
            if (! $lockedCart) {
                throw new InvalidArgumentException(
                    'Cart not found. It may have already been checked out — reopen the order to continue editing.',
                );
            }
            $cart = $lockedCart;

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

            $sameDayAppend = app(SameDayCustomerOrderService::class);
            $appendPriorSale = $sameDayAppend->resolveAppendTarget(
                $gate,
                $user,
                $salesSettings,
                $customerNum ? (int) $customerNum : null,
                $cart->branch_id ? (int) $cart->branch_id : ($user->branch_id ? (int) $user->branch_id : null),
                (string) $cart->channel,
                $cart->superseded_sale_id ? (int) $cart->superseded_sale_id : null,
                $cart->held_order_num ? (int) $cart->held_order_num : null,
            );
            $appendPriorPaid = 0.0;
            if ($appendPriorSale) {
                app(PosOrderEditService::class)->fiscalVoidBeforeEdit($appendPriorSale, $user, $gate);
                if ($appendPriorSale->stock_balanced) {
                    $this->reverseSaleStockDeductions($appendPriorSale, $user);
                } else {
                    $this->releaseSaleReservations((int) $appendPriorSale->id);
                }
                $cart->superseded_sale_id = (int) $appendPriorSale->id;
                $cart->held_order_num = (int) $appendPriorSale->order_num;
                $input['order_num'] = (int) $appendPriorSale->order_num;
                $appendPriorPaid = max(0, (float) ($appendPriorSale->amount_paid ?? 0));
            }

            $orderNum = $this->resolveCheckoutOrderNum($cart, $user, $input);
            if ($appendPriorSale) {
                $appendPriorSale->refresh();
                if ((string) $appendPriorSale->status !== 'cancelled') {
                    $appendPriorSale->update([
                        'status' => 'cancelled',
                        'cancelled_at' => $appendPriorSale->cancelled_at ?? now(),
                        'cancelled_by' => $appendPriorSale->cancelled_by ?? $user->id,
                        'archived' => 1,
                        'stock_balanced' => 0,
                        'pos_order_num' => null,
                        'pos_order_date' => null,
                    ]);
                }
                app(CustomerInvoiceService::class)->voidForCancelledSale($appendPriorSale->fresh(), $user);
            }
            $routeId = $this->resolveCheckoutRouteId($cart, $customerNum ? (int) $customerNum : null, $gate);
            app(UserMobileOrderScopeService::class)->assertCheckoutRoute($user, (string) $cart->channel, $routeId);

            $prepared = app(MobileRouteMarkupCheckoutService::class)->prepareCheckoutLines(
                $cart,
                $lines,
                $routeId,
                $gate,
            );
            $lines = $prepared['lines'];
            if ($appendPriorSale) {
                $lines = $sameDayAppend->priorItemsAsCheckoutLines($appendPriorSale)
                    ->concat($lines)
                    ->values();
            }

            $customSales = is_array($gate->organization()?->module_settings['sales'] ?? null)
                ? $gate->organization()->module_settings['sales']
                : [];
            $cashRound = in_array((string) $cart->channel, ['pos', 'backend', 'mobile'], true)
                && PosCashRoundingSettings::enabled($salesSettings, $customSales);
            if ($cashRound) {
                $lines = $lines->map(function ($line) {
                    $line->amount = PosCashRounding::roundLightStoresAmount((float) $line->amount);

                    return $line;
                });
            }

            if ($appendPriorSale) {
                $lineNet = round((float) $lines->sum('amount'), 2);
                $vat = round((float) $lines->sum('product_vat'), 2);
            } else {
                $lineNet = round((float) $lines->sum('amount'), 2);
                $vat = $cashRound
                    ? round((float) $lines->sum('product_vat'), 2)
                    : (float) ($input['total_vat'] ?? $prepared['total_vat']);
            }
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

            if ($cashRound) {
                $roundedNet = 0.0;
                foreach ($lines as $line) {
                    $roundedNet += (float) $line->amount;
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

            $cashDue = max(0, $total - $appendPriorPaid - $voucherPayment - $pointsPayment - $mpesaOnCart);
            $isMobileChannel = (string) $cart->channel === 'mobile';
            $mobileCheckout = app(MobileCheckoutSettings::class);
            $mobileCheckout->applyCheckoutPolicy($salesSettings, $input, (string) $cart->channel);
            $offlineOrder = filter_var($input['offline_order'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $adjustmentRows = $this->normalizeCheckoutPaymentAdjustments($input['payment_adjustments'] ?? null);
            // Previous-order edit (not same-day append): rebuild net tenders from the
            // superseded sale. Do not post pay_now as a fresh CASH/Equity payment — that
            // zeros or doubles method totals on X/Z/EOD after offline sync.
            $isPreviousOrderEditSettlement = (int) ($cart->superseded_sale_id ?? 0) > 0
                && $appendPriorSale === null;

            // Till cashiers may open Invoice (I) then change mind and pay Cash/M-Pesa/bank
            // in full. Client pay_now covering the bill means this is not a credit sale —
            // never book A/R from a stale is_credit_sale flag.
            if (
                $isCredit
                && empty($input['save_only'])
                && in_array((string) $cart->channel, ['pos', 'backend'], true)
                && ! $isPreviousOrderEditSettlement
            ) {
                $clientTenderTowardBill = round(
                    $appendPriorPaid
                        + max(0, (float) ($input['pay_now'] ?? 0))
                        + $voucherPayment
                        + $pointsPayment
                        + $mpesaOnCart,
                    2,
                );
                if ($total > 0.01 && $clientTenderTowardBill + 0.01 >= $total) {
                    $isCredit = false;
                }
            }

            if (! $isCredit && $payNow <= 0 && $cashDue > 0 && empty($input['save_only']) && ! $isPreviousOrderEditSettlement) {
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
            if ($isPreviousOrderEditSettlement) {
                $payNow = 0;
                // Preserve prior payment — do not invent a full settlement for unpaid/partial
                // mobile (or WhatsApp) restores. Fully-paid POS re-edits still settle at the
                // revised total so tenders/X-Z stay correct. Top-up / return adjustments from
                // the edit are included so collecting the balance does not stay "partial".
                $priorForSettlement = Sale::query()->find((int) $cart->superseded_sale_id);
                $priorPaid = $priorForSettlement
                    ? round((float) ($priorForSettlement->amount_paid ?? 0), 2)
                    : 0.0;
                $priorTotal = $priorForSettlement
                    ? round((float) ($priorForSettlement->order_total ?? 0), 2)
                    : 0.0;
                $priorFullyPaid = $priorPaid > 0.01 && $priorPaid + 0.01 >= $priorTotal;
                // Top-up / return must equal |revised total − prior total| (value of items
                // added or returned). Never persist a full new bill as a "top-up".
                $adjustmentRows = $this->reconcilePreviousOrderEditAdjustments(
                    $adjustmentRows,
                    $priorTotal,
                    $total,
                    (string) ($input['payment_method_code'] ?? $priorForSettlement?->payment_method_code ?? 'CASH'),
                );
                $adjustmentNet = 0.0;
                foreach ($adjustmentRows as $row) {
                    $amt = round((float) ($row['amount'] ?? 0), 2);
                    if ($amt <= 0) {
                        continue;
                    }
                    if (($row['adjustment_type'] ?? '') === 'return') {
                        $adjustmentNet -= $amt;
                    } else {
                        $adjustmentNet += $amt;
                    }
                }
                if ($priorFullyPaid) {
                    $amountPaid = $total;
                } else {
                    $amountPaid = round(min(max(0, $priorPaid + $adjustmentNet), $total), 2);
                }
                // External POS / backoffice till: non-credit edits must always be fully paid.
                // Only an explicit credit sale may remain unpaid or partially paid after revise.
                if (
                    ! $isCredit
                    && in_array((string) $cart->channel, ['pos', 'backend'], true)
                ) {
                    $amountPaid = $total;
                }
            } else            if (
                ! $isCredit
                && empty($input['save_only'])
                && in_array((string) $cart->channel, ['pos', 'backend'], true)
            ) {
                // Cash / M-Pesa / bank / cheque (anything except credit input): must cover
                // the bill. Reject intentional underpay — do not invent a "paid" sale.
                // Only settle-up tiny rounding / reprice gaps after that check.
                $clientPaid = round(
                    $appendPriorPaid + min($payNow, $cashDue) + $voucherPayment + $pointsPayment + $mpesaOnCart,
                    2,
                );
                if ($total > 0.01 && $clientPaid + 0.01 < $total) {
                    // Offline / local-first uploads already printed a paid till receipt.
                    // Cart reprice or a pending-sync edit can leave pay_now below the new
                    // total — settle in full instead of rejecting a sale the cashier already took.
                    if ($offlineOrder) {
                        $payNow = $cashDue;
                        unset($input['payment_splits']);
                    } else {
                        throw new InvalidArgumentException(
                            'Full payment required for Cash, M-Pesa, bank, and cheque sales. Select a credit customer to leave a balance unpaid or partially paid.',
                        );
                    }
                }
                $payNow = $cashDue;
                $amountPaid = $appendPriorPaid + $payNow + $voucherPayment + $pointsPayment + $mpesaOnCart;
            } else {
                $payNow = min($payNow, $cashDue);
                $amountPaid = $appendPriorPaid + $payNow + $voucherPayment + $pointsPayment + $mpesaOnCart;
            }
            if (! $customerNum && $loyaltyCardId) {
                $customerNum = LoyaltyCard::find($loyaltyCardId)?->customer_num;
            }

            // Final till guard: any fully settled POS/backend sale is never credit A/R.
            // Covers I→C/M/E/K where the client still sent is_credit_sale=true.
            if (
                $isCredit
                && empty($input['save_only'])
                && in_array((string) $cart->channel, ['pos', 'backend'], true)
                && ! $isPreviousOrderEditSettlement
                && $total > 0.01
                && $amountPaid + 0.01 >= $total
            ) {
                $isCredit = false;
            }

            $workflow = OrderWorkflowService::forGate($gate);
            $channelWorkflow = $workflow->forChannel($cart->channel);
            $allowPartialPayment = false;
            $paymentMethodCode = (string) ($input['payment_method_code'] ?? 'CASH');
            if (
                ! $isCredit
                && strtoupper($paymentMethodCode) === 'CREDIT'
                && empty($input['save_only'])
            ) {
                // Fully paid till sale with a stale CREDIT method (I then C/M/E/K) —
                // settle as cash, do not reject the checkout.
                if (
                    in_array((string) $cart->channel, ['pos', 'backend'], true)
                    && $amountPaid + 0.01 >= $total
                    && $total > 0.01
                ) {
                    $paymentMethodCode = 'CASH';
                } else {
                    throw new InvalidArgumentException(
                        'Credit payment method requires a credit customer sale.',
                    );
                }
            }

            // Non-credit POS sales are always fully paid (never partial / unpaid).
            if (
                ! $isCredit
                && empty($input['save_only'])
                && in_array((string) $cart->channel, ['pos', 'backend'], true)
                && ! $isPreviousOrderEditSettlement
            ) {
                $amountPaid = $total;
            }

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

            // Held/draft parks use their own hold sequence — never consume Cash Sales #.
            // Resolve float first so offline sync (closed→open remapping) scopes Cash Sales #
            // to the session that will actually be stamped on the sale.
            $floatSessionId = FloatSessionValidator::forUser($user)->resolveForCheckout($cart, $user, $input);
            if ($floatSessionId) {
                $input['float_session_id'] = $floatSessionId;
                if (
                    \App\Models\TemporaryCart::temporaryCartsHaveFloatSessionColumn()
                    && (int) ($cart->float_session_id ?? 0) !== (int) $floatSessionId
                ) {
                    $cart->float_session_id = $floatSessionId;
                    $cart->save();
                }
            }

            $posOrderFields = in_array($orderStatus, ['held', 'draft'], true)
                ? []
                : $this->resolvePosDailyOrderFields($cart, $user, $input);

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
            if ($appendPriorSale) {
                $fulfillmentMeta['same_day_customer_append'] = true;
                $fulfillmentMeta['appended_to_sale_id'] = (int) $appendPriorSale->id;
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
                'payment_method_code' => $isSaveOnly
                    ? null
                    : ($input['payment_method_code'] ?? 'CASH'),
                'is_credit_sale' => $isCredit ? 1 : 0,
                // Non-credit External POS / backoffice till sales are always fully paid.
                'payment_status' => (
                    ! $isCredit
                    && empty($input['save_only'])
                    && in_array((string) $cart->channel, ['pos', 'backend'], true)
                )
                    ? 'paid'
                    : $this->derivePaymentStatus($total, $amountPaid),
                'amount_paid' => $amountPaid,
                'completed_at' => null,
                'fulfillment_meta' => $fulfillmentMeta !== [] ? $fulfillmentMeta : null,
                '__lock_pos_ticket' => ! empty($posOrderFields['__lock_pos_ticket']),
            ], (int) $user->organization_id);

            if ($workflow->isTerminalStatus($orderStatus, (string) $cart->channel)) {
                $clientCompleted = $this->resolveOfflineClientCompletedAt($input);
                if ($clientCompleted !== null) {
                    $sale->forceFill([
                        'created_at' => $clientCompleted,
                        'completed_at' => $clientCompleted,
                    ])->save();
                } else {
                    $sale->update(['completed_at' => now()]);
                }
            }

            $deductStockRequested = (bool) ($input['deduct_stock'] ?? true);
            $shouldDeductNow = $deductStockRequested
                && $gate->shouldDeductStockAtCheckout($workflow, $orderStatus, (string) $cart->channel);
            // Ledger posting is deferred after the HTTP response. Keep soft holds so
            // available stock stays blocked (same as cart reservations) until then.
            $pendingStockDeduct = $shouldDeductNow;

            foreach ($lines->values() as $i => $line) {
                $code = trim((string) $line->product_code);
                $product = $productsByCode->get(strtolower($code));
                $snapshottedName = trim((string) ($line->product_name ?? ''));
                if ($snapshottedName === '') {
                    $snapshottedName = trim((string) ($product?->product_name ?? ''));
                }
                if ($snapshottedName === '') {
                    $snapshottedName = $code;
                }

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_code' => $line->product_code,
                    'product_name' => $snapshottedName,
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

            if ($isPreviousOrderEditSettlement) {
                $priorSale = Sale::query()->find((int) $cart->superseded_sale_id);
                if ($priorSale) {
                    $forceFullyPaid = ! $isCredit
                        && in_array((string) $cart->channel, ['pos', 'backend'], true);
                    $this->applyPreviousOrderEditTenders(
                        $priorSale,
                        $sale,
                        $adjustmentRows,
                        $floatSessionId,
                        $total,
                        (float) $amountPaid,
                        $input['payment_date'] ?? now(),
                        $forceFullyPaid,
                    );
                }
            } elseif ($appendPriorSale) {
                $this->copyPriorSalePaymentsToSale($appendPriorSale, $sale, $floatSessionId);
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

            if ($adjustmentRows !== []) {
                app(SalePaymentAdjustmentService::class)->recordForSale(
                    $sale,
                    $adjustmentRows,
                    $floatSessionId,
                    $input['payment_date'] ?? now(),
                );
            }

            $this->releaseCartReservations($cart->id);
            CartLine::where('cart_id', $cart->id)->delete();
            $cart->delete();

            $this->syncSaleTenderColumnsFromPayments($sale);

            $orderChange = round((float) ($input['order_change'] ?? 0), 2);
            if ($orderChange > 0) {
                $sale->update(['order_change' => $orderChange]);
            }

            $sale = $sale->fresh(['items.product.unit', 'payments.paymentMethod']);

            // Held/draft parks are unfinished — do not fiscalize, invoice, journal, or notify.
            $isParkedOrder = in_array($orderStatus, ['held', 'draft'], true);

            $finance = $gate->moduleSettings('finance');
            $explicitSubmit = array_key_exists('submit_kra', $input)
                ? (bool) $input['submit_kra']
                : null;

            // Offline / local-first outbox uploads: never fiscalize on sync — receipt
            // already printed without QR. Online KRA-on sales do not use offline_order.
            $offlineOrderUpload = filter_var($input['offline_order'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $eligibleForKra = ! $isParkedOrder
                && $orderStatus !== 'pending_approval'
                && ! $offlineOrderUpload;
            $submitKra = $eligibleForKra
                && KraFiscalPolicy::shouldFiscalizeSale(
                    $finance,
                    (float) $sale->order_total,
                    $explicitSubmit,
                );

            $kraResponse = null;
            $pendingKra = false;
            $buyerPin = $this->resolveCheckoutBuyerPin(
                $input['customer_kra_pin'] ?? null,
                $customer,
                $customerNum,
            );

            if ($submitKra) {
                // Device call runs after this transaction commits (still before HTTP
                // response) so a slow Comstore wait does not hold sale row locks —
                // the first receipt still includes the eTIMS QR.
                $pendingKra = true;
            } elseif (
                $eligibleForKra
                && KraFiscalPolicy::isDeviceConfigured($finance)
                && KraFiscalPolicy::isFiscalizationActive($finance)
                && KraFiscalPolicy::isBypassed($finance, (float) $sale->order_total)
            ) {
                // Intentional policy skip — still visible under Unfiscalized sales with reason.
                $kraResponse = app(CheckoutKraSubmissionService::class)
                    ->recordAmountBypass($sale, $finance);
            }
            if ($kraResponse) {
                $sale->setRelation('kraResponse', $kraResponse);
            }

            $runSideEffects = ! $isParkedOrder && $orderStatus !== 'pending_approval';

            return [
                'sale' => $sale,
                'deduct_stock' => $pendingStockDeduct,
                'run_side_effects' => $runSideEffects,
                'pending_kra' => $pendingKra,
                'buyer_pin' => $buyerPin,
            ];
        }, 5);
    }

    /**
     * Per till-float-session POS ticket # for thermal Cash Sales # (independent of S00xx).
     * Resets to 1 when a new float session opens after Z/close, even on the same day.
     * Without a float session, falls back to per-cashier calendar-day sequencing.
     *
     * @return array{pos_order_num?: int, pos_order_date?: string}
     */
    protected function resolvePosDailyOrderFields(TemporaryCart $cart, User $user, array $input = []): array
    {
        if (! $this->isPosCheckoutChannel($cart)) {
            return [];
        }

        $allocator = app(PosDailyOrderNumberAllocator::class);
        $floatSessionId = isset($input['float_session_id'])
            ? (int) $input['float_session_id']
            : ($cart->float_session_id ? (int) $cart->float_session_id : null);
        if ($floatSessionId !== null && $floatSessionId <= 0) {
            $floatSessionId = null;
        }

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
        $offlineOrder = filter_var($input['offline_order'] ?? false, FILTER_VALIDATE_BOOLEAN);
        // Cash Sales # is sequential within the float session (or cashier/day without float).
        // Offline/local-first: keep the printed ticket when it is still free.
        // Online: only accept a client ticket when it is exactly the next sequential #.
        if ($clientPos > 0 && $clientDate !== '') {
            if ($offlineOrder) {
                $claimed = $allocator->claimPrintedTicketForCheckout(
                    (int) $user->organization_id,
                    (int) $user->id,
                    $clientPos,
                    $clientDate,
                    $floatSessionId,
                );
                if ($claimed) {
                    return [
                        'pos_order_num' => $clientPos,
                        'pos_order_date' => $clientDate,
                        '__lock_pos_ticket' => true,
                    ];
                }
                // Ticket already taken (another till synced first, or counter drifted).
                // Fall through and allocate the next free Cash Sales # so offline sync
                // still uploads — the sale response carries the new ticket for reprint.
            } else {
                $peek = $allocator->peekNextForCashier(
                    (int) $user->organization_id,
                    (int) $user->id,
                    $clientDate,
                    $floatSessionId,
                );
                $expectedNext = (int) ($peek['pos_order_num'] ?? 0);
                if ($clientPos === $expectedNext) {
                    $claimed = $allocator->claimPrintedTicketForCheckout(
                        (int) $user->organization_id,
                        (int) $user->id,
                        $clientPos,
                        $clientDate,
                        $floatSessionId,
                    );
                    if ($claimed) {
                        return [
                            'pos_order_num' => $clientPos,
                            'pos_order_date' => $clientDate,
                            '__lock_pos_ticket' => true,
                        ];
                    }
                }
                // Stale reserved-block tickets (e.g. #27 while next is #7) are ignored —
                // allocate the true next Cash Sales # below.
            }
        }

        $allocated = $allocator->allocateForCheckout(
            (int) $user->organization_id,
            (int) $user->id,
            $clientDate !== '' ? $clientDate : null,
            $floatSessionId,
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
     * Preserve the cashier's local sale timestamp when replaying offline POS checkout.
     */
    protected function resolveOfflineClientCompletedAt(array $input): ?\Carbon\Carbon
    {
        if (! filter_var($input['offline_order'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $raw = trim((string) ($input['client_completed_at'] ?? ''));
        if ($raw === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($raw)->timezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Pick an order number for checkout, freeing only the sale this cart is editing.
     * Create path always gets a fresh number; previous-order edit reuses Cash Sale #
     * without ever leaving two live owners of the same number.
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

        $supersededId = $cart->superseded_sale_id ? (int) $cart->superseded_sale_id : null;

        return $allocator->withOrganizationLock($orgId, function () use (
            $allocator,
            $orgId,
            $requested,
            $supersededId,
            $user,
        ): int {
            $existing = Sale::query()
                ->where('organization_id', $orgId)
                ->where('order_num', $requested)
                ->lockForUpdate()
                ->first();

            if (! $existing) {
                $allocator->reserveSpecificForOrganization($orgId, $requested);

                return $requested;
            }

            // Only free the exact sale this cart is superseding — never steal another
            // cancelled/held row's number (that caused cross-order collisions).
            $canFree = $supersededId !== null && (int) $existing->id === $supersededId;

            // Parked held/draft on the same till may still occupy a reserved offline number.
            if (
                ! $canFree
                && $supersededId === null
                && in_array((string) $existing->status, ['held', 'draft'], true)
                && (int) ($existing->cashier_id ?? 0) === (int) $user->id
            ) {
                $canFree = true;
            }

            if (! $canFree) {
                // Live (or foreign) sale already owns this number — allocate fresh.
                return $allocator->nextForOrganization($orgId);
            }

            $meta = is_array($existing->fulfillment_meta) ? $existing->fulfillment_meta : [];
            unset(
                $meta['pos_editing_in_progress'],
                $meta['pos_editing_at'],
                $meta['pos_editing_by'],
                $meta['stock_reverse_pending'],
            );
            $meta['superseded_by_edit'] = true;
            $meta['superseded_at'] = now()->toIso8601String();
            $meta['original_order_num'] = $requested;
            $meta['edit_checkout_completed'] = true;

            $existing->update([
                'order_num' => $allocator->tombstoneForSupersededSale((int) $existing->id),
                'status' => 'cancelled',
                'cancelled_at' => $existing->cancelled_at ?? now(),
                'cancelled_by' => $existing->cancelled_by ?? $user->id,
                'archived' => 1,
                'pos_order_num' => null,
                'pos_order_date' => null,
                'fulfillment_meta' => $meta,
            ]);

            app(\App\Services\Accounting\ReferenceJournalReversalService::class)->reverseIfEnabled(
                'sale',
                (int) $existing->id,
                $user,
                app(ErpContext::class)->gateForUser($user),
            );
            app(CustomerInvoiceService::class)->voidForCancelledSale($existing->fresh(), $user);
            app(\App\Services\Notifications\ActionRequestService::class)->cancelAllPendingForSale(
                $existing->fresh(),
                $user,
                'Order superseded by revised checkout.',
            );
            $allocator->reserveSpecificForOrganization($orgId, $requested);

            return $requested;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createSaleWithOrderNum(int $orderNum, array $attributes, int $organizationId): Sale
    {
        $allocator = app(OrderNumberAllocator::class);
        $attributes['order_num'] = $orderNum;
        unset($attributes['__lock_pos_ticket']);
        $lastError = null;

        for ($attempt = 0; $attempt < 6; $attempt++) {
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
                    (
                        str_contains($message, 'uq_pos_daily_order_num')
                        || str_contains($message, 'uq_pos_ticket_scope_num')
                    )
                    && ! empty($attributes['cashier_id'])
                    && ! empty($attributes['organization_id'])
                ) {
                    // Collision on Cash Sales # (offline sync race / counter drift):
                    // bump to the next free ticket so the sale still uploads.
                    $pos = app(PosDailyOrderNumberAllocator::class)->allocateForCheckout(
                        (int) $attributes['organization_id'],
                        (int) $attributes['cashier_id'],
                        isset($attributes['pos_order_date']) ? (string) $attributes['pos_order_date'] : null,
                        isset($attributes['float_session_id']) ? (int) $attributes['float_session_id'] : null,
                    );
                    if ($pos === null) {
                        throw new InvalidArgumentException(
                            'Cash Sales #'.($attributes['pos_order_num'] ?? '').' is already used and a free ticket could not be allocated. Try sync again.',
                        );
                    }
                    $attributes['pos_order_num'] = $pos['pos_order_num'];
                    $attributes['pos_order_date'] = $pos['pos_order_date'];
                    continue;
                }
                throw new InvalidArgumentException(
                    'Could not save this sale because an order number collided. Please try sync again.',
                    0,
                    $e,
                );
            }
        }

        throw new InvalidArgumentException(
            'Could not allocate a unique order or Cash Sales number. Please try sync again.',
            0,
            $lastError,
        );
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

    /**
     * Record that fiscalization was skipped because order total meets the org amount bypass.
     * Appears on Unfiscalized sales with a clear reason (status=skipped).
     *
     * @param  array<string, mixed>  $finance
     */
    protected function recordKraAmountBypass(Sale $sale, array $finance): KraResponse
    {
        return app(CheckoutKraSubmissionService::class)->recordAmountBypass($sale, $finance);
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

        return app(CheckoutKraSubmissionService::class)->submitForSale($sale, $gate, $buyerPin);
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

    /**
     * Previous-order edit with every line removed: cancel the live sale in place
     * (same order_num), record the full return as payment_adjustments, clear the cart.
     * Stock/fiscal were already reversed on restore-to-cart — do not re-deduct.
     *
     * @return array{sale: Sale, deduct_stock: bool, run_side_effects: bool}
     */
    protected function checkoutEmptyPreviousOrderEditCancel(
        TemporaryCart $cart,
        User $user,
        CapabilityGate $gate,
        array $input,
    ): array {
        return DB::transaction(function () use ($cart, $user, $gate, $input) {
            $lockedCart = TemporaryCart::query()->whereKey($cart->id)->lockForUpdate()->first();
            if (! $lockedCart) {
                throw new InvalidArgumentException(
                    'Cart not found. It may have already been checked out — reopen the order to continue editing.',
                );
            }
            $cart = $lockedCart;

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

            $supersededId = (int) ($cart->superseded_sale_id ?? 0);
            $sale = Sale::query()->find($supersededId);
            if (! $sale) {
                throw new InvalidArgumentException('Previous order for this empty edit was not found.');
            }

            $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
            $alreadyCancelled = (string) $sale->status === 'cancelled';
            $editingInProgress = ! empty($meta['pos_editing_in_progress']);
            if (! $editingInProgress && ! $alreadyCancelled) {
                throw new InvalidArgumentException(
                    'This order is not open for an empty previous-order edit cancel.',
                );
            }

            // Idempotent retry after cart was recreated: sale already cancelled with return.
            if ($alreadyCancelled && ! empty($meta['cancelled_via_empty_previous_order_edit'])) {
                $this->releaseCartReservations($cart->id);
                CartLine::where('cart_id', $cart->id)->delete();
                $cart->delete();
                $sale = $sale->fresh([
                    'items.product.unit',
                    'payments.paymentMethod',
                    'kraResponse',
                    'cashier:id,username,full_name',
                ]);

                return [
                    'sale' => $sale,
                    'deduct_stock' => false,
                    'run_side_effects' => false,
                ];
            }

            $adjustmentRows = $this->normalizeCheckoutPaymentAdjustments($input['payment_adjustments'] ?? null);
            $returnTotal = round(array_sum(array_map(
                static fn (array $row) => ($row['adjustment_type'] ?? '') === 'return'
                    ? (float) ($row['amount'] ?? 0)
                    : 0.0,
                $adjustmentRows,
            )), 2);
            $expectedReturn = round(max(
                (float) ($sale->order_total ?? 0),
                (float) ($sale->amount_paid ?? 0),
            ), 2);
            $offlineOrder = filter_var($input['offline_order'] ?? false, FILTER_VALIDATE_BOOLEAN);
            // Offline empty-cancel sync often queues before the cashier finishes the
            // return dialog — fill a full return on the prior tender so the cancel lands.
            if (
                $offlineOrder
                && $expectedReturn > 0.009
                && $returnTotal <= 0.009
            ) {
                $method = strtoupper(trim((string) (
                    $input['payment_method_code']
                    ?? $sale->payment_method_code
                    ?? 'CASH'
                )));
                if ($method === '') {
                    $method = 'CASH';
                }
                $adjustmentRows = [[
                    'adjustment_type' => 'return',
                    'method_code' => $method,
                    'amount' => $expectedReturn,
                    'reference_number' => null,
                ]];
                $returnTotal = $expectedReturn;
            }
            if ($expectedReturn > 0.009 && abs($returnTotal - $expectedReturn) > 0.02) {
                throw new InvalidArgumentException(
                    'Record the full return of '.number_format($expectedReturn, 2, '.', '')
                    .' before cancelling this empty order edit.',
                );
            }
            if ($expectedReturn > 0.009 && $returnTotal <= 0.009) {
                throw new InvalidArgumentException(
                    'Record how the return was paid before cancelling this empty order edit.',
                );
            }
            foreach ($adjustmentRows as $row) {
                if (($row['adjustment_type'] ?? '') === 'topup') {
                    throw new InvalidArgumentException(
                        'Empty previous-order edits only accept return payment adjustments.',
                    );
                }
            }

            $statusBefore = (string) ($meta['original_status'] ?? $sale->status);
            if ($statusBefore === '' || $statusBefore === 'cancelled') {
                $statusBefore = 'completed';
            }
            unset(
                $meta['pos_editing_in_progress'],
                $meta['pos_editing_at'],
                $meta['pos_editing_by'],
            );
            $meta['status_before_cancel'] = $statusBefore;
            $meta['cancelled_via_empty_previous_order_edit'] = true;
            $meta['cancelled_via_empty_previous_order_edit_at'] = now()->toIso8601String();
            $meta = $idempotency->stampFulfillmentMeta($meta, $input);

            $orderNum = (int) ($cart->held_order_num ?? $sale->order_num);
            $allocator = app(OrderNumberAllocator::class);
            if (
                $orderNum > 0
                && (int) $sale->order_num !== $orderNum
                && (int) $sale->order_num >= 9_000_000
                && ! $allocator->isLiveOrderNumTaken(
                    (int) $sale->organization_id,
                    $orderNum,
                    (int) $sale->id,
                )
            ) {
                // Older restore flows tombstoned the order_num — reclaim only if still free.
                $sale->order_num = $orderNum;
            }

            // Keep Cash Sales # on the cancelled sale so it is never reallocated.
            $sale->update([
                'status' => 'cancelled',
                'cancelled_at' => $sale->cancelled_at ?? now(),
                'cancelled_by' => $sale->cancelled_by ?? $user->id,
                'stock_balanced' => 0,
                'archived' => 0,
                'fulfillment_meta' => $meta === [] ? null : $meta,
                'order_num' => (int) $sale->order_num > 0 ? (int) $sale->order_num : $orderNum,
            ]);

            $sale = $sale->fresh();
            app(CustomerInvoiceService::class)->voidForCancelledSale($sale, $user);
            app(ReferenceJournalReversalService::class)->reverseIfEnabled(
                'sale',
                (int) $sale->id,
                $user,
                $gate,
            );
            app(ActionRequestService::class)->cancelAllPendingForSale(
                $sale,
                $user,
                'Order cancelled after all lines were removed in a previous-order edit.',
            );

            $floatSessionId = isset($input['float_session_id'])
                ? (int) $input['float_session_id']
                : ($cart->float_session_id ? (int) $cart->float_session_id : null);
            if ($adjustmentRows !== []) {
                app(SalePaymentAdjustmentService::class)->recordForSale(
                    $sale,
                    $adjustmentRows,
                    $floatSessionId,
                    $input['payment_date'] ?? now(),
                );
            }

            $this->releaseCartReservations($cart->id);
            CartLine::where('cart_id', $cart->id)->delete();
            $cart->delete();

            $sale = $sale->fresh([
                'items.product.unit',
                'payments.paymentMethod',
                'kraResponse',
                'cashier:id,username,full_name',
            ]);

            return [
                'sale' => $sale,
                'deduct_stock' => false,
                'run_side_effects' => false,
            ];
        }, 5);
    }

    /**
     * @return list<array{method_code: string, amount: float, adjustment_type: string, reference_number: ?string}>
     */
    protected function normalizeCheckoutPaymentAdjustments(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $normalized = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $methodCode = strtoupper(trim((string) ($row['method_code'] ?? '')));
            $type = strtolower(trim((string) ($row['adjustment_type'] ?? '')));
            $amount = round((float) ($row['amount'] ?? 0), 2);
            if ($methodCode === '' || $amount <= 0 || ! in_array($type, ['return', 'topup'], true)) {
                continue;
            }
            $reference = trim((string) ($row['reference_number'] ?? ''));
            $normalized[] = [
                'method_code' => $methodCode,
                'amount' => $amount,
                'adjustment_type' => $type,
                'reference_number' => $reference !== '' ? $reference : null,
            ];
        }

        return $normalized;
    }

    /**
     * Force previous-order edit return/top-up rows to equal |revised − prior| order total.
     * Clients sometimes send the full new bill (or F10 pay_now) as a top-up; that must not
     * land in sale_payment_adjustments or inflate Payments Breakdown.
     *
     * @param  list<array{method_code: string, amount: float, adjustment_type: string, reference_number: ?string}>  $adjustmentRows
     * @return list<array{method_code: string, amount: float, adjustment_type: string, reference_number: ?string}>
     */
    protected function reconcilePreviousOrderEditAdjustments(
        array $adjustmentRows,
        float $priorTotal,
        float $newTotal,
        string $fallbackMethodCode = 'CASH',
    ): array {
        $expectedSigned = round($newTotal - $priorTotal, 2);
        if (abs($expectedSigned) < 0.01) {
            return [];
        }

        $type = $expectedSigned < 0 ? 'return' : 'topup';
        $expectedAbs = round(abs($expectedSigned), 2);

        $rows = array_values(array_filter(
            $adjustmentRows,
            static fn (array $row): bool => ($row['adjustment_type'] ?? '') === $type,
        ));

        $fallback = strtoupper(trim($fallbackMethodCode)) ?: 'CASH';
        if ($rows === []) {
            return [[
                'method_code' => $fallback,
                'amount' => $expectedAbs,
                'adjustment_type' => $type,
                'reference_number' => null,
            ]];
        }

        $sum = round(array_sum(array_map(
            static fn (array $row): float => (float) ($row['amount'] ?? 0),
            $rows,
        )), 2);

        if (abs($sum - $expectedAbs) < 0.02) {
            return $rows;
        }

        if ($sum <= 0.009) {
            $first = $rows[0];

            return [[
                'method_code' => strtoupper((string) ($first['method_code'] ?? $fallback)) ?: $fallback,
                'amount' => $expectedAbs,
                'adjustment_type' => $type,
                'reference_number' => $first['reference_number'] ?? null,
            ]];
        }

        $factor = $expectedAbs / $sum;
        $scaled = [];
        foreach ($rows as $row) {
            $scaled[] = [
                'method_code' => strtoupper((string) ($row['method_code'] ?? $fallback)) ?: $fallback,
                'amount' => round((float) ($row['amount'] ?? 0) * $factor, 2),
                'adjustment_type' => $type,
                'reference_number' => $row['reference_number'] ?? null,
            ];
        }

        $scaledSum = round(array_sum(array_column($scaled, 'amount')), 2);
        $drift = round($expectedAbs - $scaledSum, 2);
        if (abs($drift) >= 0.01 && $scaled !== []) {
            $largest = 0;
            foreach ($scaled as $i => $row) {
                if ((float) $row['amount'] >= (float) $scaled[$largest]['amount']) {
                    $largest = $i;
                }
            }
            $scaled[$largest]['amount'] = round((float) $scaled[$largest]['amount'] + $drift, 2);
        }

        return array_values(array_filter(
            $scaled,
            static fn (array $row): bool => (float) ($row['amount'] ?? 0) > 0.009,
        ));
    }

    /**
     * Rebuild sale_payments + tender columns for a previous-order edit.
     * Prior mix +/- return/topup adjustments = net tenders that match $targetPaid
     * (revised total when prior was fully paid; preserved prior paid when unpaid/partial).
     * Keeps X/Z/EOD Equity/Cash/M-Pesa correct after offline sync (no wipe, no CASH reclass).
     *
     * @param  list<array{method_code: string, amount: float, adjustment_type: string, reference_number: ?string}>  $adjustmentRows
     */
    protected function applyPreviousOrderEditTenders(
        Sale $prior,
        Sale $sale,
        array $adjustmentRows,
        ?int $floatSessionId,
        float $newTotal,
        float $targetPaid,
        mixed $paidAt,
        bool $forceFullyPaid = false,
    ): void {
        $map = $this->priorSaleTenderMap($prior);

        foreach ($adjustmentRows as $row) {
            $code = strtoupper((string) ($row['method_code'] ?? ''));
            $amount = round((float) ($row['amount'] ?? 0), 2);
            if ($code === '' || $amount <= 0) {
                continue;
            }
            // Returns stay on payment_adjustments / Change Given — do not drain an
            // unrelated tender (Cash refund must not reduce prior M-Pesa on the receipt).
            if (($row['adjustment_type'] ?? '') === 'return') {
                continue;
            }
            // Alias Eco / Ecobank onto Equity so receipt + X/Z columns stay consistent.
            if ($code === 'ECO' || str_contains($code, 'ECOBANK')) {
                $code = 'EQUITY';
            }
            $current = (float) ($map[$code] ?? 0);
            $map[$code] = round($current + $amount, 2);
        }

        $map = array_filter(
            $map,
            static fn (float $amount) => $amount > 0.009,
        );

        // Non-credit External POS / backoffice till edits must settle the full revised bill.
        if ($forceFullyPaid) {
            $targetPaid = max(0, round($newTotal, 2));
        } else {
            $targetPaid = max(0, round($targetPaid, 2));
        }
        // Unpaid prior (and no adjustments): keep empty tenders — never invent CASH.
        // Forced full-pay still needs a tender map so amount_paid matches the bill.
        $hasReturnAdjustment = false;
        foreach ($adjustmentRows as $row) {
            if (($row['adjustment_type'] ?? '') === 'return' && (float) ($row['amount'] ?? 0) > 0.009) {
                $hasReturnAdjustment = true;
                break;
            }
        }
        if ($targetPaid <= 0.009) {
            $map = [];
        } elseif ($map === [] && $forceFullyPaid) {
            $fallback = strtoupper(trim((string) ($prior->payment_method_code ?? 'CASH'))) ?: 'CASH';
            if ($fallback === 'CREDIT') {
                $fallback = 'CASH';
            }
            $map = [$fallback => $targetPaid];
        } elseif ($hasReturnAdjustment) {
            // Full cancel / empty revise: clear tender columns — the return lives on adjustments.
            // Partial return: keep prior (+ top-up) method amounts for the receipt. amount_paid
            // below still settles to the revised bill; Change Given comes from adjustments.
            if ($newTotal <= 0.009) {
                $map = [];
            }
        } else {
            $map = $this->normalizeTenderMapToTotal($map, $targetPaid);
        }

        SalePayment::query()->where('sale_id', $sale->id)->delete();
        SalePaymentColumnMapper::replaceFromMethodMap($sale, $map);

        $paidFromTenders = round(array_sum($map), 2);
        if ($forceFullyPaid || $hasReturnAdjustment) {
            $paidFromTenders = max(0, round($newTotal, 2));
        }
        $sale->update([
            'payment_method_code' => $this->primaryPaymentMethodCode($map, $prior),
            'amount_paid' => $paidFromTenders,
            'payment_status' => $forceFullyPaid
                ? 'paid'
                : $this->derivePaymentStatus($newTotal, $paidFromTenders),
        ]);

        foreach ($map as $methodCode => $amount) {
            $method = $this->resolveCheckoutPaymentMethod(
                (int) $sale->organization_id,
                (string) $methodCode,
            );
            if (! $method) {
                continue;
            }
            SalePayment::create([
                'sale_id' => $sale->id,
                'float_session_id' => $floatSessionId,
                'payment_method_id' => $method->id,
                'amount' => round((float) $amount, 2),
                'reference_number' => null,
                'paid_at' => $paidAt,
            ]);
        }
    }

    /**
     * @return array<string, float> method_code => amount
     */
    protected function priorSaleTenderMap(Sale $prior): array
    {
        $prior->loadMissing('payments.paymentMethod');
        $map = [];

        foreach ($prior->payments ?? [] as $payment) {
            $code = strtoupper((string) ($payment->paymentMethod?->method_code ?? ''));
            if ($code === '') {
                continue;
            }
            // Prefer column identity when the payment row was aliased (EQUITY→BANK).
            if (in_array($code, ['BANK', 'BANK_TRANSFER'], true)) {
                if ((float) ($prior->equity_amount ?? 0) > 0.009 && ! isset($map['EQUITY'])) {
                    $code = 'EQUITY';
                } elseif ((float) ($prior->kcb_amount ?? 0) > 0.009 && ! isset($map['KCB'])) {
                    $code = 'KCB';
                }
            }
            $map[$code] = round((float) ($map[$code] ?? 0) + (float) $payment->amount, 2);
        }

        if ($map !== []) {
            return $map;
        }

        // Legacy / column-only tenders.
        foreach ([
            'CASH' => (float) ($prior->cash ?? 0),
            'MPESA' => (float) ($prior->mpesa_amount ?? 0),
            'EQUITY' => (float) ($prior->equity_amount ?? 0),
            'KCB' => (float) ($prior->kcb_amount ?? 0),
        ] as $code => $amount) {
            if ($amount > 0.009) {
                $map[$code] = round($amount, 2);
            }
        }

        if ($map === [] && (float) ($prior->amount_paid ?? 0) > 0.009) {
            $fallback = strtoupper((string) ($prior->payment_method_code ?? 'CASH')) ?: 'CASH';
            $map[$fallback] = round((float) $prior->amount_paid, 2);
        }

        return $map;
    }

    /**
     * Scale / pad tender mix so it equals the revised order total.
     *
     * @param  array<string, float>  $map
     * @return array<string, float>
     */
    protected function normalizeTenderMapToTotal(array $map, float $newTotal): array
    {
        $newTotal = max(0, round($newTotal, 2));
        if ($newTotal <= 0.009) {
            return [];
        }

        $sum = round(array_sum($map), 2);
        if ($sum <= 0.009) {
            // Do not invent a full CASH tender for unpaid priors — callers that need a
            // settlement must pass a non-empty mix (paid POS re-edit) or a zero target.
            return [];
        }

        if (abs($sum - $newTotal) < 0.02) {
            return $map;
        }

        $factor = $newTotal / $sum;
        $scaled = [];
        foreach ($map as $code => $amount) {
            $scaled[$code] = round((float) $amount * $factor, 2);
        }

        $scaledSum = round(array_sum($scaled), 2);
        $drift = round($newTotal - $scaledSum, 2);
        if (abs($drift) >= 0.01) {
            $largest = array_key_first($scaled);
            foreach ($scaled as $code => $amount) {
                if ($amount >= ($scaled[$largest] ?? 0)) {
                    $largest = $code;
                }
            }
            $scaled[$largest] = round(($scaled[$largest] ?? 0) + $drift, 2);
        }

        return array_filter(
            $scaled,
            static fn (float $amount) => $amount > 0.009,
        );
    }

    /**
     * Header payment method for a previous-order edit — keep the prior method when it
     * still has tender, otherwise the largest remaining tender (never force CASH).
     *
     * @param  array<string, float>  $map
     */
    protected function primaryPaymentMethodCode(array $map, Sale $prior): string
    {
        $priorCode = strtoupper(trim((string) ($prior->payment_method_code ?? '')));
        if ($priorCode !== '' && isset($map[$priorCode]) && (float) $map[$priorCode] > 0.009) {
            return $priorCode;
        }

        if ($map === []) {
            return $priorCode !== '' ? $priorCode : 'CASH';
        }

        $largest = array_key_first($map);
        foreach ($map as $code => $amount) {
            if ((float) $amount >= (float) ($map[$largest] ?? 0)) {
                $largest = $code;
            }
        }

        $code = strtoupper(trim((string) $largest));

        return $code !== '' ? $code : 'CASH';
    }

    protected function copyPriorSalePaymentsToSale(Sale $prior, Sale $sale, ?int $floatSessionId): void
    {
        $prior->loadMissing('payments');
        foreach ($prior->payments ?? [] as $payment) {
            SalePayment::create([
                'sale_id' => $sale->id,
                'float_session_id' => $floatSessionId ?? $payment->float_session_id,
                'payment_method_id' => $payment->payment_method_id,
                'amount' => (float) $payment->amount,
                'reference_number' => $payment->reference_number,
                'paid_at' => $payment->paid_at ?? now(),
            ]);
            $methodCode = strtoupper((string) ($payment->paymentMethod?->method_code
                ?? PaymentMethod::query()->find($payment->payment_method_id)?->method_code
                ?? ''));
            if ($methodCode !== '') {
                SalePaymentColumnMapper::applyToSale($sale->fresh(), $methodCode, (float) $payment->amount);
            }
        }
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

    /**
     * Rebuild sales.cash / mpesa_amount / … from sale_payments so thermal receipts
     * never print zeros when payment rows were created successfully.
     */
    protected function syncSaleTenderColumnsFromPayments(Sale $sale): void
    {
        $payments = $sale->relationLoaded('payments')
            ? $sale->payments
            : $sale->payments()->with('paymentMethod')->get();

        if ($payments->isEmpty()) {
            $payments = $sale->payments()->with('paymentMethod')->get();
        } else {
            $payments->loadMissing('paymentMethod');
        }

        if ($payments->isEmpty()) {
            return;
        }

        $map = [];
        foreach ($payments as $payment) {
            $code = strtoupper(trim((string) ($payment->paymentMethod?->method_code ?? '')));
            if ($code === '') {
                continue;
            }
            $map[$code] = ($map[$code] ?? 0) + (float) $payment->amount;
        }

        if ($map === []) {
            return;
        }

        SalePaymentColumnMapper::replaceFromMethodMap($sale, $map);
    }

    /**
     * Ensure every cart line still maps to an active, branch-visible product.
     *
     * @param  \Illuminate\Support\Collection<int, CartLine>  $lines
     * @return \Illuminate\Support\Collection<string, \App\Models\Product> keyed by lowercased product_code
     */
    protected function assertCheckoutLinesAccessible(TemporaryCart $cart, $lines, User $user)
    {
        $request = request();
        $orgId = (int) ($this->userAccess()->organizationId($user, $request) ?? $user->organization_id ?? 0);
        $branchId = (int) ($cart->branch_id ?? $user->branch_id ?? 0);
        $catalog = app(ProductCatalogScopeService::class);
        $byCode = collect();

        foreach ($lines as $line) {
            $code = trim((string) ($line->product_code ?? ''));
            if ($code === '') {
                throw ValidationException::withMessages([
                    'product_code' => ['Product code is required on every cart line.'],
                ]);
            }

            $key = strtolower($code);
            if ($byCode->has($key)) {
                continue;
            }

            $product = $catalog->findAccessibleProduct($code, $orgId, $branchId);
            $byCode->put($key, $product->loadMissing(['unit', 'vat']));
        }

        return $byCode;
    }

    /** Buyer KRA PIN for eTIMS — explicit checkout input, then linked customer record. */
    protected function resolveCheckoutBuyerPin(?string $inputPin, ?Customer $customer, ?int $customerNum): ?string
    {
        $pin = trim((string) ($inputPin ?? ''));
        if ($pin !== '') {
            return $pin;
        }

        if ($customer) {
            $pin = trim((string) ($customer->kra_pin ?? ''));
            if ($pin !== '') {
                return $pin;
            }
        }

        if ($customerNum) {
            $resolved = Customer::query()
                ->where('customer_num', $customerNum)
                ->whereNull('deleted_at')
                ->value('kra_pin');
            $pin = trim((string) ($resolved ?? ''));

            return $pin !== '' ? $pin : null;
        }

        return null;
    }

    /** Order # stored on kra_responses — POS uses daily Cash Sales ticket. */
    protected function kraDisplayOrderNo(Sale $sale): int
    {
        return app(CheckoutKraSubmissionService::class)->displayOrderNo($sale);
    }
}
