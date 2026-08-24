<?php

namespace App\Services\Ai\Tools;

use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiKnowledgeService;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Validation\ValidationException;

/**
 * Search platform-trained Q&A / knowledge notes for the assistant.
 */
class SearchTrainingNotesTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected AiKnowledgeService $knowledge,
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'search_training_notes';
    }

    public function description(): string
    {
        return 'Search platform-trained Centrix knowledge notes (Q&A and how-to facts saved under Platform → AI training). '
            .'Use when the user asks how Centrix works, standard procedures, terminology, or a trained FAQ — '
            .'especially if the answer may already be in training notes. Returns topic, content, and path.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'What to look up in training notes (e.g. retail packaging, GRN, UoM bags vs kg).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max notes to return (1–20). Default 8.',
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
        if (! $this->assertSameOrganization($user, $organization)) {
            throw ValidationException::withMessages([
                'organization' => ['You cannot query another organization.'],
            ]);
        }

        $gate = $this->erp->gateForUser($user);
        if (! $this->permissions->hasPermission($user, 'ai.assist', $gate)
            && ! $user->is_admin
            && ! $user->is_super_admin) {
            return [
                'error' => true,
                'message' => 'You do not have permission to use the AI assistant.',
            ];
        }

        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return [
                'error' => true,
                'message' => 'Provide a search query for training notes.',
            ];
        }

        $limit = max(1, min(20, (int) ($arguments['limit'] ?? 8)));
        $notes = $this->knowledge->searchRelevant($query, $limit, null);

        return [
            'query' => $query,
            'count' => count($notes),
            'notes' => $notes,
            'hint' => $notes === []
                ? 'No matching platform training notes. Answer from tools/documentation, or ask a platform admin to add a note under Platform → AI training.'
                : 'Prefer these trained answers when they match. Still use live tools for tenant numbers (sales, stock, attendance).',
        ];
    }
}
