<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WhatsappConversation;
use App\Models\WhatsappHandoff;
use App\Models\WhatsappMessageLog;
use App\Services\Erp\ErpContext;
use App\Services\WhatsApp\WhatsAppHandoffService;
use Illuminate\Http\Request;

class WhatsappAdminController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected WhatsAppHandoffService $handoffs,
    ) {}

    public function conversations(Request $request)
    {
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);
        if (! $gate->whatsappPlatformEnabled()) {
            abort(404);
        }

        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
        $query = WhatsappConversation::query()
            ->where('organization_id', $org->id)
            ->with(['customer:customer_num,customer_name,phone_number'])
            ->orderByDesc('last_message_at');

        if ($state = trim((string) $request->input('state', ''))) {
            $query->where('state', $state);
        }

        if ($search = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('phone', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('customer_name', 'like', "%{$search}%"));
            });
        }

        return response()->json(
            $query->paginate($perPage)->through(fn (WhatsappConversation $row) => $this->presentConversation($row)),
        );
    }

    public function showConversation(Request $request, int $conversation)
    {
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);
        if (! $gate->whatsappPlatformEnabled()) {
            abort(404);
        }

        $row = WhatsappConversation::query()
            ->where('organization_id', $org->id)
            ->with(['customer', 'messageLogs' => fn ($q) => $q->orderBy('created_at')])
            ->findOrFail($conversation);

        return response()->json([
            'conversation' => $this->presentConversation($row),
            'messages' => $row->messageLogs,
        ]);
    }

    public function handoffs(Request $request)
    {
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);
        if (! $gate->whatsappPlatformEnabled()) {
            abort(404);
        }

        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
        $query = WhatsappHandoff::query()
            ->where('organization_id', $org->id)
            ->with(['customer:customer_num,customer_name', 'conversation'])
            ->orderByDesc('created_at');

        if ($status = trim((string) $request->input('status', ''))) {
            $query->where('status', $status);
        }

        return response()->json(
            $query->paginate($perPage)->through(fn (WhatsappHandoff $row) => $this->presentHandoff($row)),
        );
    }

    public function resolveHandoff(Request $request, int $handoff)
    {
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);
        if (! $gate->whatsappPlatformEnabled()) {
            abort(404);
        }

        $row = WhatsappHandoff::query()
            ->where('organization_id', $org->id)
            ->findOrFail($handoff);

        return response()->json([
            'handoff' => $this->handoffs->resolve($row, $request->user()),
        ]);
    }

    public function failures(Request $request)
    {
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);
        if (! $gate->whatsappPlatformEnabled()) {
            abort(404);
        }

        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);

        return response()->json(
            WhatsappMessageLog::query()
                ->where('organization_id', $org->id)
                ->where('direction', 'system')
                ->where('meta->event', 'order_failed')
                ->with(['conversation.customer:customer_num,customer_name'])
                ->orderByDesc('created_at')
                ->paginate($perPage)
                ->through(fn (WhatsappMessageLog $row) => $this->presentFailure($row)),
        );
    }

    protected function presentConversation(WhatsappConversation $row): WhatsappConversation
    {
        $payload = is_array($row->payload) ? $row->payload : [];
        $row->setAttribute('last_sale_id', isset($payload['last_sale_id']) ? (int) $payload['last_sale_id'] : null);
        $row->setAttribute('last_order_num', isset($payload['last_order_num']) ? (int) $payload['last_order_num'] : null);

        return $row;
    }

    protected function presentHandoff(WhatsappHandoff $row): WhatsappHandoff
    {
        $row->setAttribute('conversation_id', $row->conversation_id ? (int) $row->conversation_id : null);

        return $row;
    }

    protected function presentFailure(WhatsappMessageLog $row): WhatsappMessageLog
    {
        $row->setAttribute('conversation_id', $row->conversation_id ? (int) $row->conversation_id : null);

        return $row;
    }
}
