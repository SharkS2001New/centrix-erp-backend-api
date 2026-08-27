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
        protected AiLanguageGuard $languageGuard,
        protected AiWorkspaceScope $workspaceScope,
        protected AiActionExecutor $actionExecutor,
        protected AiFormSpecBuilder $formSpecBuilder,
        protected AiKnowledgeService $knowledge,
        protected AiPageExplorer $pageExplorer,
        protected AiIntentResolver $intentResolver,
        protected AiToolChatService $toolChat,
        protected AiProviderFactory $providers,
        protected AiReplyFormatter $replyFormatter,
        protected AiAssistantHelpGuide $helpGuide,
        protected AiCreateProductParamMerger $productParamMerger,
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
        ?array $entityRefs = null,
    ): array {
        $teachResult = $this->tryCaptureUserTeaching($user, $message);
        if ($teachResult) {
            return $teachResult;
        }

        if ($this->helpGuide->isHelpRequest($message) && ! $pendingAction && ! $confirmAction) {
            $label = null;
            try {
                $gate = $this->contextBuilder->gateForUser($user);
                $scope = $this->workspaceScope->resolve($user, $gate, $workspaceId, $pathname);
                $label = (string) ($scope['label'] ?? null);
            } catch (\Throwable) {
                $label = null;
            }
            $help = $this->replyFormatter->format($this->helpGuide->reply($label));

            return [
                'success' => true,
                'reply' => $help,
                'message' => $help,
                'tools_used' => ['help_guide'],
            ];
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

        $productMergeNotes = [];
        if (is_array($pendingAction) && (string) ($pendingAction['type'] ?? '') === 'create_product') {
            $mergedProduct = $this->productParamMerger->merge($user, $pendingAction, $message, $history);
            $pendingAction = $mergedProduct['pending'];
            $productMergeNotes = $mergedProduct['notes'];

            // Short field follow-ups (VAT / UoM / prices) must not depend on the LLM.
            if (
                ! $confirmAction
                && ! $this->actionExecutor->isConfirmation($message)
                && ! $this->intentResolver->isCancelIntent($message)
                && ! $this->intentResolver->isDataQuestion($message)
                && ($mergedProduct['changed'] || $this->productParamMerger->looksLikeFieldFollowUp($message))
            ) {
                $gate = $this->contextBuilder->gateForUser($user);
                $scope = $this->workspaceScope->resolve($user, $gate, $workspaceId, $pathname);
                $reply = $this->productParamMerger->statusReply($pendingAction, $productMergeNotes);

                return $this->attachPendingAction(
                    $user,
                    [
                        'success' => true,
                        'reply' => $reply,
                        'message' => $reply,
                        'tools_used' => ['create_product_param_merger'],
                        'active_workspace' => $scope['id'],
                        'provider' => $runtime['provider'] ?? null,
                    ],
                    $pendingAction,
                    $message,
                    $pathname,
                    $scope,
                );
            }
        }

        if ($confirmAction && $pendingAction) {
            if (! $this->actionExecutor->isReadyToConfirm($pendingAction)) {
                $gate = $this->contextBuilder->gateForUser($user);
                $scope = $this->workspaceScope->resolve($user, $gate, $workspaceId, $pathname);
                $pendingAction['ready_to_confirm'] = false;

                return $this->attachPendingAction(
                    $user,
                    [
                        'reply' => $this->actionExecutor->notReadyToConfirmMessage($pendingAction),
                        'message' => $this->actionExecutor->notReadyToConfirmMessage($pendingAction),
                        'tools_used' => [],
                        'active_workspace' => $scope['id'],
                    ],
                    $pendingAction,
                    $message,
                    $pathname,
                    $scope,
                );
            }

            return $this->executeConfirmedAction($user, $pendingAction, $workspaceId, $pathname);
        }

        if ($pendingAction && $this->actionExecutor->wantsFormUi($message)) {
            $pendingAction['show_form'] = true;
        }

        if ($pendingAction && $this->shouldExecuteConfirmedAction($pendingAction, $message)) {
            return $this->executeConfirmedAction($user, $pendingAction, $workspaceId, $pathname);
        }

        if (
            $pendingAction
            && $this->actionExecutor->isWriteAction((string) ($pendingAction['type'] ?? ''))
            && $this->actionExecutor->isConfirmation($message)
            && ! $this->actionExecutor->isReadyToConfirm($pendingAction)
        ) {
            $gate = $this->contextBuilder->gateForUser($user);
            $scope = $this->workspaceScope->resolve($user, $gate, $workspaceId, $pathname);
            $pendingAction['ready_to_confirm'] = false;

            return $this->attachPendingAction(
                $user,
                [
                    'reply' => $this->actionExecutor->notReadyToConfirmMessage($pendingAction),
                    'message' => $this->actionExecutor->notReadyToConfirmMessage($pendingAction),
                    'tools_used' => [],
                    'active_workspace' => $scope['id'],
                ],
                $pendingAction,
                $message,
                $pathname,
                $scope,
            );
        }

        if ($pendingAction && ($this->intentResolver->isCancelIntent($message) || $this->intentResolver->isDataQuestion($message))) {
            $pendingAction = null;
        }

        $normalizedRefs = \App\Support\EntityMentionRefs::normalize($entityRefs);

        // Create / write intents stay on the classic assistant (forms + confirm + POST).
        // Tool chat is for data Q&A — never use it while a pending write is being collected,
        // or "confirm" will get a chat reply instead of executing create_lpo / etc.
        $inferredCreate = $this->intentResolver->inferCreateAction($message, $history, $pathname);
        $pendingWrite = is_array($pendingAction)
            && $this->actionExecutor->isWriteAction((string) ($pendingAction['type'] ?? ''));
        $provider = strtolower((string) ($runtime['provider'] ?? config('ai.provider', 'openai')));
        $preferToolChat = ! $inferredCreate && ! $pendingWrite && (
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
                $normalizedRefs,
            );
            if (! empty($toolResult['success']) || ! empty($toolResult['declined_off_topic']) || ! empty($toolResult['declined_language'])) {
                return $toolResult;
            }

            Log::info('AI tool chat failed; falling back to classic assistant', [
                'provider' => $provider,
                'error_code' => $toolResult['error_code'] ?? null,
            ]);
        }

        $gate = $this->contextBuilder->gateForUser($user);
        $scope = $this->workspaceScope->resolve($user, $gate, $workspaceId, $pathname);

        if (! $this->languageGuard->isEnglishQuery($message)) {
            return [
                'reply' => $this->languageGuard->englishOnlyMessage(),
                'tools_used' => [],
                'declined_language' => true,
            ];
        }

        if (! $this->topicGuard->isErpRelated($message)) {
            return [
                'reply' => $this->topicGuard->declineMessage(),
                'tools_used' => [],
                'declined_off_topic' => true,
            ];
        }

        $inScope = $this->workspaceScope->isMessageInScope($message, $scope, $pendingAction);
        if (! $inScope && $this->isAlwaysAllowedWriteIntent($inferredCreate)) {
            $inScope = true;
        }
        if (! $inScope) {
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
        if ($normalizedRefs !== []) {
            $systemContext['resolved_entities'] = $normalizedRefs;
            $systemContext['resolved_entity_lines'] = \App\Support\EntityMentionRefs::contextLines($normalizedRefs);
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
                if (is_array($pendingAction) && $this->actionExecutor->isWriteAction((string) ($pendingAction['type'] ?? ''))) {
                    $rawReply = (string) ($pendingAction['type'] ?? '') === 'create_product'
                        ? $this->productParamMerger->statusReply($pendingAction, $productMergeNotes)
                        : $this->defaultReplyForPendingAction((string) ($pendingAction['type'] ?? ''), false);
                } else {
                    $rawReply = 'I could not generate a response. Please try rephrasing your question.';
                }
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
                    'params' => is_array($parsedAction['params'] ?? null) ? $parsedAction['params'] : [],
                ];
                // Keep previously collected fields when the model re-emits the same action type.
                if (
                    is_array($pendingAction)
                    && (string) ($pendingAction['type'] ?? '') !== ''
                    && (string) ($pendingAction['type'] ?? '') === (string) ($pending['type'] ?? '')
                ) {
                    $pending['params'] = array_merge(
                        is_array($pendingAction['params'] ?? null) ? $pendingAction['params'] : [],
                        $pending['params'],
                    );
                    if (empty($pending['summary']) && ! empty($pendingAction['summary'])) {
                        $pending['summary'] = $pendingAction['summary'];
                    }
                }
            } elseif ($pendingAction && $this->shouldContinuePendingAction($message, $history, $pathname)) {
                $pending = $pendingAction;
            } else {
                $inferred = $inferredCreate ?? $this->intentResolver->inferCreateAction($message, $history, $pathname);
                if ($inferred) {
                    $pending = $inferred;
                    if ($this->looksLikeFetchingReply($reply)) {
                        $result['reply'] = $this->isConversationalCreateAction($inferred)
                            ? 'Share the details in chat, or reply **show form** if you prefer a form.'
                            : 'Use the form below to complete the details. Options are loaded from your organization data.';
                        $result['message'] = $result['reply'];
                    }
                }
            }

            if ($pending) {
                if ((string) ($pending['type'] ?? '') === 'create_product') {
                    $pending = $this->productParamMerger->merge($user, $pending, $message, $history)['pending'];
                }
                $pending = $this->enrichPendingActionFromEntityRefs($pending, $normalizedRefs);
                $actionType = (string) ($pending['type'] ?? '');
                $allowedInWorkspace = $actionType !== '' && in_array($actionType, $scope['action_types'] ?? [], true);
                $alwaysAllowed = $this->isAlwaysAllowedWriteAction($actionType);
                if ($actionType !== '' && ! $allowedInWorkspace && ! $alwaysAllowed) {
                    $result['reply'] = $this->workspaceScope->declineMessage($scope);
                    $result['message'] = $result['reply'];
                } elseif ($actionType !== '' && ! $this->actionExecutor->canExecute($user, $actionType)) {
                    $result['reply'] = $this->actionExecutor->permissionDeclineMessage($actionType);
                    $result['message'] = $result['reply'];
                } elseif ($this->actionExecutor->isNavigationAction($actionType)) {
                    $result = $this->resolveNavigationAction($user, $result, $pending, $pathname);
                } elseif ($this->actionExecutor->isWriteAction($actionType)) {
                    $currentReply = (string) ($result['reply'] ?? $reply);
                    if ($this->looksLikeFetchingReply($currentReply) || $this->looksLikeWriteRefusal($currentReply)) {
                        $result['reply'] = $this->isConversationalCreateAction($pending)
                            ? $this->defaultReplyForPendingAction($actionType, false)
                            : 'Use the form below to complete the details. Options are loaded from your organization data.';
                        $result['message'] = $result['reply'];
                    }
                    // When ready and the user just confirmed, execute immediately (POST) instead of only echoing a draft.
                    if (
                        $this->actionExecutor->isConfirmation($message)
                        && $this->actionExecutor->isReadyToConfirm($pending)
                    ) {
                        return $this->executeConfirmedAction($user, $pending, $workspaceId, $pathname);
                    }
                    $result = $this->attachPendingAction($user, $result, $pending, $message, $pathname, $scope);
                }
                // Unknown action types: reply only — never show a Confirm strip.
            }

            if (! array_key_exists('pending_action', $result)) {
                $result['pending_action'] = null;
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
                'pending_action' => $pendingAction,
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
                'pending_action' => $pendingAction,
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

        if (! $this->languageGuard->isEnglishQuery($message)) {
            return [
                'reply' => $this->languageGuard->englishOnlyMessage(),
                'tools_used' => [],
                'declined_language' => true,
                'training_mode' => $trainingMode,
            ];
        }

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
                if (is_array($pendingAction) && $this->actionExecutor->isWriteAction((string) ($pendingAction['type'] ?? ''))) {
                    $rawReply = (string) ($pendingAction['type'] ?? '') === 'create_product'
                        ? $this->productParamMerger->statusReply($pendingAction)
                        : $this->defaultReplyForPendingAction((string) ($pendingAction['type'] ?? ''), false, $trainingMode);
                } else {
                    $rawReply = 'I could not generate a response. Please try rephrasing your question.';
                }
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
                        $result['reply'] = $this->isConversationalCreateAction($inferred)
                            ? 'Share the details in chat, or reply **show form** to preview fields (training mode).'
                            : 'Use the form below to preview the fields. Confirm is disabled in training mode.';
                        $result['message'] = $result['reply'];
                    }
                }
            }

            if ($pending) {
                $actionType = (string) ($pending['type'] ?? '');
                $allowedInWorkspace = $actionType !== '' && in_array($actionType, $scope['action_types'] ?? [], true);
                $alwaysAllowed = $this->isAlwaysAllowedWriteAction($actionType);
                if ($actionType !== '' && ! $allowedInWorkspace && ! $alwaysAllowed) {
                    $result['reply'] = $this->workspaceScope->declineMessage($scope);
                    $result['message'] = $result['reply'];
                } elseif ($this->actionExecutor->isNavigationAction($actionType)) {
                    $result = $this->resolveNavigationAction($user, $result, $pending, $pathname);
                } elseif ($this->actionExecutor->isWriteAction($actionType)) {
                    $currentReply = (string) ($result['reply'] ?? $reply);
                    if ($this->looksLikeFetchingReply($currentReply) || $this->looksLikeWriteRefusal($currentReply)) {
                        $result['reply'] = $this->isConversationalCreateAction($pending)
                            ? $this->defaultReplyForPendingAction($actionType, false, true)
                            : 'Use the form below to preview the fields. Confirm is disabled in training mode.';
                        $result['message'] = $result['reply'];
                    }
                    $contextUser = clone $user;
                    $contextUser->organization_id = $organization->id;
                    $contextUser->is_admin = true;
                    $result = $this->attachPendingAction(
                        $user,
                        $result,
                        $pending,
                        $message,
                        $pathname,
                        $scope,
                        $contextUser,
                        $trainingMode,
                    );
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
            $allowedInWorkspace = $actionType !== '' && in_array($actionType, $scope['action_types'] ?? [], true);
            if ($actionType !== '' && ! $allowedInWorkspace && ! $this->isAlwaysAllowedWriteAction($actionType)) {
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
            $docHint = '';
            if (! empty($result['document_links']) && is_array($result['document_links'])) {
                $docHint = ' Use Download PDF / Print in the chat panel when available.';
            }
            $reply = $this->replyFormatter->format(($outcome['message'] ?? 'Done.').$linkHint.$docHint);

            return [
                'reply' => $reply,
                'tools_used' => ['action_executor'],
                'action_result' => $outcome,
                'pending_action' => null,
                'form_spec' => null,
                'document_links' => is_array($result['document_links'] ?? null) ? $result['document_links'] : [],
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

    /** Model wrongly refuses a write action the product supports (e.g. create LPO / product). */
    protected function looksLikeWriteRefusal(string $reply): bool
    {
        if (preg_match(
            '/\b('
            .'can(?:not|\'t)|unable|not able|won\'t|will not|do not|don\'t|refuse|refusing|'
            .'not (?:allowed|supported|available)|outside (?:this|my) (?:scope|workspace)|'
            .'switch workspace|only help with'
            .')\b.{0,120}\b('
            .'creat|save|draft|make|raise|lpo|purchase order|product|write|perform that'
            .')/i',
            $reply,
        )) {
            return true;
        }

        // "I can't write products directly… Open /products"
        return (bool) preg_match(
            '/\b(open|go to|use|visit)\b.{0,40}\/(products|lpo|suppliers|customers)\b.{0,80}\b(save|enter|type|fill)\b/i',
            $reply,
        );
    }

    /** LPO create/workflow is allowed from any workspace when the user has permission. */
    protected function isAlwaysAllowedWriteAction(string $type): bool
    {
        return $type === 'create_lpo'
            || $type === 'open_lpo'
            || in_array($type, AiActionExecutor::lpoWorkflowActionTypes(), true);
    }

    /** @param  array<string, mixed>|null  $inferred */
    protected function isAlwaysAllowedWriteIntent(?array $inferred): bool
    {
        if (! is_array($inferred)) {
            return false;
        }

        return $this->isAlwaysAllowedWriteAction((string) ($inferred['type'] ?? ''));
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
Do not invent numbers for other modules — for live sales/stock/purchasing data outside this workspace, prefer tools or tell them to ask again after switching when needed.

Use entity_schemas in context — it lists every field, which are required, auto-generated, important, and FK relations (e.g. unit_id → uoms).
Use platform_knowledge as SAMPLE Q&A from platform admins (usage=exemplar). Each note shows the kind of thinking and reply shape to use for similar questions — NOT a canned answer to paste. Keep procedures, labels, and screen paths; write a fresh answer for THIS user; call live tools for current org data.
Use navigation, module_catalog, and available_actions to act as Centrix documentation.

INTERACTIVE FORMS: Select options are ALREADY in entity_detail / entity_schemas — never say you are fetching or ask the user to wait.

Image uploads are NOT supported — never ask for photos or images.

RULES:
1. Off-topic only for weather, recipes, trivia, unrelated coding → reply with DECLINE_OFF_TOPIC on its own line.
2. Navigation/help across modules is allowed. For most WRITE/create actions outside {$label} available_actions, suggest switching workspace — EXCEPT: you CAN and SHOULD create/save products, suppliers, customers, employees, sales orders, and purchase orders (LPOs) whenever those actions are in available_actions (and LPOs from any workspace). Never refuse create_product / create_lpo / create_supplier / create_customer or tell the user you cannot write/save them. Never say catalog creation must happen only on /products.
3. Use entity_schemas.field metadata: skip auto-generated fields unless user provides a value; use select options for FK fields.
4. Normal orders = create_sales_order; held/save-only = create_held_order only when explicitly requested.
5. Platform administrators train ERP-wide sample Q&A under Platform → AI training; use them as exemplars of how to respond (not verbatim quotes).
6. PERMISSIONS — read user_access in context:
   - user.is_admin=true or user_access.has_full_permissions=true → user has ALL permissions; never say they lack access.
   - Answer read-only questions using *_summary data in context when present.
   - Only decline WRITE actions the user lacks permission for (not merely because of workspace).
7. Always include clickable Centrix paths like /inventory/stock when telling users where to go.
8. Only cite paths from navigation / workflows / find_screen — never invent menu paths.
9. Names only in replies: product_name (never product_code/SKU), customer_name (never customer_num), supplier_name (never id/code), people by full name and username (never numeric user/employee id).
10. Formulas: plain text with real field names (Stock Value = Cost Price × Stock on Hand). Never LaTeX.
11. Markdown headings (# ## ### ####) are fine; the UI renders them as real headings.
12. Always reply in English. If a user writes in another language (e.g. Swahili), do not answer the ERP question — tell them Centrix AI expects questions in English only.
13. Custom report builder: ask what to name the report, then emit create_report_template with name + instruction (or wait for confirmation). After save, give /reports/custom/{id}.
14. Focus on the user's meaning, not punctuation or stray symbols (trailing ?, /, !, …). "…create an lpo for me /" means the same as with "?".
15. Product/sales tables: Product | Qty | Amount — no Code column.
16. Typos / spelling: interpret meaning despite misspellings (e.g. "anomally" → anomaly / abnormal sales, "lpo" / "purchase oder"). Do not lecture about grammar; answer the intended ERP question.
17. Abnormal / unusual / anomaly sales this week (or lookback ~7 days): treat as sales anomaly detection — help the user review unusual large orders, after-hours sales, multi-branch spikes, and deep discounts. Never say you cannot check for anomalies.
18. Creates (product, supplier, customer, employee, sales order, LPO): collect required details in chat, then when the user replies confirm / save / create it, emit a complete ```action``` block with params so the system POSTs and saves. Never tell them to open /products, /lpo, /suppliers, or /customers and type the record themselves after they asked you to save.
19. User-facing wording: never say "backend" to the user. Say "Backoffice" (sales channel `backend`/`erp` = Backoffice). Internal module keys like sales.backend stay internal only.

```action
{"type":"create_product","summary":"New product Widget","params":{"product_name":"Widget","unit_price":150,"last_cost_price":140,"subcategory_id":1,"unit_id":1,"vat_id":1}}
```

For all create / write actions (product, supplier, customer, LPO, sales order, employee, payment, LPO approve/send/receive, etc.): ask for required details in chat first. Do NOT mention or show an inline form until the user replies **show form** (or similar). Offer the form as an option — never show both a field checklist and the form on the same turn. Only ask them to reply **confirm** / **save** / **create it** after required fields are collected — never on the first “help me create…” turn. When confirming, always emit a complete ```action``` block (create_product, create_lpo, etc.) with the collected params so the system can save — do not send the user to a screen to re-enter data.
PROMPT;
    }

    /** @param  array<int, array{role: string, content: string}>  $history */
    protected function shouldContinuePendingAction(string $message, array $history, ?string $pathname): bool
    {
        if ($this->intentResolver->isCancelIntent($message) || $this->intentResolver->isDataQuestion($message)) {
            return false;
        }

        if ($this->actionExecutor->isConfirmation($message) || $this->actionExecutor->wantsFormUi($message)) {
            return true;
        }

        if ($this->productParamMerger->looksLikeFieldFollowUp($message)) {
            return true;
        }

        if ($this->intentResolver->inferCreateAction($message, $history, $pathname) !== null) {
            return true;
        }

        return (bool) preg_match(
            '/\b(subcategory|supplier|customer|employee|unit|price|sku|barcode|vat|reorder|product\s+name|named|called|line\s*items?|ordered_qty|qty|quantity|cost\s*price|due\s*date|delivery|reference|terms|order_num|lpo|purchase\s+order|first\s+name|last\s+name|contact|phone|email|payment|amount|report\s+name)\b/i',
            $message,
        );
    }

    /** @param  array<string, mixed>  $pending */
    protected function isConversationalCreateAction(array $pending): bool
    {
        $type = (string) ($pending['type'] ?? '');
        if ($type === '') {
            return false;
        }

        if (in_array($type, config('ai.immediate_form_create_actions', []), true)) {
            return false;
        }

        return str_starts_with($type, 'create_')
            || $type === 'record_customer_payment'
            || in_array($type, AiActionExecutor::lpoWorkflowActionTypes(), true);
    }

    /** @param  array<string, mixed>  $pending */
    protected function shouldExecuteConfirmedAction(array $pending, string $message): bool
    {
        if (! $this->actionExecutor->isConfirmation($message)) {
            return false;
        }

        if (! $this->actionExecutor->isReadyToConfirm($pending)) {
            return false;
        }

        if ($this->isConversationalCreateAction($pending)
            && preg_match('/^(yes|yeah|yep|ok|okay)\s*[.!]?$/i', trim($message))) {
            return false;
        }

        return true;
    }

    /** @param  array<string, mixed>  $pending */
    protected function shouldAttachFormSpec(array $pending, string $message): bool
    {
        if (! $this->actionExecutor->isWriteAction((string) ($pending['type'] ?? ''))) {
            return false;
        }

        if (! $this->isConversationalCreateAction($pending)) {
            return true;
        }

        return ! empty($pending['show_form']) || $this->actionExecutor->wantsFormUi($message);
    }

    /**
     * Navigation deep links open immediately — no Confirm / Ready to create UI.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $pending
     * @return array<string, mixed>
     */
    protected function resolveNavigationAction(
        User $user,
        array $result,
        array $pending,
        ?string $pathname = null,
    ): array {
        $outcome = $this->actionExecutor->execute($user, $pending);
        $nav = is_array($outcome['result'] ?? null) ? $outcome['result'] : [];
        $path = (string) ($nav['path'] ?? $nav['href'] ?? '');

        $result['pending_action'] = null;
        $result['form_spec'] = null;
        $result['action_result'] = $outcome;

        if ($path !== '') {
            $reply = trim((string) ($result['reply'] ?? ''));
            $linkLine = "Open: {$path}";
            if ($reply === '' || ! str_contains($reply, $path)) {
                $result['reply'] = $reply !== ''
                    ? rtrim($reply)."\n\n{$linkLine}"
                    : ($outcome['message'] ?? $linkLine);
                $result['message'] = $result['reply'];
            }
        }

        return $result;
    }

    protected function defaultReplyForPendingAction(string $actionType, bool $hasForm, bool $trainingMode = false): string
    {
        if ($hasForm) {
            if ($trainingMode) {
                return 'Preview the form below. Training mode does not execute actions against tenant data.';
            }

            return match ($actionType) {
                'record_customer_payment' => 'Fill in the form below, then click Confirm & record payment.',
                'create_lpo' => 'Fill in the form below, then click Confirm & create LPO.',
                'submit_lpo_for_approval' => 'Confirm below to submit this LPO for approval.',
                'approve_lpo' => 'Confirm below to approve this LPO.',
                'mark_lpo_sent' => 'Confirm below to mark this LPO as sent.',
                'receive_lpo_goods' => 'Confirm below to receive goods against this LPO.',
                default => 'Fill in the form below, then click Confirm & create.',
            };
        }

        return match ($actionType) {
            'create_product' => 'Share the product name (and subcategory / UoM / VAT / prices if you have them). Reply **confirm** or **save** when ready — I will create it. Or reply **show form** for a form.',
            'create_supplier' => 'Share the supplier name and contact details here in chat. Reply **confirm** or **save** when ready, or **show form** for a form.',
            'create_customer' => 'Share the customer details here in chat. Reply **confirm** or **save** when ready, or **show form** for a form.',
            'create_employee' => 'Share the employee details here in chat. Reply **confirm** or **save** when ready, or **show form** for a form.',
            'create_sales_order', 'create_held_order' => 'Share the customer and line items here in chat. Reply **confirm** or **save** when ready, or **show form** for a form.',
            'record_customer_payment' => 'Share the order and payment details here in chat. Reply **confirm** or **save** when ready, or **show form** for a form.',
            'create_report_template' => 'Share the report name and what it should show here in chat. Reply **confirm** or **save** when ready, or **show form** for a form.',
            'create_lpo' => 'Share the supplier and line items here in chat. Reply **confirm** or **save** when ready — I will create the LPO. Or reply **show form** for a form.',
            'submit_lpo_for_approval' => 'Share the LPO number if needed, then reply **confirm** to submit it for approval.',
            'approve_lpo' => 'Share the LPO number if needed, then reply **confirm** to approve it.',
            'mark_lpo_sent' => 'Share the LPO number if needed, then reply **confirm** to mark it as sent. You can also download the PDF to share with the supplier.',
            'receive_lpo_goods' => 'Share the LPO number if needed, then reply **confirm** to receive remaining quantities into stock.',
            default => 'Share the details here in chat, or reply **show form** if you prefer a form.',
        };
    }

    /**
     * Fold @-mention refs into pending write params (supplier / product lines for LPO, etc.).
     *
     * @param  array<string, mixed>  $pending
     * @param  list<array{type: string, id: ?string, code: ?string, label: string}>  $refs
     * @return array<string, mixed>
     */
    protected function enrichPendingActionFromEntityRefs(array $pending, array $refs): array
    {
        if ($refs === []) {
            return $pending;
        }

        $type = (string) ($pending['type'] ?? '');
        $params = is_array($pending['params'] ?? null) ? $pending['params'] : [];

        if ($type === 'create_lpo') {
            $supplierIds = \App\Support\EntityMentionRefs::supplierIds($refs);
            if ((int) ($params['supplier_id'] ?? 0) <= 0 && $supplierIds !== []) {
                $params['supplier_id'] = (int) $supplierIds[0];
            }

            $productCodes = \App\Support\EntityMentionRefs::productCodes($refs);
            $lines = is_array($params['lines'] ?? null) ? $params['lines'] : [];
            $existingCodes = [];
            foreach ($lines as $line) {
                if (is_array($line) && trim((string) ($line['product_code'] ?? '')) !== '') {
                    $existingCodes[strtoupper(trim((string) $line['product_code']))] = true;
                }
            }
            foreach ($productCodes as $code) {
                $key = strtoupper(trim((string) $code));
                if ($key === '' || isset($existingCodes[$key])) {
                    continue;
                }
                $lines[] = [
                    'product_code' => $code,
                    'ordered_qty' => 1,
                ];
                $existingCodes[$key] = true;
            }
            if ($lines !== []) {
                $params['lines'] = $lines;
            }
        }

        if (in_array($type, ['create_supplier'], true)) {
            foreach ($refs as $ref) {
                if (($ref['type'] ?? '') === 'supplier' && trim((string) ($params['supplier_name'] ?? '')) === '') {
                    $params['supplier_name'] = (string) ($ref['label'] ?? '');
                    break;
                }
            }
        }

        if (in_array($type, ['create_customer'], true)) {
            foreach ($refs as $ref) {
                if (($ref['type'] ?? '') === 'customer' && trim((string) ($params['customer_name'] ?? '')) === '') {
                    $params['customer_name'] = (string) ($ref['label'] ?? '');
                    break;
                }
            }
        }

        $pending['params'] = $params;

        return $pending;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $pending
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    protected function attachPendingAction(
        User $user,
        array $result,
        array $pending,
        string $message,
        ?string $pathname,
        array $scope,
        ?User $formUser = null,
        bool $trainingMode = false,
    ): array {
        if ($this->actionExecutor->wantsFormUi($message)) {
            $pending['show_form'] = true;
        }

        $pending['ready_to_confirm'] = $this->actionExecutor->isReadyToConfirm($pending);
        $result['pending_action'] = $pending;

        if ($this->shouldAttachFormSpec($pending, $message)) {
            $result['form_spec'] = $this->formSpecBuilder->forAction($formUser ?? $user, $pending, $pathname);
        } else {
            $result['form_spec'] = null;
        }

        if (empty(trim((string) ($result['reply'] ?? '')))) {
            $result['reply'] = $this->defaultReplyForPendingAction(
                (string) ($pending['type'] ?? ''),
                ! empty($result['form_spec']),
                $trainingMode,
            );
            $result['message'] = $result['reply'];
        }

        return $result;
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

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @param  array<string, mixed>|null  $pendingAction
     * @return \Generator<int, array<string, mixed>>
     */
    public function chatStream(
        User $user,
        string $message,
        array $history = [],
        ?array $pendingAction = null,
        bool $confirmAction = false,
        ?string $workspaceId = null,
        ?string $pathname = null,
        ?string $conversationId = null,
        ?array $pageContext = null,
        ?array $entityRefs = null,
    ): \Generator {
        $teachResult = $this->tryCaptureUserTeaching($user, $message);
        if ($teachResult) {
            $content = (string) ($teachResult['reply'] ?? $teachResult['message'] ?? '');
            if ($content !== '') {
                yield ['event' => 'delta', 'content' => $content];
            }
            yield ['event' => 'done'] + $teachResult;

            return;
        }

        if ($this->helpGuide->isHelpRequest($message) && ! $pendingAction && ! $confirmAction) {
            $result = $this->chat(
                $user,
                $message,
                $history,
                null,
                false,
                $workspaceId,
                $pathname,
                $conversationId,
                $pageContext,
                $entityRefs,
            );
            $content = (string) ($result['message'] ?? $result['reply'] ?? '');
            if ($content !== '') {
                yield ['event' => 'delta', 'content' => $content];
            }
            yield ['event' => 'done'] + $result;

            return;
        }

        if ($confirmAction || $pendingAction) {
            $result = $this->chat(
                $user,
                $message,
                $history,
                $pendingAction,
                $confirmAction,
                $workspaceId,
                $pathname,
                $conversationId,
                $pageContext,
                $entityRefs,
            );
            $content = (string) ($result['message'] ?? $result['reply'] ?? '');
            if ($content !== '') {
                yield ['event' => 'delta', 'content' => $content];
            }
            yield ['event' => 'done'] + $result;

            return;
        }

        $normalizedRefs = \App\Support\EntityMentionRefs::normalize($entityRefs);
        $inferredCreate = $this->intentResolver->inferCreateAction($message, $history, $pathname);
        $runtime = AiSettingsResolver::resolveRuntime($user);
        $provider = strtolower((string) ($runtime['provider'] ?? config('ai.provider', 'openai')));
        $preferToolChat = ! $inferredCreate && (
            $provider === 'gemini'
            || ($provider === 'openai' && filter_var(config('ai.use_tool_chat', false), FILTER_VALIDATE_BOOLEAN))
        );

        if ($preferToolChat) {
            yield from $this->toolChat->chatStream(
                $user,
                $message,
                $conversationId,
                $history,
                $workspaceId,
                $pathname,
                $pageContext,
                $normalizedRefs,
            );

            return;
        }

        $result = $this->chat(
            $user,
            $message,
            $history,
            null,
            false,
            $workspaceId,
            $pathname,
            $conversationId,
            $pageContext,
            $entityRefs,
        );
        $content = (string) ($result['message'] ?? $result['reply'] ?? '');
        if ($content !== '') {
            yield ['event' => 'delta', 'content' => $content];
        }
        yield ['event' => 'done'] + $result;
    }
}
