<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesCartPayments;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\LoyaltyCard;
use App\Models\Voucher;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CheckoutRequest;
use App\Models\CartLine;
use App\Models\ChartOfAccount;
use App\Models\CustomerInvoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\KraResponse;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\TemporaryCart;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CheckoutController extends Controller
{
    use HandlesCartPayments;
    use HandlesInventory;

    public function __construct(protected ErpContext $erp) {}

    public function fromCart(CheckoutRequest $request, int $cartId)
    {
        $cart = TemporaryCart::with('lines')->findOrFail($cartId);
        if ((int) $cart->user_id !== (int) $request->user()->id) {
            abort(403, 'This cart belongs to another cashier.');
        }
        $gate = $this->erp->gateForUser($request->user());
        $sale = $this->checkoutFromCart($cart, $request->user(), $gate, $request->validated());

        return response()->json($sale, 201);
    }

    protected function checkoutFromCart(TemporaryCart $cart, User $user, CapabilityGate $gate, array $input): Sale
    {
        $lines = CartLine::where('cart_id', $cart->id)->get();
        if ($lines->isEmpty()) {
            throw new InvalidArgumentException('Cart is empty.');
        }

        $inventorySettings = $gate->moduleSettings('inventory');
        $salesSettings = $gate->moduleSettings('sales');
        $txnType = $this->saleTransactionType($cart->channel);

        $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);

        return DB::transaction(function () use ($cart, $user, $gate, $input, $lines, $inventorySettings, $salesSettings, $txnType, $allowBelowStock) {
            $stockDeducted = false;
            $orderNum = (int) ($input['order_num'] ?? $this->nextOrderNum());
            $lineNet = (float) $lines->sum('amount');
            $orderDiscount = ! empty($salesSettings['enable_order_discount'])
                ? min(max(0, (float) ($cart->order_discount ?? 0)), $lineNet)
                : 0;
            $total = max(0, $lineNet - $orderDiscount);
            $vat = (float) ($input['total_vat'] ?? $lines->sum('product_vat'));
            $isCredit = (bool) ($input['is_credit_sale'] ?? false);
            $payNow = (float) ($input['pay_now'] ?? 0);

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
            if (! $isCredit && $payNow <= 0 && $cashDue > 0) {
                $payNow = $cashDue;
            }
            $payNow = min($payNow, $cashDue);
            $amountPaid = $payNow + $voucherPayment + $pointsPayment;
            $customerNum = $input['customer_num'] ?? null;
            if (! $customerNum && $loyaltyCardId) {
                $customerNum = LoyaltyCard::find($loyaltyCardId)?->customer_num;
            }

            $sale = Sale::create([
                'order_num' => $orderNum,
                'branch_id' => $cart->branch_id ?? $user->branch_id,
                'organization_id' => $user->organization_id,
                'channel' => $cart->channel,
                'till_id' => $cart->till_id,
                'float_session_id' => $input['float_session_id'] ?? null,
                'cashier_id' => $user->id,
                'customer_num' => $customerNum,
                'customer_name_override' => $input['customer_name_override'] ?? null,
                'route_id' => $cart->route_id,
                'status' => $input['status'] ?? ($cart->channel === 'pos' ? 'completed' : 'booked'),
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
            ]);

            if ($sale->status === 'completed') {
                $sale->update(['completed_at' => now()]);
            }

            foreach ($lines as $i => $line) {
                $isRetailLine = (bool) $line->on_wholesale_retail;
                $location = $this->saleLineStockLocation(
                    $cart->channel,
                    $inventorySettings,
                    $salesSettings,
                    $isRetailLine,
                );

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_code' => $line->product_code,
                    'line_no' => $line->line_no ?: ($i + 1),
                    'item_code' => (string) ($line->line_no ?: ($i + 1)),
                    'quantity' => $line->quantity,
                    'uom' => $line->uom,
                    'selling_price' => $line->unit_price,
                    'discount_given' => (float) ($line->discount_given ?? 0),
                    'product_vat' => $line->product_vat ?? 0,
                    'amount' => $line->amount,
                    'on_wholesale_retail' => $line->on_wholesale_retail,
                ]);

                if (($input['deduct_stock'] ?? true) && $sale->status === 'completed') {
                    $this->postStockLedger([
                        'branch_id' => $sale->branch_id,
                        'product_code' => $line->product_code,
                        'stock_location' => $location,
                        'transaction_type' => $txnType,
                        'reference_type' => 'sale',
                        'reference_id' => $sale->id,
                        'quantity_change' => -abs((float) $line->quantity),
                        'created_by' => $user->id,
                    ], $allowBelowStock);
                    $stockDeducted = true;
                }
            }

            if (! empty($stockDeducted)) {
                $sale->update(['stock_balanced' => 1]);
            }

            if ($voucherPayment > 0 && $cart->payment_voucher_id) {
                $voucher = Voucher::lockForUpdate()->find($cart->payment_voucher_id);
                if ($voucher) {
                    $method = PaymentMethod::where('method_code', 'VOUCHER')->first();
                    if ($method) {
                        SalePayment::create([
                            'sale_id' => $sale->id,
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

            if ($pointsPayment > 0 && $loyaltyCardId) {
                $card = LoyaltyCard::lockForUpdate()->find($loyaltyCardId);
                if ($card) {
                    $method = PaymentMethod::where('method_code', 'POINTS')->first();
                    if ($method) {
                        SalePayment::create([
                            'sale_id' => $sale->id,
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
                        'payment_method_id' => $method->id,
                        'amount' => $payNow,
                        'reference_number' => $input['payment_reference'] ?? null,
                        'paid_at' => $input['payment_date'] ?? now(),
                    ]);
                }
            }

            if ($sale->status === 'completed') {
                $this->awardLoyaltyPointsForCompletedSale(
                    (int) $sale->organization_id,
                    $customerNum ? (int) $customerNum : null,
                    $salesSettings,
                    $total,
                    $loyaltyCardId,
                );
            }

            if ($isCredit && $sale->customer_num) {
                CustomerInvoice::create([
                    'invoice_number' => 'AR-' . $sale->order_num,
                    'sale_id' => $sale->id,
                    'customer_num' => $sale->customer_num,
                    'branch_id' => $sale->branch_id,
                    'organization_id' => $sale->organization_id,
                    'created_by' => $user->id,
                    'invoice_date' => now()->toDateString(),
                    'total_vat' => $sale->total_vat,
                    'invoice_total' => $total,
                    'amount_paid' => $amountPaid,
                    'payment_status' => $amountPaid >= $total ? 2 : ($amountPaid > 0 ? 1 : 0),
                ]);
            }

            $this->releaseCartReservations($cart->id);
            CartLine::where('cart_id', $cart->id)->delete();
            $cart->delete();

            $sale = $sale->fresh(['items']);

            $this->queueKraReceipt($sale, (bool) ($input['submit_kra'] ?? true));
            $this->postSaleJournalIfEnabled($sale, $user, $gate);

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
        return (int) (Sale::max('order_num') ?? 90000) + 1;
    }

    protected function queueKraReceipt(Sale $sale, bool $submit = true): ?KraResponse
    {
        if (! $submit) {
            return null;
        }

        return KraResponse::create([
            'sale_id' => $sale->id,
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

    protected function postSaleJournalIfEnabled(Sale $sale, User $user, CapabilityGate $gate): ?JournalEntry
    {
        if (! $gate->enabled('accounting')) {
            return null;
        }

        $settings = $gate->moduleSettings('accounting') ?? [];
        if (! ($settings['auto_post_sales'] ?? true)) {
            return null;
        }

        $orgId = $sale->organization_id;
        $cash = ChartOfAccount::where('organization_id', $orgId)
            ->where('account_code', '1000')->first();
        $sales = ChartOfAccount::where('organization_id', $orgId)
            ->where('account_code', '4000')->first();

        if (! $cash || ! $sales) {
            return null;
        }

        $net = (float) $sale->order_total - (float) $sale->total_vat;
        $entryNumber = 'SALE-' . $sale->order_num;

        if (JournalEntry::where('organization_id', $orgId)->where('entry_number', $entryNumber)->exists()) {
            return null;
        }

        $entry = JournalEntry::create([
            'organization_id' => $orgId,
            'branch_id' => $sale->branch_id,
            'entry_number' => $entryNumber,
            'entry_date' => now()->toDateString(),
            'reference_type' => 'sale',
            'reference_id' => $sale->id,
            'description' => 'Auto journal for sale #' . $sale->order_num,
            'status' => 'posted',
            'created_by' => $user->id,
            'posted_at' => now(),
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cash->id,
            'debit' => $net,
            'credit' => 0,
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $sales->id,
            'debit' => 0,
            'credit' => $net,
        ]);

        return $entry;
    }
}
