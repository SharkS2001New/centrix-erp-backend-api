<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\WorkspaceResolver;

class AiWorkspaceScope
{
    public function __construct(
        protected WorkspaceResolver $workspaceResolver,
    ) {}

    /**
     * @return array{
     *   id: string,
     *   label: string,
     *   description: string,
     *   nav_section_ids: list<string>,
     *   action_types: list<string>,
     *   module_catalog_keys: list<string>,
     *   workflow_keys: list<string>
     * }
     */
    public function resolve(User $user, CapabilityGate $gate, ?string $workspaceId, ?string $pathname = null): array
    {
        $available = collect($this->workspaceResolver->availableForUser($user, $gate))
            ->keyBy('id');

        $id = $this->normalizeWorkspaceId($workspaceId, $pathname, $available->keys()->all());

        if (! $available->has($id)) {
            $id = (string) ($available->keys()->first() ?? 'backoffice');
        }

        $def = config("ai_workspaces.{$id}", config('ai_workspaces.backoffice', []));

        return [
            'id' => $id,
            'label' => (string) ($def['label'] ?? $id),
            'description' => (string) ($def['description'] ?? ''),
            'nav_section_ids' => array_values($def['nav_section_ids'] ?? []),
            'nav_path_prefixes' => array_values($def['nav_path_prefixes'] ?? []),
            'report_include_paths' => array_values($def['report_include_paths'] ?? []),
            'report_exclude_paths' => array_values($def['report_exclude_paths'] ?? []),
            'action_types' => array_values($def['action_types'] ?? []),
            'module_catalog_keys' => array_values($def['module_catalog_keys'] ?? []),
            'workflow_keys' => array_values($def['workflow_keys'] ?? []),
            'keywords' => array_values($def['keywords'] ?? []),
            'foreign_signals' => array_values($def['foreign_signals'] ?? []),
        ];
    }

    public function declineMessage(array $scope): string
    {
        $label = $scope['label'] ?? 'this module';

        return "I can only help with {$label} while you have that workspace open. "
            ."Switch workspace from the top bar (grid icon) to ask about other modules, "
            ."or rephrase your question for {$label}.";
    }

    /**
     * @param  list<string>  $availableIds
     */
    protected function normalizeWorkspaceId(?string $workspaceId, ?string $pathname, array $availableIds): string
    {
        $id = strtolower(trim((string) $workspaceId));
        if ($id !== '' && in_array($id, $availableIds, true)) {
            return $id;
        }

        $path = (string) ($pathname ?? '');
        foreach (config('ai_workspaces', []) as $candidate => $def) {
            if (! in_array($candidate, $availableIds, true)) {
                continue;
            }
            foreach ($def['nav_path_prefixes'] ?? [] as $prefix) {
                if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/').'/')) {
                    return (string) $candidate;
                }
            }
        }

        return $id !== '' ? $id : 'backoffice';
    }

    /**
     * @param  array<string, mixed>|null  $pendingAction
     */
    public function isMessageInScope(string $message, array $scope, ?array $pendingAction = null): bool
    {
        if ($pendingAction !== null) {
            return true;
        }

        $text = trim($message);
        if ($text === '') {
            return false;
        }

        if (preg_match('/^(hi|hello|hey|help|thanks|thank you|ok|yes|no|confirm|proceed|go ahead)\b/i', $text)) {
            return true;
        }

        if (preg_match('/^(remember|note|teach)\s*(that|:)/i', $text)) {
            return true;
        }

        $lower = strtolower($text);

        foreach ($scope['foreign_signals'] ?? [] as $pattern) {
            if (@preg_match($pattern, $text) === 1) {
                $matchedOwn = false;
                foreach ($scope['keywords'] ?? [] as $keyword) {
                    if ($keyword !== '' && str_contains($lower, strtolower($keyword))) {
                        $matchedOwn = true;
                        break;
                    }
                }
                if (! $matchedOwn) {
                    return false;
                }
            }
        }

        foreach ($scope['keywords'] ?? [] as $keyword) {
            if ($keyword !== '' && str_contains($lower, strtolower($keyword))) {
                return true;
            }
        }

        return strlen($text) <= 120;
    }

    /** @param  list<array<string, mixed>>  $sections */
    public function filterNavigation(array $sections, array $scope): array
    {
        $allowedIds = $scope['nav_section_ids'] ?? [];
        $prefixes = $scope['nav_path_prefixes'] ?? [];
        $includeReports = $scope['report_include_paths'] ?? [];
        $excludeReports = $scope['report_exclude_paths'] ?? [];

        $out = [];
        foreach ($sections as $section) {
            $sectionId = (string) ($section['id'] ?? '');
            if ($allowedIds !== [] && ! in_array($sectionId, $allowedIds, true)) {
                continue;
            }

            $items = [];
            foreach ($section['items'] ?? [] as $item) {
                $path = (string) ($item['path'] ?? '');
                if ($path === '') {
                    continue;
                }

                if ($sectionId === 'reports') {
                    if ($includeReports !== [] && ! $this->pathMatchesAny($path, $includeReports)) {
                        continue;
                    }
                    if ($excludeReports !== [] && $this->pathMatchesAny($path, $excludeReports)) {
                        continue;
                    }
                } elseif ($prefixes !== [] && ! $this->pathMatchesAny($path, $prefixes)) {
                    continue;
                }

                $items[] = $item;
            }

            if ($items !== []) {
                $section['items'] = $items;
                $out[] = $section;
            }
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $actions */
    public function filterActions(array $actions, array $scope): array
    {
        $allowed = $scope['action_types'] ?? [];
        if ($allowed === []) {
            return [];
        }

        return array_values(array_filter(
            $actions,
            fn ($action) => in_array((string) ($action['type'] ?? ''), $allowed, true),
        ));
    }

    /** @param  list<array<string, mixed>>  $modules */
    public function filterModuleCatalog(array $modules, array $scope): array
    {
        $allowed = $scope['module_catalog_keys'] ?? [];
        if ($allowed === []) {
            return [];
        }

        return array_values(array_filter(
            $modules,
            fn ($module) => in_array((string) ($module['key'] ?? ''), $allowed, true),
        ));
    }

    /** @param  array<string, mixed>  $workflows */
    public function filterWorkflows(array $workflows, array $scope): array
    {
        $allowed = $scope['workflow_keys'] ?? [];
        if ($allowed === []) {
            return [];
        }

        return array_intersect_key($workflows, array_flip($allowed));
    }

    /** @param  list<string>  $prefixes */
    protected function pathMatchesAny(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            $prefix = (string) $prefix;
            if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/').'/')) {
                return true;
            }
        }

        return false;
    }
}
