<?php

namespace App\Http\Controllers\Api\V1\Operations\Concerns;

use App\Models\Sale;
use App\Models\StockTakeSession;
use App\Models\TillFloatSession;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use Illuminate\Database\Eloquent\Model;

trait HandlesBranchScope
{
    protected function userAccess(): UserAccessService
    {
        return app(UserAccessService::class);
    }

    protected function findScopedSale(int $saleId, User $user): Sale
    {
        $query = Sale::query()->where('id', $saleId);
        $this->userAccess()->scopeOrganization($query, $user);
        $this->userAccess()->scopeBranchIfLimited($query, $user);

        return $query->firstOrFail();
    }

    protected function findScopedTillSession(int $sessionId, User $user): TillFloatSession
    {
        $query = TillFloatSession::query()->where('id', $sessionId);
        $this->userAccess()->scopeBranchIfLimited($query, $user);

        return $query->firstOrFail();
    }

    protected function findScopedStockTakeSession(int $sessionId, User $user): StockTakeSession
    {
        $query = StockTakeSession::query()->where('id', $sessionId);
        $this->userAccess()->scopeBranchIfLimited($query, $user);

        return $query->firstOrFail();
    }

    /** @param  class-string<Model>  $modelClass */
    protected function findBranchScopedModel(string $modelClass, int|string $id, User $user, string $column = 'id'): Model
    {
        $query = $modelClass::query()->where($column, $id);
        if (in_array('organization_id', (new $modelClass)->getFillable(), true)) {
            $this->userAccess()->scopeOrganization($query, $user);
        }
        if (in_array('branch_id', (new $modelClass)->getFillable(), true)) {
            $this->userAccess()->scopeBranchIfLimited($query, $user);
        }

        return $query->firstOrFail();
    }
}
