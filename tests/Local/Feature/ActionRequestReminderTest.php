<?php


/**
 * Local-only tests — excluded from CI / default `composer test`.
 * Run manually: composer test:local
 */

namespace Tests\Local\Feature;

use App\Models\ActionRequest;
use App\Models\InAppNotification;
use App\Models\User;
use App\Services\Notifications\ActionRequestService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ActionRequestReminderTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_requester_can_send_approval_reminder(): void
    {
        $requester = User::where('username', 'admin')->firstOrFail();
        $approver = User::query()
            ->where('organization_id', $requester->organization_id)
            ->where('id', '!=', $requester->id)
            ->where('is_active', true)
            ->firstOrFail();

        $actionRequest = app(ActionRequestService::class)->requestApproval($requester, [
            'type' => 'stock_adjustment',
            'module' => 'inventory',
            'reference_type' => 'stock_adjustment_request',
            'reference_id' => 0,
            'approver_permission' => 'inventory.manage',
            'title' => 'Stock adjustment approval required',
            'message' => 'Clerk requested +2 adjustment.',
            'reason' => 'Cycle count variance',
            'severity' => 'warning',
            'action_url' => '/inventory/adjustments',
            'allow_duplicate_reference' => true,
            'payload' => [
                'message' => 'Clerk requested +2 adjustment.',
                'action_url' => '/inventory/adjustments',
            ],
        ]);

        InAppNotification::query()
            ->where('action_request_id', $actionRequest->id)
            ->update(['is_read' => true]);

        Sanctum::actingAs($requester);

        $this->postJson("/api/v1/action-requests/{$actionRequest->id}/remind")
            ->assertOk()
            ->assertJsonPath('message', 'Approval reminder sent.');

        $this->assertDatabaseHas('approval_actions', [
            'action_request_id' => $actionRequest->id,
            'user_id' => $requester->id,
            'action' => 'reminded',
        ]);

        $this->assertDatabaseHas('in_app_notifications', [
            'action_request_id' => $actionRequest->id,
            'user_id' => $approver->id,
            'title' => 'Approval reminder',
        ]);
    }

    public function test_non_requester_cannot_send_reminder(): void
    {
        $requester = User::where('username', 'admin')->firstOrFail();
        $other = User::query()
            ->where('organization_id', $requester->organization_id)
            ->where('id', '!=', $requester->id)
            ->where('is_active', true)
            ->firstOrFail();

        $actionRequest = ActionRequest::query()->create([
            'organization_id' => $requester->organization_id,
            'type' => 'journal_entry',
            'module' => 'accounting',
            'reference_type' => 'journal_entry',
            'reference_id' => 1,
            'requested_by' => $requester->id,
            'approver_permission' => 'accounting.journal_entries.approve',
            'status' => 'pending',
            'title' => 'Journal entry posting approval',
            'reason' => 'Test',
            'payload' => ['action_url' => '/accounting/journal-entries/1'],
        ]);

        Sanctum::actingAs($other);

        $this->postJson("/api/v1/action-requests/{$actionRequest->id}/remind")
            ->assertStatus(422);
    }
}
