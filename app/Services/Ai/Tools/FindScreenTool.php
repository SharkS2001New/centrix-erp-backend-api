<?php

namespace App\Services\Ai\Tools;

use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiSystemContextBuilder;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Validation\ValidationException;

/**
 * Find Centrix screens / paths so the assistant can guide users who do not know where to go.
 */
class FindScreenTool implements AiToolInterface
{
    public function __construct(
        protected AiSystemContextBuilder $contextBuilder,
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'find_screen';
    }

    public function description(): string
    {
        return 'Find Centrix ERP screens and paths for a topic (where to go / how to open a feature). '
            .'Use for questions like "where are suppliers?", "how do I receive stock?", "open GRN", '
            .'"roles and permissions", or when the user does not know which menu to use. '
            .'Returns matching labels and clickable paths the user can open.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'What the user wants to find or do (e.g. suppliers, GRN, payroll, sales by user).',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $organization = $this->resolveOrganizationForUser($user);
        if (! $organization) {
            throw ValidationException::withMessages([
                'organization' => ['Your account is not linked to an organization.'],
            ]);
        }

        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return [
                'error' => true,
                'message' => 'Provide a short description of the screen or task to find.',
            ];
        }

        $docs = $this->contextBuilder->documentationContext($user, $organization);
        $needle = mb_strtolower($query);
        $tokens = preg_split('/[\s,;\/\-]+/', $needle) ?: [];
        $tokens = array_values(array_filter($tokens, fn ($t) => mb_strlen($t) >= 2));

        $screens = [];
        foreach ($docs['navigation'] ?? [] as $item) {
            $hay = mb_strtolower(implode(' ', [
                $item['label'] ?? '',
                $item['path'] ?? '',
                $item['section'] ?? '',
            ]));
            $score = $this->score($hay, $needle, $tokens);
            if ($score <= 0) {
                continue;
            }
            $screens[] = [
                'score' => $score,
                'label' => $item['label'],
                'path' => $item['path'],
                'section' => $item['section'],
            ];
        }

        usort($screens, fn ($a, $b) => $b['score'] <=> $a['score']);
        $screens = array_slice(array_map(function (array $row) {
            unset($row['score']);

            return $row;
        }, $screens), 0, 8);

        $modules = [];
        foreach ($docs['module_catalog'] ?? [] as $module) {
            $hay = mb_strtolower(implode(' ', [
                $module['key'] ?? '',
                $module['label'] ?? '',
                implode(' ', $module['tasks'] ?? []),
                implode(' ', $module['paths'] ?? []),
            ]));
            if ($this->score($hay, $needle, $tokens) <= 0) {
                continue;
            }
            $modules[] = [
                'label' => $module['label'],
                'paths' => $module['paths'] ?? [],
                'tasks' => $module['tasks'] ?? [],
            ];
        }

        $workflows = [];
        foreach ($docs['workflows'] ?? [] as $workflow) {
            $hay = mb_strtolower(implode(' ', [
                $workflow['key'] ?? '',
                $workflow['summary'] ?? '',
                $workflow['path'] ?? '',
            ]));
            if ($this->score($hay, $needle, $tokens) <= 0) {
                continue;
            }
            $workflows[] = $workflow;
        }

        $knowledge = [];
        foreach ($docs['platform_knowledge'] ?? [] as $entry) {
            $hay = mb_strtolower(implode(' ', [
                $entry['topic'] ?? '',
                $entry['content'] ?? '',
                $entry['path'] ?? '',
            ]));
            if ($this->score($hay, $needle, $tokens) <= 0) {
                continue;
            }
            $knowledge[] = [
                'topic' => $entry['topic'] ?? null,
                'content' => $entry['content'] ?? null,
                'path' => $entry['path'] ?? null,
            ];
        }

        return [
            'query' => $query,
            'screens' => $screens,
            'modules' => array_slice($modules, 0, 5),
            'workflows' => array_slice($workflows, 0, 5),
            'platform_knowledge' => array_slice($knowledge, 0, 5),
            'tip' => $screens === []
                ? 'No exact screen match. Suggest the closest module from module_catalog in the system context, or ask the user to clarify.'
                : 'Reply with the best path as a clickable Centrix link (e.g. /suppliers). Explain briefly what the screen is for.',
        ];
    }

    /**
     * @param  list<string>  $tokens
     */
    protected function score(string $haystack, string $needle, array $tokens): int
    {
        $score = 0;
        if ($needle !== '' && str_contains($haystack, $needle)) {
            $score += 10;
        }
        foreach ($tokens as $token) {
            if (str_contains($haystack, $token)) {
                $score += 2;
            }
        }

        $aliases = [
            'grn' => 'receipt',
            'lpo' => 'purchase',
            'po' => 'purchase',
            'sku' => 'product',
            'stock' => 'stock',
            'inventory' => 'inventory',
            'cashier' => 'sales',
            'till' => 'till',
            'debtor' => 'debtor',
            'payroll' => 'payroll',
            'attendance' => 'attendance',
            'clock' => 'attendance',
            'punch' => 'attendance',
            'builder' => 'builder',
            'permission' => 'role',
            'user' => 'user',
        ];
        foreach ($aliases as $alias => $mapped) {
            if (str_contains($needle, $alias) && str_contains($haystack, $mapped)) {
                $score += 3;
            }
        }
        if (str_contains($needle, 'field') && str_contains($haystack, 'field attendance')) {
            $score += 5;
        }
        if ((str_contains($needle, 'custom report') || str_contains($needle, 'report builder'))
            && str_contains($haystack, 'builder')) {
            $score += 5;
        }

        return $score;
    }

    protected function resolveOrganizationForUser(User $user): ?Organization
    {
        $request = request();
        $actingId = $request->attributes->get('acting_organization_id');
        if ($actingId && ($request->user()?->id === $user->id || $user->is_super_admin)) {
            $acting = Organization::query()->find((int) $actingId);
            if ($acting) {
                return $acting;
            }
        }

        return Organization::query()->find((int) $user->organization_id);
    }
}
