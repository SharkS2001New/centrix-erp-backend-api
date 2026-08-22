<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessEquityIncomingMatchJob;
use App\Models\EquityIncomingPayment;
use App\Models\Organization;
use App\Services\Equity\EquityBankAccountService;
use App\Services\Equity\EquitySettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Public Equity paybill / collection callback ingress.
 * Resolve tenant by account/paybill number registered in equity_bank_accounts.
 */
class EquityPaymentController extends Controller
{
    public function __construct(
        protected EquityBankAccountService $bankAccounts,
    ) {}

    public function confirmation(Request $request)
    {
        $payload = $request->all();
        if ($payload === []) {
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Empty payload'], 400);
        }

        $resolved = $this->bankAccounts->resolveFromCallbackPayload($payload);
        if (! $resolved) {
            Log::warning('Equity callback: unknown account number', [
                'account' => $this->bankAccounts->extractAccountNumber($payload),
            ]);

            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Unknown Equity account'], 404);
        }

        $organization = Organization::query()->find((int) $resolved['organization_id']);
        if (! $organization) {
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Organization not found'], 404);
        }

        $settings = EquitySettingsResolver::forOrganization($organization);
        $secret = trim((string) ($settings['callback_shared_secret'] ?? ''));
        if ($secret !== '' && $secret !== '********') {
            $provided = trim((string) (
                $request->header('X-Equity-Secret')
                ?? $request->header('X-Callback-Secret')
                ?? $payload['shared_secret']
                ?? $payload['SharedSecret']
                ?? ''
            ));
            if (! hash_equals($secret, $provided)) {
                return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback secret'], 401);
            }
        }

        if (! Schema::hasTable('equity_incoming_payments')) {
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Not configured'], 503);
        }

        $transactionId = $this->extractTransactionId($payload);
        $amount = $this->extractAmount($payload);
        if ($transactionId === '' || $amount < 1) {
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Missing transaction id or amount'], 422);
        }

        $account = $resolved['account'];
        $existing = EquityIncomingPayment::query()
            ->where('organization_id', (int) $resolved['organization_id'])
            ->where('transaction_id', $transactionId)
            ->first();
        if ($existing) {
            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'Already received',
                'payment_id' => $existing->id,
            ]);
        }

        $payment = EquityIncomingPayment::query()->create([
            'organization_id' => (int) $resolved['organization_id'],
            'equity_bank_account_id' => $account?->id,
            'matched_branch_id' => $resolved['branch_id'] ?? null,
            'matched_route_id' => $resolved['route_id'] ?? null,
            'transaction_id' => $transactionId,
            'phone_number' => $this->extractPhone($payload),
            'bill_ref_number' => $this->extractBillRef($payload),
            'payer_name' => $this->extractPayerName($payload),
            'business_account_number' => $this->bankAccounts->extractAccountNumber($payload) ?: ($account?->primary_account_number),
            'amount' => $amount,
            'source' => 'callback',
            'status' => 'available',
            'reconciliation_status' => 'unmatched',
            'received_at' => now(),
        ]);

        if (EquitySettingsResolver::isPaybillReconciliationEnabledForOrganization($organization)) {
            ProcessEquityIncomingMatchJob::dispatch((int) $payment->id);
        }

        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted',
            'payment_id' => $payment->id,
        ]);
    }

    protected function extractTransactionId(array $payload): string
    {
        foreach ([
            'TransID', 'transaction_id', 'TransactionID', 'TranId', 'payment_reference',
            'PaymentReference', 'reference', 'Reference', 'TrxCode',
        ] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    protected function extractAmount(array $payload): int
    {
        foreach (['TransAmount', 'amount', 'Amount', 'TranAmount', 'PaidAmount'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $raw = $payload[$key];
            if (is_numeric($raw)) {
                return (int) round((float) $raw);
            }
        }

        return 0;
    }

    protected function extractBillRef(array $payload): ?string
    {
        foreach ([
            'BillRefNumber', 'bill_ref_number', 'AccountReference', 'account_reference',
            'BillRef', 'CustomerRef', 'customer_ref', 'Narration',
        ] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function extractPhone(array $payload): ?string
    {
        foreach (['MSISDN', 'msisdn', 'phone', 'Phone', 'customer_phone', 'MobileNumber'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function extractPayerName(array $payload): ?string
    {
        foreach (['FirstName', 'payer_name', 'PayerName', 'CustomerName', 'customer_name', 'FullName'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
