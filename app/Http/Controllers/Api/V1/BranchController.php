<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BranchController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Branch::class;
    }

    public function destroy(Request $request, string $id)
    {
        $branch = $this->findScopedModel($request, $id);
        $usersCount = User::query()->where('branch_id', $branch->id)->count();

        if ($usersCount > 0) {
            throw ValidationException::withMessages([
                'branch' => "Cannot delete this branch — {$usersCount} user(s) are still assigned. Reassign them first.",
            ]);
        }

        $branch->delete();

        return response()->json(null, 204);
    }
}
