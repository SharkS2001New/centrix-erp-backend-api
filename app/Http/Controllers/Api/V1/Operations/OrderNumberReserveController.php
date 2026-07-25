<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Sales\OrderNumberAllocator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class OrderNumberReserveController extends Controller
{
    /**
     * Reserve a block of sequential order numbers for External POS offline selling.
     */
    public function reserve(Request $request, OrderNumberAllocator $allocator)
    {
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
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'organization' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'organization_id' => $orgId,
            'start' => $block['start'],
            'end' => $block['end'],
            'numbers' => $block['numbers'],
            'count' => count($block['numbers']),
        ]);
    }
}
