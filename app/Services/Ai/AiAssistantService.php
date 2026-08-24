<?php

namespace App\Services\Ai;

use App\Exceptions\Ai\AiProviderException;
use App\Models\Organization;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AiAssistantService
{
    public function __construct(
        protected AiSystemContextBuilder $contextBuilder,
        protected AiTopicGuard $topicGuard,
        protected AiWorkspaceScope $workspaceScope,
        protected AiActionExecutor $actionExecutor,
        protected AiFormSpecBuilder $formSpecBuilder,
        protected AiKnowledgeService $knowledge,
        protected AiPageExplorer $pageExplorer,
        protected AiIntentResolver $intentResolver,
        protected AiToolChatService $toolChat,
        protected AiProviderFactory $providers,
        protected AiReplyFormatter $replyFormatter,
    ) {}

    public function isAvailableForUser(User $user): bool
    {
        return AiSettingsResolver::isAvailableForUser($user);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @param  array<string, mixed>|null  $pendingAction
     * @return array<string, mixed>
     */
    public function chat(
        User $user,
        string $message,
        array $history = [],
        ?array $pendingAction = null,
        bool $confirmAction = false,
        ?string $workspaceId = null,
        ?string $pathname = null,
        ?string $conversationId = null,
        ?array $pageContext = null,
    ): array {
        $teachResult = $this->tryCaptureUserTeaching($user, $message);
        if ($teachResult) {
            return $teachResult;
        }

        $runtime = AiSettingsResolver::resolveRuntime($user);
        if (! $runtime) {
            $notConfigured = $this->notConfiguredReply(AiSettingsResolver::forUser($user), $this->contextBuilder->gateForUser($user));

            return [
                'success' => false,
                'reply' => $notConfigured,
                'message' => $notConfigured,
                'tools_used' => [],
                'error_code' => 'not_configured',
            ];
        }

        if ($confirmAction && $pendingAction) {
            return $this->executeConfirmedAction($user, $pendingAction, $workspaceId, $pathname);
        }

        if ($pendingAction && $this->actionExecutor->isConfirmation($message)) {
            return $this->executeConfirmedAction($user, $pendingAction, $workspaceId, $pathname);
        }

        if ($pendingAction && ($this->intentResolver->isCancelIntent($message) || $this->intentResolver->isDataQuestion($message))) {
            $pendingAction = null;
        }

        // Create / write intents stay on the classic assistant (forms + confirm).
        // Tool chat is used for Gemini data Q&A (and OpenAI when AI_USE_TOOL_CHAT=true),
        // with automatic fallback to classic Gemini/OpenAI if the tool path fails.
        $inferredCreate = $this->intentResolver->inferCreateAction($message, $history, $pathname);
        $provider = strtolower((string) ($runtime['provider'] ?? config('ai.provider', 'openai')));
        $preferToolChat = ! $inferredCreate && (
            $provider === 'gemini'
            || ($provider === 'openai' && filter_var(config('ai.use_tool_chat', false), FILTER_VALIDATE_BOOLEAN))
        );
        if ($preferToolChat) {
            $toolResult = $this->toolChat->chat(
                $user,
                $message,
                $conversationId,
                $history,
                $workspaceId,
                $pathname,
                $pageContext,
            );
            if (! empty($toolResult['success']) || ! empty($toolResult['declined_off_topic'])) {
                return $toolResult;
            }

            Log::info('AI tool chat failed; falling back to classic assistant', [
                'provider' => $provider,
                'error_code' => $toolResult['error_code'] ?? null,
            ]);
        }

        $gate = $this->contextBuilder->gateForUser($user);
        $scope = $this->workspaceScope->resolve($user, $gate, $workspaceId, $pathname);

        if (! $this->topicGuard->isErpRelated($message)) {
            return [
                'reply' => $this->topicGuard->declineMessage(),
                'tools_used' => [],
                'declined_off_topic' => true,
            ];
        }

        if (! $this->workspaceScope->isMessageInScope($message, $scope, $pendingAction)) {
            return [
                'reply' => $this->workspaceScope->declineMessage($scope),
                'tools_used' => [],
                'declined_off_topic' => true,
                'active_workspace' => $scope['id'],
            ];
        }

        $systemContext = $this->contextBuilder->build($user, $message, $scope);
        if (is_array($pageContext) && $pageContext !== []) {
            $systemContext['page_context'] = $this->compactPageContext($pageContext);
        }
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($scope)],
            [
                'role' => 'system',
                'content' => "Organization ERP context (use for navigation, permissions, entity schemas, and data — do not invent):\n"
                    .json_encode($systemContext, JSON_PRETTY_PRINT),
            ],
        ];

        foreach (array_slice($history, -10) as $turn) {
            if (! empty($turn['role']) && ! empty($turn['content'])) {
                $messages[] = ['role' => $turn['role'], 'content' => (string) $turn['content']];
            }
        }

        if ($pendingAction) {
            $messages[] = [
                'role' => 'system',
                'content' => 'Pending action awaiting user confirmation: '.json_encode($pendingAction),
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        try {
            $rawReply = $this->completeWithProvider($runtime, $messages);
            if ($rawReply === '') {
                $rawReply = 'I could not generate a response. Please try rephrasing your question.';
            }

            if (str_contains($rawReply, 'DECLINE_OFF_TOPIC')) {
                return [
                    'reply' => $this->topicGuard->declineMessage(),
                    'tools_used' => [],
                    'declined_off_topic' => true,
                ];
            }

            $parsedLearn = self::parseLearnBlock($rawReply);
            $rawReply = self::stripLearnBlock($rawReply);

            if ($parsedLearn && ! empty($parsedLearn['path'])) {
                return $this->buildLearnProposal($user, $parsedLearn, AiActionExecutor::stripActionBlock($rawReply));
            }

            $parsedAction = AiActionExecutor::parseActionBlock($rawReply);
            $reply = $this->replyFormatter->format(AiActionExecutor::stripActionBlock($rawReply));

            $result = [
                'success' => true,
                'reply' => $reply,
                'message' => $reply,
                'tools_used' => array_keys(array_diff_key(
                    $systemContext,
                    array_flip(['organization', 'user', 'enabled_modules', 'active_workspace']),
                )),
                'data' => $systemContext,
                'active_workspace' => $scope['id'],
                'provider' => $runtime['provider'] ?? null,
            ];

            $pending = null;
            if ($parsedAction) {
                $pending = [
                    'type' => $parsedAction['type'] ?? null,
                    'summary' => $parsedAction['summary'] ?? ($parsedAction['label'] ?? 'Proposed action'),
                    'params' => $parsedAction['params'] ?? [],
                ];
            } elseif ($pendingAction && $this->shouldContinuePendingAction($message, $history, $pathname)) {
                $pending = $pendingAction;
            } else {
                $inferred = $inferredCreate ?? $this->intentResolver->inferCreateAction($message, $history, $pathname);
                if ($inferred) {
                    $pending = $inferred;
                    if ($this->looksLikeFetchingReply($reply)) {
                        $result['reply'] = 'Use the form below to complete the details. Options are loaded from your organization data.';
                        $result['message'] = $result['reply'];
                    }
                }
            }

            if ($pending) {
                $actionType = (string) ($pending['type'] ?? '');
                if ($actionType !== '' && ! in_array($actionType, $scope['action_types'] ?? [], true)) {
                    $result['reply'] = $this->workspaceScope->declineMessage($scope);
                    $result['message'] = $result['reply'];
                    unset($pending);
                } elseif ($actionType !== '' && ! $this->actionExecutor->canExecute($user, $actionType)) {
                    $result['reply'] = $this->actionExecutor->permissionDeclineMessage($actionType);
                    $result['message'] = $result['reply'];
                    unset($pending);
                } else {
                    $result['pending_action'] = $pending;
                    $result['form_spec'] = $this->formSpecBuilder->forAction($user, $pending, $pathname);
                    if (empty(trim($result['reply'] ?? ''))) {
                        $result['reply'] = $actionType === 'record_customer_payment'
                            ? 'Fill in the form below, then click Confirm & record payment.'
                            : 'Fill in the form below, then click Confirm & create.';
                        $result['message'] = $result['reply'];
                    }
                }
            }

            return $result;
        } catch (AiProviderException $e) {
            Log::warning('AI chat provider failure', [
                'provider' => $runtime['provider'] ?? null,
                'code' => $e->codeKey,
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'reply' => $e->getMessage(),
                'message' => $e->getMessage(),
                'tools_used' => [],
                'error_code' => $e->codeKey,
            ];
        } catch (\Throwable $e) {
            Log::error('AI chat exception', [
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return [
                'success' => false,
                'reply' => $this->formatInternalFailure($e),
                'message' => $this->formatInternalFailure($e),
                'tools_used' => [],
                'error_code' => 'internal_error',
            ];
        }
    }

    /**
     * Chat in the context of a target organization (platform AI training sandbox).
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @param  array<string, mixed>|null  $pendingAction
     * @return array<string, mixed>
     */
    public function chatForOrganization(
        User $user,
        Organization $organization,
        string $message,
        array $history = [],
        ?array $pendingAction = null,
        bool $confirmAction = false,
        ?string $workspaceId = null,
        ?string $pathname = null,
        bool $trainingMode = false,
    ): array {
        if ($confirmAction && $pendingAction) {
            return [
                'reply' => 'Training mode — actions are not executed against tenant data.',
                'tools_used' => ['training_mode'],
                'training_mode' => true,
            ];
        }

        $runtime = $trainingMode
            ? AiSettingsResolver::resolveRuntimeForPlatformTraining()
            : AiSettingsResolver::resolveRuntimeForOrganization($organization);
        if (! $runtime) {
            if ($trainingMode) {
                $settings = AiSettingsResolver::forPlatformTraining();

                return [
                    'reply' => ! ($settings['enabled'] ?? false)
                        ? 'Platform AI training is disabled. Enable it under Platform → AI training → Platform AI credentials.'
                        : 'Platform AI training is not configured — add an API key under Platform → Settings → AI credentials.',
                    'tools_used' => [],
                    'training_mode' => true,
                ];
            }

            $gate = (new CapabilityGate)->forOrganization($organization);
            $settings = AiSettingsResolver::forOrganization($organization);

            return [
                'reply' => ! $gate->aiPlatformEnabled()
                    ? 'AI assistant is not enabled at platform level for this organization.'
                    : (! ($settings['enabled'] ?? false)
                        ? 'AI assistant is disabled for this organization. Enable it under Organization settings → AI.'
                        : 'AI assistant is not configured — add an API key under Organization settings → AI.'),
                'tools_used' => [],
                'training_mode' => $trainingMode,
            ];
        }

        $gate = (new CapabilityGate)->forOrganization($organization);
        $scope = $this->workspaceScope->resolve($user, $gate, $workspaceId, $pathname);

        if (! $this->topicGuard->isErpRelated($message)) {
            return [
                'reply' => $this->topicGuard->declineMessage(),
                'tools_used' => [],
                'declined_off_topic' => true,
                'training_mode' => $trainingMode,
            ];
        }

        if (! $this->workspaceScope->isMessageInScope($message, $scope, $pendingAction)) {
            return [
                'reply' => $this->workspaceScope->declineMessage($scope),
                'tools_used' => [],
                'declined_off_topic' => true,
                'active_workspace' => $scope['id'],
                'training_mode' => $trainingMode,
            ];
        }

        $systemContext = $this->contextBuilder->build($user, $message, $scope, $organization, $trainingMode);
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($scope)],
            [
                'role' => 'system',
                'content' => "Organization ERP context (use for navigation, permissions, entity schemas, and data — do not invent):\n"
                    .json_encode($systemContext, JSON_PRETTY_PRINT),
            ],
        ];

        foreach (array_slice($history, -10) as $turn) {
            if (! empty($turn['role']) && ! empty($turn['content'])) {
                $messages[] = ['role' => $turn['role'], 'content' => (string) $turn['content']];
            }
        }

        if ($pendingAction) {
            $messages[] = [
                'role' => 'system',
                'content' => 'Pending action awaiting user confirmation: '.json_encode($pendingAction),
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        try {
            $rawReply = $this->completeWithProvider($runtime, $messages);
            if ($rawReply === '') {
                $rawReply = 'I could not generate a response. Please try rephrasing your question.';
            }

            if (str_contains($rawReply, 'DECLINE_OFF_TOPIC')) {
                return [
                    'reply' => $this->topicGuard->declineMessage(),
                    'tools_used' => [],
                    'declined_off_topic' => true,
                    'training_mode' => $trainingMode,
                ];
            }

            $parsedAction = AiActionExecutor::parseActionBlock($rawReply);
            $reply = $this->replyFormatter->format(AiActionExecutor::stripActionBlock($rawReply));

            $result = [
                'success' => true,
                'reply' => $reply,
                'message' => $reply,
                'tools_used' => array_keys(array_diff_key(
                    $systemContext,
                    array_flip(['organization', 'user', 'enabled_modules', 'active_workspace']),
                )),
                'active_workspace' => $scope['id'],
                'training_mode' => $trainingMode,
                'provider' => $runtime['provider'] ?? null,
            ];

            $pending = null;
            if ($parsedAction) {
                $pending = [
                    'type' => $parsedAction['type'] ?? null,
                    'summary' => $parsedAction['summary'] ?? ($parsedAction['label'] ?? 'Proposed action'),
                    'params' => $parsedAction['params'] ?? [],
                ];
            } elseif ($pendingAction && $this->shouldContinuePendingAction($message, $history, $pathname)) {
                $pending = $pendingAction;
            } else {
                $inferred = $this->intentResolver->inferCreateAction($message, $history, $pathname);
                if ($inferred) {
                    $pending = $inferred;
                    if ($this->looksLikeFetchingReply($reply)) {
                        $result['reply'] = 'Use the form below to preview the fields. Confirm is disabled in training mode.';
                        $result['message'] = $result['reply'];
                    }
                }
            }

            if ($pending) {
                $actionType = (string) ($pending['type'] ?? '');
                if ($actionType !== '' && ! in_array($actionType, $scope['action_types'] ?? [], true)) {
                    $result['reply'] = $this->workspaceScope->declineMessage($scope);
                    $result['message'] = $result['reply'];
                } else {
                    $contextUser = clone $user;
                    $contextUser->organization_id = $organization->id;
                    $contextUser->is_admin = true;
                    $result['pending_action'] = $pending;
                    $result['form_spec'] = $this->formSpecBuilder->forAction($contextUser, $pending, $pathname);
                    if (empty(trim($result['reply'] ?? ''))) {
                        $result['reply'] = 'Preview the form below. Training mode does not execute actions against tenant data.';
                        $result['message'] = $result['reply'];
                    }
                }
            }

            return $result;
        } catch (AiProviderException $e) {
            Log::warning('AI training chat provider failure', [
                'provider' => $runtime['provider'] ?? null,
                'code' => $e->codeKey,
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'reply' => $e->getMessage(),
                'message' => $e->getMessage(),
                'tools_used' => [],
                'error_code' => $e->codeKey,
                'training_mode' => $trainingMode,
            ];
        } catch (\Throwable $e) {
            Log::error('AI training chat exception', [
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return [
                'success' => false,
                'reply' => $this->formatInternalFailure($e),
                'message' => $this->formatInternalFailure($e),
                'tools_used' => [],
                'error_code' => 'internal_error',
                'training_mode' => $trainingMode,
            ];
        }
    }

    /** @param  array<string, mixed>  $pendingAction
     * @return array<string, mixed>
     */
    protected function executeConfirmedAction(
        User $user,
        array $pendingAction,
        ?string $workspaceId = null,
        ?string $pathname = null,
    ): array {
        try {
            $gate = $this->contextBuilder->gateForUser($user);
            $scope = $this->workspaceScope->resolve($user, $gate, $workspaceId, $pathname);
            $actionType = (string) ($pendingAction['type'] ?? '');
            if ($actionType !== '' && ! in_array($actionType, $scope['action_types'] ?? [], true)) {
                return [
                    'reply' => $this->workspaceScope->declineMessage($scope),
                    'tools_used' => ['action_executor'],
                    'action_error' => true,
                    'pending_action' => null,
                    'form_spec' => null,
                    'active_workspace' => $scope['id'],
                ];
            }

            $outcome = $this->actionExecutor->execute($user, $pendingAction);
            $result = $outcome['result'] ?? [];
            $path = $result['path'] ?? null;
            $linkHint = $path ? " Open: {$path}" : '';
            $reply = $this->replyFormatter->format(($outcome['message'] ?? 'Done.').$linkHint);

            return [
                'reply' => $reply,
                'tools_used' => ['action_executor'],
                'action_result' => $outcome,
                'pending_action' => null,
                'form_spec' => null,
            ];
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?? 'Action could not be completed.';

            return [
                'reply' => $msg,
                'tools_used' => ['action_executor'],
                'action_error' => true,
                'pending_action' => $pendingAction,
                'form_spec' => $this->formSpecBuilder->forAction($user, $pendingAction, $pathname),
            ];
        } catch (ModelNotFoundException $e) {
            Log::error('AI action model not found', ['message' => $e->getMessage()]);

            return [
                'reply' => 'A referenced record was not found. Check product codes, customer numbers, and other selections, then try again.',
                'tools_used' => ['action_executor'],
                'action_error' => true,
                'pending_action' => $pendingAction,
                'form_spec' => $this->formSpecBuilder->forAction($user, $pendingAction, $pathname),
            ];
        } catch (\Throwable $e) {
            Log::error('AI action failed', ['message' => $e->getMessage()]);

            return [
                'reply' => 'The action could not be completed: '.$e->getMessage(),
                'tools_used' => ['action_executor'],
                'action_error' => true,
                'pending_action' => $pendingAction,
                'form_spec' => $this->formSpecBuilder->forAction($user, $pendingAction, $pathname),
            ];
        }
    }

    /** @return array<string, mixed>|null */
    protected function tryCaptureUserTeaching(User $user, string $message): ?array
    {
        if (! preg_match('/^(remember|note|teach)\s*(that|:)\s*(.+)$/is', trim($message), $m)) {
            return null;
        }

        $content = trim($m[3] ?? '');
        if ($content === '') {
            return null;
        }

        if (! $user->is_super_admin) {
            return [
                'reply' => 'Training notes are managed platform-wide and apply to every organization. Ask your platform administrator to add notes under Platform → AI training.',
                'tools_used' => [],
            ];
        }

        $entry = $this->knowledge->teachGlobal($user, 'User note', $content, null, null, 'user_teaching');

        return [
            'reply' => 'Got it — I saved that as platform-wide training. Every organization will use it in future answers.',
            'tools_used' => ['user_teaching'],
            'knowledge_saved' => $entry,
        ];
    }

    /** @param  array<string, mixed>  $learn
     * @return array<string, mixed>
     */
    protected function buildLearnProposal(User $user, array $learn, string $reply): array
    {
        $path = (string) ($learn['path'] ?? '/dashboard');
        $analysis = $this->pageExplorer->analyze($user, $path);
        $draft = $this->knowledge->storeDraft(
            $user,
            (string) $analysis['topic'],
            (string) $analysis['summary'],
            'page_explore',
            $analysis['path'],
        );

        return [
            'reply' => $reply !== ''
                ? $reply
                : 'I analyzed this screen. Please confirm to save what I learned as platform-wide training.',
            'tools_used' => ['page_explorer'],
            'pending_learn' => [
                'draft_id' => $draft['id'],
                'path' => $analysis['path'],
                'summary' => $analysis['summary'],
                'topic' => $analysis['topic'],
            ],
            'explore_analysis' => $analysis,
        ];
    }

    /** @return array<string, mixed>|null */
    public static function parseLearnBlock(string $reply): ?array
    {
        if (preg_match('/```learn\s*([\s\S]*?)```/i', $reply, $m)) {
            $decoded = json_decode(trim($m[1]), true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    public static function stripLearnBlock(string $reply): string
    {
        return trim(preg_replace('/```learn\s*[\s\S]*?```/i', '', $reply) ?? $reply);
    }

    protected function formatInternalFailure(\Throwable $e): string
    {
        if (config('app.debug')) {
            return 'AI assistant error: '.$e->getMessage();
        }

        return 'AI assistant encountered an internal error. Try again or contact your administrator.';
    }

    /**
     * @param  array{provider?: string, api_key: string, model: string, base_url?: string}  $runtime
     * @param  list<array{role: string, content: string}>  $messages
     */
    protected function completeWithProvider(array $runtime, array $messages): string
    {
        $systemParts = [];
        $history = [];
        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');
            $content = (string) ($message['content'] ?? '');
            if ($content === '') {
                continue;
            }
            if ($role === 'system') {
                $systemParts[] = $content;
                continue;
            }
            $history[] = [
                'role' => $role === 'assistant' ? 'assistant' : 'user',
                'content' => $content,
            ];
        }

        $turn = $this->providers->make($runtime)->chat([
            'system' => implode("\n\n", $systemParts),
            'messages' => $history,
            'temperature' => 0.25,
            'max_output_tokens' => (int) config('ai.defaults.max_output_tokens', config('ai.defaults.max_tokens', 2048)),
            'thinking_level' => strtolower((string) ($runtime['provider'] ?? '')) === 'gemini' ? 'MINIMAL' : null,
        ]);

        return trim((string) ($turn['text'] ?? ''));
    }

    protected function notConfiguredReply(array $settings, CapabilityGate $gate): string
    {
        if (! $gate->aiPlatformEnabled()) {
            return 'AI assistant is not enabled for this organization. Contact your platform administrator.';
        }

        if (! empty($settings['use_platform_gemini'])) {
            return AiSettingsResolver::platformFreeAiConfigured()
                ? 'AI assistant is not available right now. Try again shortly.'
                : 'Platform AI is enabled for this organization, but credentials are not configured. A platform admin must add them under Platform → Settings → AI credentials.';
        }

        if (! ($settings['enabled'] ?? false)) {
            return 'AI assistant is disabled for this organization. An admin can enable it under Administration → Settings → AI.';
        }

        return 'AI assistant is not configured. Add an API key under Administration → Settings → AI, or ask a platform admin to enable Platform AI for this organization.';
    }

    protected function looksLikeFetchingReply(string $reply): bool
    {
        return (bool) preg_match('/\b(fetch|hold on|please wait|moment|loading|retrieve|look up)\b/i', $reply);
    }

    protected function systemPrompt(array $scope): string
    {
        $label = $scope['label'] ?? 'this workspace';
        $description = $scope['description'] ?? '';

        return <<<PROMPT
You are the in-app assistant for Centrix ERP — a Kenya-focused business management system (currency KES).

ACTIVE WORKSPACE: {$label}. {$description}
Prefer answering in the context of {$label}, but you MAY answer navigation / "where do I…?" / "how do I…?" questions for ANY Centrix module.
When guiding to another module, give the Centrix path (e.g. /hr/employees) — clicking it opens that application automatically.
Do not invent numbers for other modules — for live sales/stock/purchasing data, tell them to ask again after switching workspace if create-actions are scoped here.

Use entity_schemas in context — it lists every field, which are required, auto-generated, important, and FK relations (e.g. unit_id → uoms).
Use platform_knowledge for ERP-wide facts trained by platform administrators — they apply to every organization.
Use navigation, module_catalog, and available_actions to act as Centrix documentation.

INTERACTIVE FORMS: Select options are ALREADY in entity_detail / entity_schemas — never say you are fetching or ask the user to wait.

Image uploads are NOT supported — never ask for photos or images.

RULES:
1. Off-topic only for weather, recipes, trivia, unrelated coding → reply with DECLINE_OFF_TOPIC on its own line.
2. Navigation/help across modules is allowed. Decline only WRITE/create actions outside {$label} available_actions — suggest switching workspace.
3. Use entity_schemas.field metadata: skip auto-generated fields unless user provides a value; use select options for FK fields.
4. Normal orders = create_sales_order; held/save-only = create_held_order only when explicitly requested.
5. Platform administrators train ERP-wide notes under Platform → AI training; they apply to all tenants.
6. PERMISSIONS — read user_access in context:
   - user.is_admin=true or user_access.has_full_permissions=true → user has ALL permissions; never say they lack access.
   - Answer read-only questions using *_summary data in context when present.
   - Only decline WRITE actions not listed in available_actions.
7. Always include clickable Centrix paths like /inventory/stock when telling users where to go.
8. Only cite paths from navigation / workflows / find_screen — never invent menu paths.
9. People: use username and full name — never numeric user id or employee id.
10. Formulas: plain text with real field names (Stock Value = Cost Price × Stock on Hand). Never LaTeX.
11. Markdown headings (# ## ###) are fine; the UI renders them as real headings.
12. Custom report builder: ask what to name the report, then emit create_report_template with name + instruction (or wait for confirmation). After save, give /reports/custom/{id}.

```action
{"type":"create_product","summary":"New product Widget","params":{"product_name":"Widget","unit_price":150}}
```

Tell users to fill the form and confirm, or reply "confirm" when params are complete.
PROMPT;
    }

    /** @param  array<int, array{role: string, content: string}>  $history */
    protected function shouldContinuePendingAction(string $message, array $history, ?string $pathname): bool
    {
        if ($this->intentResolver->isCancelIntent($message) || $this->intentResolver->isDataQuestion($message)) {
            return false;
        }

        if ($this->intentResolver->inferCreateAction($message, $history, $pathname) !== null) {
            return true;
        }

        return (bool) preg_match(
            '/\b(subcategory|supplier|unit|price|sku|barcode|vat|reorder|product\s+name|named|called)\b/i',
            $message,
        );
    }

    /**
     * @param  array<string, mixed>  $pageContext
     * @return array<string, mixed>
     */
    protected function compactPageContext(array $pageContext): array
    {
        $rows = is_array($pageContext['rows'] ?? null) ? $pageContext['rows'] : [];
        if (count($rows) > 40) {
            $rows = array_slice($rows, 0, 40);
        }

        return array_filter([
            'screen_key' => $pageContext['screen_key'] ?? null,
            'title' => $pageContext['title'] ?? null,
            'pathname' => $pageContext['pathname'] ?? null,
            'entity' => $pageContext['entity'] ?? null,
            'entity_id' => $pageContext['entity_id'] ?? null,
            'branch_id' => $pageContext['branch_id'] ?? null,
            'filters' => is_array($pageContext['filters'] ?? null) ? $pageContext['filters'] : null,
            'summary' => is_array($pageContext['summary'] ?? null) ? $pageContext['summary'] : null,
            'rows' => $rows !== [] ? $rows : null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
