<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\TillFloatSession;
use App\Services\Erp\TillSessionAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TillFloatSessionController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return TillFloatSession::class;
    }

    protected function scopesByBranch(): bool
    {
        return true;
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request);
        $user = $request->user();

        if ($user && ! $user->is_admin && ! TillSessionAuthorization::canManageSessions($user)) {
            $query->where('cashier_id', $user->id);
        }

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($q = $request->input('q')) {
            $searchCol = $this->routeKeyColumn() !== 'id'
                ? $this->routeKeyColumn()
                : ($this->fillableFields()[0] ?? 'id');
            $query->where($searchCol, 'like', "%{$q}%");
        }

        $perPage = min((int) $request->input('per_page', 25), 200);
        $this->applyListOrdering($request, $query, 'id', 'desc');

        return response()->json($query->paginate($perPage));
    }

    public function show(Request $request, string $id)
    {
        $session = $this->findScopedModel($request, $id);
        TillSessionAuthorization::assertCanView($request->user(), $session);

        return response()->json($session);
    }

    /** @param  mixed  $breakdown */
    protected function normalizeFloatEntries($breakdown): array
    {
        if (! is_array($breakdown) || $breakdown === []) {
            return [];
        }

        if (array_is_list($breakdown)) {
            return array_values(array_filter(array_map(function ($entry) {
                if (! is_array($entry)) {
                    return null;
                }

                return [
                    'new_float' => (float) ($entry['new_float'] ?? 0),
                    'payment_type' => strtoupper((string) ($entry['payment_type'] ?? 'CASH')),
                    'date_added' => $entry['date_added'] ?? now()->format('Y-m-d\TH:i:s.v'),
                ];
            }, $breakdown)));
        }

        $entries = [];
        foreach ($breakdown as $type => $amount) {
            if (is_numeric($amount)) {
                $entries[] = [
                    'new_float' => (float) $amount,
                    'payment_type' => strtoupper((string) $type),
                    'date_added' => now()->format('Y-m-d\TH:i:s.v'),
                ];
            }
        }

        return $entries;
    }

    protected function sumFloatEntries(array $entries): float
    {
        return array_sum(array_map(
            fn (array $entry) => (float) ($entry['new_float'] ?? 0),
            $entries,
        ));
    }

    public function store(Request $request)
    {
        return response()->json([
            'message' => 'Use POST /pos/sessions/open to start a cashier session.',
        ], 422);
    }

    public function update(\Illuminate\Http\Request $request, string $id)
    {
        $session = $this->findScopedModel($request, $id);
        $data = $request->validate([
            'notes' => 'nullable|string',
            'float_breakdown' => 'nullable|array',
            'working_amount' => 'nullable|numeric|min:0',
        ]);

        if (array_key_exists('float_breakdown', $data) || array_key_exists('working_amount', $data)) {
            if (! TillSessionAuthorization::canCorrectFloat($request->user())) {
                throw new AccessDeniedHttpException('Only managers can correct session float.');
            }
        }

        if (array_key_exists('float_breakdown', $data)) {
            $entries = $this->normalizeFloatEntries($data['float_breakdown']);
            $data['float_breakdown'] = $entries;
            $data['working_amount'] = (int) round($this->sumFloatEntries($entries));
        } elseif (array_key_exists('working_amount', $data)) {
            $amount = (float) $data['working_amount'];
            $entries = $this->normalizeFloatEntries($session->float_breakdown);
            if ($entries === []) {
                $entries = [[
                    'new_float' => $amount,
                    'payment_type' => 'CASH',
                    'date_added' => now()->format('Y-m-d\TH:i:s.v'),
                ]];
            } else {
                $entries[0]['new_float'] = $amount;
            }
            $data['float_breakdown'] = $entries;
            $data['working_amount'] = (int) round($this->sumFloatEntries($entries));
        }

        $session->update($data);

        return response()->json($session->fresh());
    }

    public function destroy(Request $request, string $id)
    {
        $session = $this->findScopedModel($request, $id);

        $hasSales = DB::table('sales')->where('float_session_id', $session->id)->exists();
        if ($hasSales) {
            throw new InvalidArgumentException(
                'This session has linked sales and cannot be deleted. Close it and keep it for history.',
            );
        }

        if ($session->status === 'open') {
            $session->update([
                'status' => 'closed',
                'closed_at' => now(),
                'notes' => trim(($session->notes ?? '').' · Deleted while open'),
            ]);
        }

        $session->delete();

        return response()->json(null, 204);
    }
}
