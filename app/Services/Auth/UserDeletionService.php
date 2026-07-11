<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserMembership;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class UserDeletionService
{
    public function __construct(
        protected UserActivityChecker $activity,
        protected UserLoginService $loginService,
    ) {}

    /** @return array{mode: string, message: string} */
    public function delete(User $target, User $actor): array
    {
        app(UserAccountGuard::class)->assertCanDelete($target, $actor);

        $target->forceFill(['deleted_by' => $actor->id])->save();
        $this->loginService->disableLogin($target);

        if ($this->activity->hasRetainedActivity((int) $target->id)) {
            return $this->archive($target);
        }

        try {
            UserMembership::query()->where('user_id', $target->id)->delete();
            $target->tokens()->delete();
            $target->forceDelete();
        } catch (QueryException $e) {
            // Any remaining FK (or race) — keep the account as soft-deleted instead of erroring.
            if ($this->isForeignKeyConstraintFailure($e)) {
                Log::info('User hard delete blocked by FK; soft-deleting instead', [
                    'user_id' => $target->id,
                    'error' => $e->getMessage(),
                ]);

                return $this->archive($target->fresh() ?? $target);
            }

            throw $e;
        }

        return [
            'mode' => 'deleted',
            'message' => 'User permanently deleted.',
        ];
    }

    /** @return array{mode: string, message: string} */
    protected function archive(User $target): array
    {
        if (! $target->trashed()) {
            $target->delete();
        }

        return [
            'mode' => 'archived',
            'message' => 'User archived (soft deleted). Related records are retained so the account cannot be permanently removed.',
        ];
    }

    protected function isForeignKeyConstraintFailure(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $message = $e->getMessage();

        return $sqlState === '23000'
            || $driverCode === 1451
            || str_contains($message, 'Integrity constraint violation')
            || str_contains($message, 'foreign key constraint fails');
    }
}
