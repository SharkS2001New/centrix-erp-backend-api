<?php

namespace App\Services\Platform;

use App\Jobs\PublishPlatformWhatsNewJob;
use App\Models\Organization;
use App\Models\PlatformWhatsNewNote;
use App\Models\PlatformWhatsNewRead;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\WorkspaceResolver;
use App\Services\Notifications\InAppNotificationService;
use App\Support\CentrixVersion;
use Illuminate\Validation\ValidationException;

class PlatformWhatsNewService
{
    public function __construct(
        protected InAppNotificationService $notifications,
        protected WorkspaceResolver $workspaces,
    ) {}

    /** @return list<string> */
    public function allowedWorkspaceIds(): array
    {
        return array_keys(config('erp_workspaces', []));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): PlatformWhatsNewNote
    {
        return PlatformWhatsNewNote::query()->create([
            'title' => (string) $data['title'],
            'body' => (string) $data['body'],
            'link_url' => $this->nullableUrl($data['link_url'] ?? null),
            'audience' => (string) ($data['audience'] ?? PlatformWhatsNewNote::AUDIENCE_ALL_USERS),
            'organization_ids' => $this->normalizeOrgIds($data['organization_ids'] ?? null),
            'workspace_ids' => $this->normalizeWorkspaceIds($data['workspace_ids'] ?? []),
            'status' => PlatformWhatsNewNote::STATUS_DRAFT,
            'show_on_login' => (bool) ($data['show_on_login'] ?? true),
            'created_by' => $actor->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PlatformWhatsNewNote $note, array $data): PlatformWhatsNewNote
    {
        if ($note->isPublished()) {
            // Published notes: allow only soft content tweaks (title/body/link/show_on_login).
            $note->fill([
                'title' => array_key_exists('title', $data) ? (string) $data['title'] : $note->title,
                'body' => array_key_exists('body', $data) ? (string) $data['body'] : $note->body,
                'link_url' => array_key_exists('link_url', $data)
                    ? $this->nullableUrl($data['link_url'])
                    : $note->link_url,
                'show_on_login' => array_key_exists('show_on_login', $data)
                    ? (bool) $data['show_on_login']
                    : $note->show_on_login,
            ]);
            $note->save();

            return $note->fresh();
        }

        $note->fill([
            'title' => array_key_exists('title', $data) ? (string) $data['title'] : $note->title,
            'body' => array_key_exists('body', $data) ? (string) $data['body'] : $note->body,
            'link_url' => array_key_exists('link_url', $data)
                ? $this->nullableUrl($data['link_url'])
                : $note->link_url,
            'audience' => array_key_exists('audience', $data)
                ? (string) $data['audience']
                : $note->audience,
            'organization_ids' => array_key_exists('organization_ids', $data)
                ? $this->normalizeOrgIds($data['organization_ids'])
                : $note->organization_ids,
            'workspace_ids' => array_key_exists('workspace_ids', $data)
                ? $this->normalizeWorkspaceIds($data['workspace_ids'])
                : $note->workspace_ids,
            'show_on_login' => array_key_exists('show_on_login', $data)
                ? (bool) $data['show_on_login']
                : $note->show_on_login,
        ]);
        $note->save();

        return $note->fresh();
    }

    public function publish(PlatformWhatsNewNote $note, User $actor): PlatformWhatsNewNote
    {
        if ($note->isPublished()) {
            throw ValidationException::withMessages([
                'status' => ['This note is already published.'],
            ]);
        }

        if ($note->targetedWorkspaceIds() === []) {
            throw ValidationException::withMessages([
                'workspace_ids' => ['Select at least one module / workspace.'],
            ]);
        }

        $note->forceFill([
            'status' => PlatformWhatsNewNote::STATUS_PUBLISHED,
            'release_version' => CentrixVersion::current(),
            'published_at' => now(),
            'published_by' => $actor->id,
        ])->save();

        PublishPlatformWhatsNewJob::dispatch($note->id);

        return $note->fresh();
    }

    public function delete(PlatformWhatsNewNote $note): void
    {
        PlatformWhatsNewRead::query()->where('note_id', $note->id)->delete();
        $note->delete();
    }

    /**
     * Make a published note show on login again for users who already dismissed it.
     *
     * @return array{note: PlatformWhatsNewNote, cleared_reads: int, renotify: bool, message: string}
     */
    public function reshow(PlatformWhatsNewNote $note, User $actor, bool $renotify = true): array
    {
        if (! $note->isPublished()) {
            throw ValidationException::withMessages([
                'status' => ['Only published notes can be shown again. Publish the draft first.'],
            ]);
        }

        if (! $note->show_on_login) {
            $note->forceFill(['show_on_login' => true])->save();
        }

        $cleared = PlatformWhatsNewRead::query()->where('note_id', $note->id)->delete();

        $note->forceFill([
            'published_at' => now(),
            'published_by' => $actor->id,
            'release_version' => CentrixVersion::current(),
        ])->save();

        if ($renotify) {
            PublishPlatformWhatsNewJob::dispatch($note->id);
        }

        $message = $cleared > 0
            ? "Cleared {$cleared} dismissal(s). Matching users will see this note again on next login."
            : 'No prior dismissals found. Matching users will see this note on next login if they have not dismissed it.';
        if ($renotify) {
            $message .= ' Bell notifications are being re-sent.';
        }

        return [
            'note' => $note->fresh(),
            'cleared_reads' => (int) $cleared,
            'renotify' => $renotify,
            'message' => $message,
        ];
    }

    /**
     * Fan-out in-app notifications to matching users. Called from the publish job.
     */
    public function fanOutNotifications(PlatformWhatsNewNote $note): int
    {
        if (! $note->isPublished()) {
            return 0;
        }

        $orgIds = $this->resolveTargetOrganizationIds($note);
        if ($orgIds === []) {
            return 0;
        }

        $targetWorkspaces = $note->targetedWorkspaceIds();
        $count = 0;

        Organization::query()
            ->whereIn('id', $orgIds)
            ->orderBy('id')
            ->each(function (Organization $org) use ($note, $targetWorkspaces, &$count) {
                $gate = app(CapabilityGate::class)->forOrganization($org);
                $users = User::query()
                    ->where('organization_id', $org->id)
                    ->where('is_active', true)
                    ->where('is_super_admin', false)
                    ->when($note->isAdminsOnly(), fn ($q) => $q->where('is_admin', true))
                    ->get();

                foreach ($users as $user) {
                    $matched = $this->matchingWorkspacesForUser($user, $gate, $targetWorkspaces);
                    if ($matched === []) {
                        continue;
                    }

                    $homePath = (string) ($matched[0]['home_path'] ?? '/dashboard');
                    $actionUrl = $homePath.(str_contains($homePath, '?') ? '&' : '?').'whats_new='.$note->id;

                    $this->notifications->createForUser($user, [
                        'organization_id' => (int) $org->id,
                        'type' => 'whats_new',
                        'severity' => 'info',
                        'title' => 'New in Centrix: '.$note->title,
                        'message' => $this->notificationPreview($note->body),
                        'action_url' => $actionUrl,
                        'created_by' => $note->published_by ?? $note->created_by,
                    ]);
                    $count++;
                }
            });

        $note->forceFill(['notified_count' => $count])->save();

        return $count;
    }

    /**
     * Pending login-modal notes for the signed-in user in the given workspace.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingForUser(User $user, ?string $workspaceId = null): array
    {
        if ($user->is_super_admin) {
            return [];
        }

        $workspaceId = $workspaceId ? strtolower(trim($workspaceId)) : null;
        $orgId = (int) $user->organization_id;

        $dismissedIds = PlatformWhatsNewRead::query()
            ->where('user_id', $user->id)
            ->pluck('note_id')
            ->all();

        $notes = PlatformWhatsNewNote::query()
            ->where('status', PlatformWhatsNewNote::STATUS_PUBLISHED)
            ->where('show_on_login', true)
            ->when($dismissedIds !== [], fn ($q) => $q->whereNotIn('id', $dismissedIds))
            ->orderByDesc('published_at')
            ->limit(10)
            ->get();

        $organization = Organization::query()->find($orgId) ?? $user->organization;
        if (! $organization) {
            return [];
        }

        $gate = app(CapabilityGate::class)->forOrganization($organization);

        $availableIds = collect($this->workspaces->availableForUser($user, $gate))
            ->pluck('id')
            ->map(fn ($id) => strtolower((string) $id))
            ->all();

        $pending = [];
        foreach ($notes as $note) {
            if (! $this->noteMatchesOrganization($note, $orgId)) {
                continue;
            }
            if ($note->isAdminsOnly() && ! $user->is_admin) {
                continue;
            }

            $targets = $note->targetedWorkspaceIds();
            $overlap = array_values(array_intersect($targets, $availableIds));
            if ($overlap === []) {
                continue;
            }

            if ($workspaceId !== null && $workspaceId !== '' && ! in_array($workspaceId, $overlap, true)) {
                continue;
            }

            $pending[] = $this->presentForUser($note);
        }

        return $pending;
    }

    public function dismissForUser(PlatformWhatsNewNote $note, User $user): void
    {
        if (! $note->isPublished()) {
            return;
        }

        PlatformWhatsNewRead::query()->updateOrCreate(
            [
                'note_id' => $note->id,
                'user_id' => $user->id,
            ],
            [
                'organization_id' => (int) $user->organization_id,
                'dismissed_at' => now(),
            ],
        );
    }

    /**
     * Users who dismissed (saw and closed) the login popup for this note.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int}}
     */
    public function listReads(PlatformWhatsNewNote $note): array
    {
        $reads = PlatformWhatsNewRead::query()
            ->where('note_id', $note->id)
            ->with([
                'user:id,full_name,username,email,organization_id',
                'organization:id,org_name,company_code',
            ])
            ->orderByDesc('dismissed_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        $data = $reads->map(function (PlatformWhatsNewRead $read) {
            $user = $read->user;
            $org = $read->organization;

            return [
                'user_id' => (int) $read->user_id,
                'full_name' => $user?->full_name,
                'username' => $user?->username,
                'email' => $user?->email,
                'organization_id' => $read->organization_id !== null ? (int) $read->organization_id : null,
                'organization_name' => $org?->org_name,
                'company_code' => $org?->company_code,
                'dismissed_at' => $read->dismissed_at?->toIso8601String(),
            ];
        })->values()->all();

        return [
            'data' => $data,
            'meta' => ['total' => count($data)],
        ];
    }

    /** @return array<string, mixed> */
    public function present(PlatformWhatsNewNote $note): array
    {
        $workspaceDefs = config('erp_workspaces', []);
        $workspaces = collect($note->targetedWorkspaceIds())->map(function (string $id) use ($workspaceDefs) {
            $def = $workspaceDefs[$id] ?? null;

            return [
                'id' => $id,
                'label' => is_array($def) ? (string) ($def['label'] ?? $id) : $id,
            ];
        })->values()->all();

        $attrs = $note->getAttributes();
        $readsCount = array_key_exists('reads_count', $attrs)
            ? (int) $attrs['reads_count']
            : (int) $note->reads()->count();

        return [
            'id' => (int) $note->id,
            'title' => $note->title,
            'body' => $note->body,
            'link_url' => $note->link_url,
            'audience' => $note->audience,
            'organization_ids' => $note->targetedOrganizationIds(),
            'targets_all_organizations' => $note->targetsAllOrganizations(),
            'workspace_ids' => $note->targetedWorkspaceIds(),
            'workspaces' => $workspaces,
            'status' => $note->status,
            'release_version' => $note->release_version,
            'show_on_login' => (bool) $note->show_on_login,
            'published_at' => $note->published_at?->toIso8601String(),
            'notified_count' => (int) $note->notified_count,
            'reads_count' => $readsCount,
            'created_at' => $note->created_at?->toIso8601String(),
            'updated_at' => $note->updated_at?->toIso8601String(),
            'created_by' => $note->creator ? [
                'id' => (int) $note->creator->id,
                'full_name' => $note->creator->full_name,
            ] : null,
            'published_by' => $note->publisher ? [
                'id' => (int) $note->publisher->id,
                'full_name' => $note->publisher->full_name,
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function presentForUser(PlatformWhatsNewNote $note): array
    {
        return [
            'id' => (int) $note->id,
            'title' => $note->title,
            'body' => $note->body,
            'link_url' => $note->link_url,
            'release_version' => $note->release_version,
            'published_at' => $note->published_at?->toIso8601String(),
        ];
    }

    /** @return list<int> */
    protected function resolveTargetOrganizationIds(PlatformWhatsNewNote $note): array
    {
        $platformCode = config('erp.platform_company_code', 'PLATFORM');
        $selected = $note->targetedOrganizationIds();

        $query = Organization::query()
            ->where('company_code', '!=', $platformCode)
            ->where('is_active', true);

        if ($selected !== []) {
            $query->whereIn('id', $selected);
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    protected function noteMatchesOrganization(PlatformWhatsNewNote $note, int $orgId): bool
    {
        if ($note->targetsAllOrganizations()) {
            return true;
        }

        return in_array($orgId, $note->targetedOrganizationIds(), true);
    }

    /**
     * @param  list<string>  $targetWorkspaces
     * @return list<array{id: string, home_path: string}>
     */
    protected function matchingWorkspacesForUser(User $user, CapabilityGate $gate, array $targetWorkspaces): array
    {
        $available = $this->workspaces->availableForUser($user, $gate);
        $matched = [];
        foreach ($available as $ws) {
            $id = strtolower((string) ($ws['id'] ?? ''));
            if ($id !== '' && in_array($id, $targetWorkspaces, true)) {
                $matched[] = [
                    'id' => $id,
                    'home_path' => (string) ($ws['home_path'] ?? '/dashboard'),
                ];
            }
        }

        return $matched;
    }

    protected function notificationPreview(string $body): string
    {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');
        if (mb_strlen($plain) <= 240) {
            return $plain;
        }

        return mb_substr($plain, 0, 237).'…';
    }

    /** @param  mixed  $ids */
    protected function normalizeOrgIds($ids): ?array
    {
        if ($ids === null || $ids === [] || $ids === '') {
            return null;
        }
        if (! is_array($ids)) {
            return null;
        }

        $normalized = array_values(array_unique(array_map('intval', array_filter($ids, fn ($id) => (int) $id > 0))));

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @param  mixed  $ids
     * @return list<string>
     */
    protected function normalizeWorkspaceIds($ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $allowed = array_flip($this->allowedWorkspaceIds());
        $out = [];
        foreach ($ids as $id) {
            $key = strtolower(trim((string) $id));
            if ($key !== '' && isset($allowed[$key])) {
                $out[$key] = $key;
            }
        }

        return array_values($out);
    }

    protected function nullableUrl(mixed $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        return (string) $url;
    }
}
