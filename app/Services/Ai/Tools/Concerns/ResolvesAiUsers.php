<?php

namespace App\Services\Ai\Tools\Concerns;

use App\Models\User;
use App\Services\Ai\AiNearMissHelper;

trait ResolvesAiUsers
{
    /**
     * @return array{error?: bool, message?: string, candidates?: list<array<string, mixed>>, user?: User}
     */
    protected function resolveUserByName(int $organizationId, string $name, bool $withRelations = false): array
    {
        $needle = mb_strtolower(trim($name));
        if ($needle === '') {
            return [
                'error' => true,
                'message' => 'Provide a username or full name.',
            ];
        }

        $matches = $this->userNameQuery($organizationId, $needle, $withRelations)->get();

        if ($matches->isEmpty()) {
            $matches = $this->userRelaxedQuery($organizationId, $name, $withRelations)->get();
        }

        if ($matches->isEmpty()) {
            return AiNearMissHelper::noExact(
                $name,
                null,
                [],
                [['label' => 'Users', 'path' => '/admin/users']],
                'Open Users to verify the name or username.',
            );
        }

        if ($matches->count() === 1) {
            return ['user' => $matches->first()];
        }

        return AiNearMissHelper::ambiguous(
            $name,
            $matches->map(fn (User $row) => [
                'label' => (string) ($row->full_name ?: $row->username),
                'username' => $row->username,
                'role' => $row->role?->role_name,
            ])->all(),
            'user',
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    protected function userNameQuery(int $organizationId, string $needle, bool $withRelations)
    {
        $query = User::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at');

        \App\Support\CentrixAgentServiceUser::excludeFromQuery($query);

        if ($withRelations) {
            $query->with([
                'role:id,role_name',
                'branch:id,branch_name',
                'assignedRoutes:id,organization_id,branch_id,route_name,direction,is_active',
            ]);
        } else {
            $query->with(['role:id,role_name']);
        }

        return $query
            ->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(username) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(COALESCE(full_name, \'\')) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', ['%'.$needle.'%']);
            })
            ->orderBy('full_name')
            ->limit(10);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    protected function userRelaxedQuery(int $organizationId, string $name, bool $withRelations)
    {
        $tokens = AiNearMissHelper::tokens($name);
        if ($tokens === []) {
            return User::query()->whereRaw('1 = 0');
        }

        $query = User::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at');

        \App\Support\CentrixAgentServiceUser::excludeFromQuery($query);

        if ($withRelations) {
            $query->with([
                'role:id,role_name',
                'branch:id,branch_name',
                'assignedRoutes:id,organization_id,branch_id,route_name,direction,is_active',
            ]);
        } else {
            $query->with(['role:id,role_name']);
        }

        return $query
            ->where(function ($q) use ($tokens) {
                foreach ($tokens as $token) {
                    $like = '%'.mb_strtolower($token).'%';
                    $q->orWhereRaw('LOWER(username) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(full_name, \'\')) LIKE ?', [$like]);
                }
            })
            ->orderBy('full_name')
            ->limit(10);
    }
}
