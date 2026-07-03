<?php

namespace App\Services\Inventory;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\ActionRequest;
use App\Models\Damage;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use App\Services\Auth\UserPermissionService;
use App\Services\Notifications\ActionRequestService;
use App\Services\Notifications\NotificationActionUrlBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DamageApprovalService
{
    use HandlesInventory;

    public function __construct(
        protected UserPermissionService $permissions,
        protected UserAccessService $access,
    ) {}

    public function canApprove(User $user): bool
    {
        return (bool) $user->is_admin
            || $this->permissions->hasPermission($user, 'inventory.manage');
    }

    /** @param  array<string, mixed>  $data */
    public function requestCreate(User $requester, array $data): ActionRequest
    {
        $this->access->assertBranchAccess($requester, (int) $data['branch_id']);
        $qty = rtrim(rtrim(number_format((float) $data['quantity'], 4, '.', ''), '0'), '.');
        $requesterName = $requester->full_name ?: $requester->username;
        $actionUrl = NotificationActionUrlBuilder::for('damage', 0);

        return app(ActionRequestService::class)->requestApproval($requester, [
            'type' => 'damage_write_off',
            'module' => 'inventory',
            'reference_type' => 'damage',
            'reference_id' => 0,
            'approver_permission' => 'inventory.manage',
            'title' => 'Damage write-off approval required',
            'message' => "{$requesterName} requested write-off of {$qty} {$data['product_code']}.",
            'reason' => $data['reason'] ?? null,
            'severity' => 'danger',
            'action_url' => $actionUrl,
            'allow_duplicate_reference' => true,
            'payload' => [
                'action' => 'create',
                'data' => $data,
                'action_url' => $actionUrl,
            ],
        ]);
    }

    public function approve(ActionRequest $request, User $approver): Damage
    {
        $payload = $request->payload ?? [];
        $data = $payload['data'] ?? [];
        if (! is_array($data)) {
            throw new InvalidArgumentException('Damage approval payload is invalid.');
        }

        $requester = User::query()->findOrFail((int) $request->requested_by);
        $this->access->assertBranchAccess($requester, (int) $data['branch_id']);
        $allowBelowStock = $this->organizationAllowsBelowStock($requester->organization_id);

        return DB::transaction(function () use ($data, $requester, $allowBelowStock) {
            $damage = Damage::create([
                ...$data,
                'reported_by' => $requester->id,
            ]);

            $this->postStockLedger([
                'branch_id' => (int) $damage->branch_id,
                'product_code' => (string) $damage->product_code,
                'stock_location' => (string) $damage->stock_location,
                'transaction_type' => 'DAMAGE',
                'reference_type' => 'damage',
                'reference_id' => $damage->id,
                'quantity_change' => -abs((float) $damage->quantity),
                'notes' => $damage->reason ?: 'Stock damage / write-off',
                'created_by' => $requester->id,
            ], $allowBelowStock);

            return $damage->fresh();
        });
    }
}
