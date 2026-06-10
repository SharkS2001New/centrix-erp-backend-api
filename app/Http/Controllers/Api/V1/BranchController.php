<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class BranchController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Branch::class;
    }

    public function destroy(string $id)
    {
        $branch = Branch::findOrFail($id);
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
