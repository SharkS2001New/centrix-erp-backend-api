<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserMembership;

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
            $target->delete();

            return [
                'mode' => 'archived',
                'message' => 'User archived (soft deleted). Sales and activity history are retained.',
            ];
        }

        UserMembership::query()->where('user_id', $target->id)->delete();
        $target->forceDelete();

        return [
            'mode' => 'deleted',
            'message' => 'User permanently deleted.',
        ];
    }
}
