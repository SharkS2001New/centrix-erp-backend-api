<?php

namespace App\Http\Controllers\Api\V1\Operations\Concerns;

use App\Models\Sale;
use App\Models\StockTakeSession;
use App\Models\TillFloatSession;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

trait HandlesBranchScope
{
    protected function userAccess(): UserAccessService
    {
        return app(UserAccessService::class);
    }

    protected function findScopedSale(int $saleId, User $user, ?Request $request = null): Sale
    {
        $query = Sale::query()->where('id', $saleId);
        $this->userAccess()->scopeOrganization($query, $user, 'organization_id', $request);
        $this->userAccess()->scopeBranchIfLimited($query, $user);

        return $query->firstOrFail();
    }

    protected function findScopedTillSession(int $sessionId, User $user, ?Request $request = null): TillFloatSession
    {
        $query = TillFloatSession::query()->where('id', $sessionId);
        $this->userAccess()->scopeOrganization($query, $user, 'organization_id', $request);
        $this->userAccess()->scopeBranchIfLimited($query, $user);

        return $query->firstOrFail();
    }

    protected function findScopedStockTakeSession(int $sessionId, User $user, ?Request $request = null): StockTakeSession
    {
        $query = StockTakeSession::query()->where('id', $sessionId);
        $this->userAccess()->scopeOrganization($query, $user, 'organization_id', $request);
        $this->userAccess()->scopeBranchIfLimited($query, $user);

        return $query->firstOrFail();
    }

    /** @param  class-string<Model>  $modelClass */
    protected function findBranchScopedModel(
        string $modelClass,
        int|string $id,
        User $user,
        string $column = 'id',
        ?Request $request = null,
    ): Model {
        $query = $modelClass::query()->where($column, $id);
        $fillable = (new $modelClass)->getFillable();
        $hasOrganization = in_array('organization_id', $fillable, true);
        $hasBranch = in_array('branch_id', $fillable, true);

        if ($hasOrganization && $hasBranch) {
            $this->userAccess()->scopeOrganizationWithBranchFallback($query, $user, $request);
        } elseif ($hasOrganization) {
            $this->userAccess()->scopeOrganization($query, $user, 'organization_id', $request);
        } elseif ($hasBranch) {
            $this->userAccess()->scopeOrganizationViaBranch($query, $user, 'branch_id', $request);
        }

        if ($hasBranch) {
            $this->userAccess()->scopeBranchIfLimited($query, $user);
        }

        return $query->firstOrFail();
    }
}
