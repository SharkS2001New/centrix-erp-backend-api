<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\KraAgent;
use App\Services\Erp\ErpContext;
use App\Services\Kra\KraAgentBridge;
use App\Support\KraAgentToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KraAgentController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected KraAgentBridge $bridge,
    ) {}

    /** Admin: issue Sanctum token + config for zip download. */
    public function issueAgentPackage(Request $request)
    {
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);
        $finance = $gate->moduleSettings('finance');

        if (empty($finance['enable_kra_device'])) {
            return response()->json([
                'message' => 'Enable the KRA device in Finance settings before downloading the agent.',
            ], 422);
        }

        $agent = $this->bridge->resolveOrCreateForOrganization((int) $org->id, $finance);
        $user = $request->user();
        $tokenName = KraAgentToken::nameForOrganization((int) $org->id);

        $user->tokens()->where('name', $tokenName)->delete();
        $plain = $user->createToken($tokenName, ['*'], null)->plainTextToken;

        DB::table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->where('name', $tokenName)
            ->update([
                'organization_id' => $org->id,
                'expires_at' => null,
            ]);

        $apiUrl = rtrim((string) config('app.url'), '/').'/api/v1';

        return response()->json([
            'agent' => $this->bridge->agentStatus($agent),
            'config' => [
                'centrixApiUrl' => $apiUrl,
                'centrixToken' => $plain,
                'organizationId' => (int) $org->id,
                'agentId' => (int) $agent->id,
                'comstoreBaseUrl' => $agent->comstore_base_url,
                'longPollMs' => 2000,
                'heartbeatIntervalSeconds' => 60,
                'commandTimeoutSeconds' => 50,
            ],
        ]);
    }

    public function status(Request $request)
    {
        $org = $this->erp->resolveOrganization($request);
        $finance = $this->erp->gateForRequest($request)->moduleSettings('finance');
        $agent = KraAgent::query()->where('organization_id', $org->id)->first();

        if (! $agent) {
            return response()->json([
                'enabled' => (bool) ($finance['enable_kra_agent'] ?? false),
                'online' => false,
                'message' => 'KRA agent has not been registered yet. Download the agent package from Finance settings.',
            ]);
        }

        $status = $this->bridge->agentStatus($agent);

        return response()->json(array_merge($status, [
            'enabled' => (bool) ($finance['enable_kra_agent'] ?? false),
            'message' => $status['online']
                ? 'KRA agent is online.'
                : 'KRA agent is offline. Start the Windows service on the shop PC.',
        ]));
    }

    public function heartbeat(Request $request)
    {
        $agent = $this->resolveAgentForToken($request);
        $version = trim((string) $request->input('agent_version', ''));
        $this->bridge->touchAgent($agent, $version !== '' ? $version : null);

        $comstore = trim((string) $request->input('comstore_base_url', ''));
        if ($comstore !== '') {
            $agent->comstore_base_url = $this->bridge->normalizeComstoreUrl($comstore);
            $agent->save();
        }

        return response()->json([
            'ok' => true,
            'agent' => $this->bridge->agentStatus($agent->fresh()),
        ]);
    }

    public function pendingCommands(Request $request)
    {
        $agent = $this->resolveAgentForToken($request);
        $version = trim((string) $request->input('agent_version', $request->query('agent_version', '')));
        $waitMs = min(10_000, max(0, (int) $request->input('wait_ms', $request->query('wait_ms', 0))));
        $deadline = microtime(true) + ($waitMs / 1000);
        $commands = [];

        do {
            $commands = $this->bridge->pullPendingCommands(
                $agent,
                min(10, max(1, (int) $request->input('limit', 5))),
                $version !== '' ? $version : null,
            );
            if ($commands !== []) {
                break;
            }
            if ($waitMs <= 0 || microtime(true) >= $deadline) {
                break;
            }
            usleep(50_000);
        } while (true);

        return response()->json([
            'commands' => $commands,
            'comstore_base_url' => $agent->comstore_base_url,
        ]);
    }

    public function commandResult(Request $request, string $commandId)
    {
        $agent = $this->resolveAgentForToken($request);
        $data = $request->validate([
            'success' => 'required|boolean',
            'status' => 'sometimes|nullable|integer',
            'body' => 'sometimes|nullable|string',
            'headers' => 'sometimes|nullable|array',
            'error' => 'sometimes|nullable|string|max:500',
            'agent_version' => 'sometimes|nullable|string|max:40',
        ]);

        $this->bridge->submitCommandResult($agent, $commandId, $data);

        return response()->json(['ok' => true]);
    }

    protected function resolveAgentForToken(Request $request): KraAgent
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $name = $token?->name;
        if (! KraAgentToken::isAgentTokenName($name)) {
            abort(403, 'This endpoint requires a KRA agent token.');
        }

        $orgId = (int) ($token->organization_id ?? $user->organization_id ?? 0);
        if ($orgId < 1) {
            abort(403, 'KRA agent token is missing organization scope.');
        }

        $agent = KraAgent::query()->where('organization_id', $orgId)->first();
        if (! $agent) {
            abort(404, 'KRA agent is not registered for this organization.');
        }

        return $agent;
    }
}
