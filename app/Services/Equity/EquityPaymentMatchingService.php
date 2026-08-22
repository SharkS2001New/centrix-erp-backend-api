<?php

namespace App\Services\Equity;

use App\Models\Customer;
use App\Models\EquityBankAccount;
use App\Models\EquityIncomingPayment;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\User;
use App\Services\Customers\CustomerPhoneLookup;
use App\Services\Mpesa\MpesaPaymentReferenceParser;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;

class EquityPaymentMatchingService
{
    public function __construct(
        protected MpesaPaymentReferenceParser $referenceParser,
        protected CustomerPhoneLookup $customerPhoneLookup,
        protected EquityBankAccountService $bankAccounts,
    ) {}

    public function isEnabledForOrganization(Organization $organization): bool
    {
        return EquitySettingsResolver::isPaybillReconciliationEnabledForOrganization($organization);
    }

    public function enrichPayment(EquityIncomingPayment $payment): EquityIncomingPayment
    {
        $parsed = $this->referenceParser->parse((string) ($payment->bill_ref_number ?? ''));

        $payment->fill([
            'parsed_order_num' => $parsed['order_num'] ?? null,
            'parsed_customer_num' => $parsed['customer_num'] ?? null,
        ]);

        $best = $this->findBestMatch($payment);
        if ($best) {
            $payment->fill([
                'match_method' => $best['method'],
                'match_confidence' => $best['confidence'],
            ]);
        }

        $payment->save();

        return $payment->fresh();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findCandidates(EquityIncomingPayment $payment, int $limit = 5): array
    {
        $organizationId = (int) $payment->organization_id;
        if ($organizationId <= 0) {
            return [];
        }

        $candidates = collect();
        $amount = (float) $payment->amount;
        $paymentAccount = null;
        if ($payment->equity_bank_account_id) {
            $paymentAccount = EquityBankAccount::query()->find((int) $payment->equity_bank_account_id);
        } elseif (trim((string) $payment->business_account_number) !== '') {
            $paymentAccount = $this->bankAccounts->findActiveByAccountNumber((string) $payment->business_account_number);
        }

        if ($payment->parsed_order_num) {
            $sale = $this->findSaleByOrderNum($organizationId, (int) $payment->parsed_order_num);
            if ($sale && $this->bankAccounts->paymentMatchesSale(
                $paymentAccount,
                $payment->business_account_number,
                $sale,
            )) {
                $candidates->push($this->candidateFromSale($sale, 'order_ref', $amount));
            }
        }

        if ($payment->parsed_customer_num) {
            $sales = $this->findOpenSalesForCustomer($organizationId, (int) $payment->parsed_customer_num, $amount);
            foreach ($sales as $sale) {
                if (! $this->bankAccounts->paymentMatchesSale(
                    $paymentAccount,
                    $payment->business_account_number,
                    $sale,
                )) {
                    continue;
                }
                $candidates->push($this->candidateFromSale($sale, 'customer_num', $amount));
            }
        }

        $phoneCustomer = $this->customerPhoneLookup->findByPhone($organizationId, (string) $payment->phone_number);
        if ($phoneCustomer) {
            $sales = $this->findOpenSalesForCustomer($organizationId, (int) $phoneCustomer->customer_num, $amount);
            foreach ($sales as $sale) {
                if (! $this->bankAccounts->paymentMatchesSale(
                    $paymentAccount,
                    $payment->business_account_number,
                    $sale,
                )) {
                    continue;
                }
                $candidates->push($this->candidateFromSale($sale, 'customer_phone', $amount));
            }
        }

        return $candidates
            ->unique(fn (array $row) => (int) $row['sale_id'])
            ->sortByDesc(function (array $row) use ($paymentAccount, $payment) {
                $confidence = $this->confidenceRank((string) $row['confidence']) * 10;
                $sale = Sale::query()->find((int) $row['sale_id']);
                if (! $sale) {
                    return $confidence;
                }
                $routeId = (int) ($paymentAccount?->route_id ?: $payment->matched_route_id ?: 0);
                $branchId = (int) ($paymentAccount?->branch_id ?: $payment->matched_branch_id ?: 0);
                if ($routeId > 0 && (int) $sale->route_id === $routeId) {
                    $confidence += 2;
                }
                if ($branchId > 0 && (int) $sale->branch_id === $branchId) {
                    $confidence += 1;
                }

                return $confidence;
            })
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return ?array{sale: Sale, method: string, confidence: string, balance_due: float}
     */
    public function findBestMatch(EquityIncomingPayment $payment): ?array
    {
        $candidates = $this->findCandidates($payment, 10);
        if ($candidates === []) {
            return null;
        }

        $best = $candidates[0];
        $sale = Sale::query()->find((int) $best['sale_id']);
        if (! $sale) {
            return null;
        }

        return [
            'sale' => $sale,
            'method' => (string) $best['method'],
            'confidence' => (string) $best['confidence'],
            'balance_due' => (float) $best['balance_due'],
        ];
    }

    public function resolveActingUser(EquityIncomingPayment $payment, Sale $sale): ?User
    {
        if ($sale->cashier_id) {
            $cashier = User::query()->find((int) $sale->cashier_id);
            if ($cashier && (int) $cashier->organization_id === (int) $payment->organization_id) {
                return $cashier;
            }
        }

        return User::query()
            ->where('organization_id', (int) $payment->organization_id)
            ->where(function ($query) {
                $query->where('is_admin', true)->orWhere('is_super_admin', true);
            })
            ->orderBy('id')
            ->first();
    }

    public function shouldAutoApply(Organization $organization, array $bestMatch): bool
    {
        $settings = EquitySettingsResolver::forOrganization($organization);
        if (($settings['auto_apply_order_reference'] ?? true) === false) {
            return false;
        }

        if (($bestMatch['confidence'] ?? '') !== 'high') {
            return false;
        }

        return ($bestMatch['method'] ?? '') === 'order_ref';
    }

    protected function findSaleByOrderNum(int $organizationId, int $orderNum): ?Sale
    {
        return Sale::query()
            ->with('customer')
            ->where('organization_id', $organizationId)
            ->where('order_num', $orderNum)
            ->whereNotIn('status', ['cancelled'])
            ->orderByDesc('id')
            ->first();
    }

    /** @return Collection<int, Sale> */
    protected function findOpenSalesForCustomer(int $organizationId, int $customerNum, float $amount): Collection
    {
        return Sale::query()
            ->with('customer')
            ->where('organization_id', $organizationId)
            ->where('customer_num', $customerNum)
            ->whereNotIn('status', ['cancelled', 'held'])
            ->whereRaw('(order_total - amount_paid) >= 0.01')
            ->orderByRaw('ABS((order_total - amount_paid) - ?) asc', [$amount])
            ->orderByDesc('id')
            ->limit(3)
            ->get();
    }

    /** @return array<string, mixed> */
    protected function candidateFromSale(Sale $sale, string $method, float $amount): array
    {
        $balanceDue = round((float) $sale->order_total - (float) $sale->amount_paid, 2);
        $confidence = $this->confidenceFor($method, $amount, $balanceDue);

        return [
            'sale_id' => (int) $sale->id,
            'order_num' => (int) $sale->order_num,
            'customer_num' => $sale->customer_num ? (int) $sale->customer_num : null,
            'customer_name' => $sale->customer_name_override ?: $sale->customer?->customer_name,
            'order_total' => (float) $sale->order_total,
            'amount_paid' => (float) $sale->amount_paid,
            'balance_due' => $balanceDue,
            'payment_status' => (string) $sale->payment_status,
            'status' => (string) $sale->status,
            'method' => $method,
            'confidence' => $confidence,
        ];
    }

    protected function confidenceFor(string $method, float $amount, float $balanceDue): string
    {
        $amountMatches = abs($amount - $balanceDue) <= 1.0 || ($balanceDue > 0 && $amount <= $balanceDue + 1.0);

        return match ($method) {
            'order_ref' => $amountMatches ? 'high' : 'medium',
            'customer_num' => $amountMatches ? 'medium' : 'low',
            'customer_phone' => $amountMatches ? 'medium' : 'low',
            default => 'low',
        };
    }

    protected function confidenceRank(string $confidence): int
    {
        return match ($confidence) {
            'high' => 3,
            'medium' => 2,
            default => 1,
        };
    }
}
