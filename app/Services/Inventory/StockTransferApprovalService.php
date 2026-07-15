<?php

namespace App\Services\Inventory;

use App\Models\ActionRequest;
use App\Models\Product;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\CapabilityGate;
use App\Services\Notifications\ActionRequestService;
use Illuminate\Validation\ValidationException;

class StockTransferApprovalService
{
    public function __construct(
        protected UserPermissionService $permissions,
        protected UserAccessService $access,
        protected StockTransferService $transfers,
    ) {}

    public static function isRoutineStoreToShopTransfer(string $from, string $to): bool
    {
        return $from === 'store' && $to === 'shop';
    }

    public function approvalEnabled(CapabilityGate $gate): bool
    {
        $settings = $gate->moduleSettings('inventory');

        return ! empty($settings['stock_transfer_approval_enabled']);
    }

    public function canDirectTransfer(User $user): bool
    {
        return $this->permissions->canDirectManageInventory($user);
    }

    public function canApprove(User $user): bool
    {
        return $this->permissions->canApproveInventoryOperations($user);
    }

    public function requiresApproval(CapabilityGate $gate, User $user, string $from, string $to): bool
    {
        if (self::isRoutineStoreToShopTransfer($from, $to)) {
            return false;
        }

        return $this->approvalEnabled($gate) && ! $this->canDirectTransfer($user);
    }

    /** @param  array<string, mixed>  $data */
    public function requestTransfer(User $user, array $data, CapabilityGate $gate): ActionRequest
    {
        if (! $this->approvalEnabled($gate)) {
            throw ValidationException::withMessages([
                'authorization' => 'Stock transfer approval is not enabled.',
            ]);
        }

        if ($this->canDirectTransfer($user)) {
            throw ValidationException::withMessages([
                'authorization' => 'You can post stock transfers directly.',
            ]);
        }

        if (self::isRoutineStoreToShopTransfer($data['from_location'], $data['to_location'])) {
            throw ValidationException::withMessages([
                'authorization' => 'Store to shop transfers do not require approval.',
            ]);
        }

        $this->access->assertBranchAccess($user, (int) $data['branch_id']);

        $product = Product::query()->where('product_code', $data['product_code'])->first();
        $productName = $product?->product_name ?? $data['product_code'];
        $requesterName = $user->full_name ?: $user->username;
        $qty = rtrim(rtrim(number_format((float) $data['quantity'], 4, '.', ''), '0'), '.');
        $to = (string) $data['to_location'];
        $toLabel = StockTransferService::isPurposeDestination($to)
            ? StockTransferService::purposeLabel($to)
            : ucfirst($to);
        $route = ucfirst((string) $data['from_location']).' → '.$toLabel;

        return app(ActionRequestService::class)->requestApproval($user, [
            'type' => 'stock_transfer',
            'module' => 'inventory',
            'reference_type' => 'stock_transfer_request',
            'reference_id' => 0,
            'approver_permission' => 'inventory.manage',
            'title' => 'Stock transfer approval required',
            'message' => "{$requesterName} requested {$qty} of {$productName} ({$route}).",
            'reason' => $data['notes'] ?? null,
            'severity' => 'warning',
            'action_url' => '/inventory/transfers',
            'allow_duplicate_reference' => true,
            'payload' => [
                'branch_id' => (int) $data['branch_id'],
                'product_code' => (string) $data['product_code'],
                'product_name' => $productName,
                'quantity' => (float) $data['quantity'],
                'from_location' => (string) $data['from_location'],
                'to_location' => (string) $data['to_location'],
                'notes' => $data['notes'] ?? null,
                'action_url' => '/inventory/transfers',
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public function applyFromActionRequest(ActionRequest $request, User $approver): array
    {
        $payload = $request->payload ?? [];
        $this->access->assertBranchAccess($approver, (int) ($payload['branch_id'] ?? 0));

        return $this->transfers->transfer(
            (int) $payload['branch_id'],
            (string) $payload['product_code'],
            (float) $payload['quantity'],
            (string) $payload['from_location'],
            (string) $payload['to_location'],
            User::query()->findOrFail((int) $request->requested_by),
            $payload['notes'] ?? $request->reason,
        );
    }
}
