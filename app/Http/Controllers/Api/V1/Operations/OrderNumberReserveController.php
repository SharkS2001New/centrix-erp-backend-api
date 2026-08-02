<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Sales\OrderNumberAllocator;
use App\Services\Sales\PosDailyOrderNumberAllocator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class OrderNumberReserveController extends Controller
{
    /**
     * Reserve a block of sequential order numbers for External POS offline selling.
     */
    public function reserve(
        Request $request,
        OrderNumberAllocator $allocator,
        PosDailyOrderNumberAllocator $posAllocator,
    ) {
        $user = $request->user();
        $orgId = (int) ($user?->organization_id ?? 0);
        if ($orgId <= 0) {
            throw ValidationException::withMessages([
                'organization' => 'Your user account has no organization.',
            ]);
        }

        $data = $request->validate([
            'count' => 'nullable|integer|min:1|max:'.OrderNumberAllocator::MAX_RESERVE_BLOCK,
        ]);
        $count = (int) ($data['count'] ?? 20);

        try {
            $block = $allocator->reserveBlockForOrganization($orgId, $count);
            $posBlock = $posAllocator->reserveBlockForCashier($orgId, (int) $user->id, count($block['numbers']));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'organization' => $e->getMessage(),
            ]);
        }

        $slots = [];
        foreach ($block['numbers'] as $i => $orderNum) {
            $ticket = $posBlock['tickets'][$i] ?? null;
            $slots[] = [
                'order_num' => $orderNum,
                'pos_order_num' => $ticket['pos_order_num'] ?? null,
                'pos_order_date' => $ticket['pos_order_date'] ?? null,
            ];
        }

        return response()->json([
            'organization_id' => $orgId,
            'start' => $block['start'],
            'end' => $block['end'],
            'numbers' => $block['numbers'],
            'count' => count($block['numbers']),
            'pos_order_date' => $posBlock['pos_order_date'] ?? null,
            'pos_tickets' => $posBlock['tickets'] ?? [],
            'slots' => $slots,
        ]);
    }
}
