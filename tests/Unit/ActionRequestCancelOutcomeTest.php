<?php

namespace Tests\Unit;

use App\Models\InAppNotification;
use App\Models\User;
use App\Services\Notifications\ActionRequestService;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ActionRequestCancelOutcomeTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_cancel_pending_request_notifies_requester_of_withdrawal(): void
    {
        $requester = User::where('username', 'cashier')->firstOrFail();
        $actor = User::where('username', 'admin')->firstOrFail();

        $request = app(ActionRequestService::class)->requestApproval($requester, [
            'type' => 'discount',
            'module' => 'sales',
            'reference_type' => 'temporary_cart',
            'reference_id' => 999001,
            'approver_permission' => 'sales.discounts.approve',
            'title' => 'Discount approval required',
            'message' => 'Cashier requested a discount.',
            'severity' => 'warning',
            'action_url' => '/pos',
            'allow_duplicate_reference' => true,
            'payload' => [
                'order_num' => 42,
                'action_url' => '/pos',
            ],
        ]);

        $this->assertSame('pending', $request->status);

        app(ActionRequestService::class)->cancelAllPendingForDomainReference(
            $actor,
            'temporary_cart',
            999001,
            'Cart cleared.',
        );

        $request->refresh();
        $this->assertSame('cancelled', $request->status);

        $outcome = InAppNotification::query()
            ->where('user_id', $requester->id)
            ->where('type', 'approval_outcome')
            ->where('action_request_id', $request->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($outcome);
        $this->assertSame('Discount withdrawn', $outcome->title);
        $this->assertStringContainsString('withdrawn', (string) $outcome->message);
    }
}
