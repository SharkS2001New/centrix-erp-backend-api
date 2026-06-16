<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingConnection;
use App\Services\Accounting\AccountingSettingsResolver;
use App\Services\Accounting\JournalExportService;
use App\Services\Accounting\QuickBooksApiClient;
use App\Services\Accounting\QuickBooksOAuthService;
use App\Services\Accounting\QuickBooksSettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExternalAccountingController extends Controller
{
    public function __construct(
        protected QuickBooksOAuthService $quickBooks,
        protected JournalExportService $exports,
        protected AccountingSettingsResolver $settings,
        protected QuickBooksApiClient $quickBooksApi,
    ) {}

    public function status(Request $request)
    {
        $orgId = (int) $request->user()->organization_id;
        $finance = app(\App\Services\Erp\ErpContext::class)
            ->gateForUser($request->user())
            ->moduleSettings('finance');
        $settings = $this->settings->fromFinanceSettings($finance);
        $provider = $settings->provider() ?? 'quickbooks';
        $connection = AccountingConnection::query()
            ->where('organization_id', $orgId)
            ->where('provider', $provider)
            ->first();

        $qbConfig = QuickBooksSettingsResolver::forOrganization($orgId);
        $quickbooksMode = (! $qbConfig['client_id'] || ! $qbConfig['client_secret']) ? 'stub' : 'live';

        return response()->json([
            'accounting_mode' => $settings->mode(),
            'accounting_provider' => $provider,
            'sync_direction' => $settings->syncDirection(),
            'quickbooks_mode' => $quickbooksMode,
            'connection' => $connection ? [
                'provider' => $connection->provider,
                'status' => $connection->status,
                'realm_id' => $connection->realm_id,
                'connected_at' => $connection->connected_at,
            ] : null,
            'pending_exports' => DB::table('accounting_export_queue')
                ->where('organization_id', $orgId)
                ->where('provider', $provider)
                ->where('status', 'pending')
                ->count(),
        ]);
    }

    public function quickBooksConnectUrl(Request $request)
    {
        $user = $request->user();
        $url = $this->quickBooks->authorizationUrl((int) $user->organization_id, (int) $user->id);

        return response()->json(['authorization_url' => $url]);
    }

    public function quickBooksCallback(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
            'realmId' => 'nullable|string',
        ]);

        $result = $this->quickBooks->handleCallback(
            $data['code'],
            $data['state'],
            $data['realmId'] ?? null,
        );

        return redirect()->away($result['redirect_url']);
    }

    public function quickBooksDisconnect(Request $request)
    {
        $this->quickBooks->disconnect((int) $request->user()->organization_id);

        return response()->json(['message' => 'QuickBooks disconnected.']);
    }

    public function listMappings(Request $request)
    {
        $orgId = (int) $request->user()->organization_id;
        $provider = $request->input('provider', 'quickbooks');

        return response()->json([
            'data' => AccountingAccountMapping::query()
                ->where('organization_id', $orgId)
                ->where('provider', $provider)
                ->orderBy('local_account_code')
                ->get(),
        ]);
    }

    public function syncMappings(Request $request)
    {
        $orgId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'provider' => 'sometimes|in:quickbooks',
            'mappings' => 'required|array',
            'mappings.*.local_account_code' => 'required|string|max:20',
            'mappings.*.external_account_id' => 'required|string|max:100',
            'mappings.*.external_account_name' => 'nullable|string|max:200',
        ]);

        $provider = $data['provider'] ?? 'quickbooks';

        foreach ($data['mappings'] as $row) {
            AccountingAccountMapping::updateOrCreate(
                [
                    'organization_id' => $orgId,
                    'provider' => $provider,
                    'local_account_code' => $row['local_account_code'],
                ],
                [
                    'external_account_id' => $row['external_account_id'],
                    'external_account_name' => $row['external_account_name'] ?? null,
                ],
            );
        }

        return $this->listMappings($request);
    }

    public function quickBooksAccounts(Request $request)
    {
        $orgId = (int) $request->user()->organization_id;
        $connection = $this->quickBooks->connectionForOrg($orgId);
        if (! $connection || $connection->status !== 'connected') {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        return response()->json([
            'data' => $this->quickBooksApi->listAccounts($connection),
        ]);
    }

    public function exportQueue(Request $request)
    {
        $status = $request->input('status');

        return response()->json([
            'data' => $this->exports->listQueue((int) $request->user()->organization_id, is_string($status) ? $status : null),
        ]);
    }

    public function processExportQueue(Request $request)
    {
        $provider = $request->input('provider', 'quickbooks');
        $result = $this->exports->processPending((int) $request->user()->organization_id, $provider);

        return response()->json($result);
    }

    public function retryFailedExports(Request $request)
    {
        $provider = $request->input('provider', 'quickbooks');
        $result = $this->exports->retryFailed((int) $request->user()->organization_id, $provider);

        return response()->json($result);
    }
}
