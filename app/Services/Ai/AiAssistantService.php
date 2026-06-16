<?php

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AiAssistantService
{
    public function __construct(
        protected AiSystemContextBuilder $contextBuilder,
        protected AiTopicGuard $topicGuard,
        protected AiActionExecutor $actionExecutor,
        protected AiFormSpecBuilder $formSpecBuilder,
        protected AiKnowledgeService $knowledge,
        protected AiPageExplorer $pageExplorer,
        protected AiIntentResolver $intentResolver,
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
    ): array {
        $teachResult = $this->tryCaptureUserTeaching($user, $message);
        if ($teachResult) {
            return $teachResult;
        }

        $runtime = AiSettingsResolver::resolveRuntime($user);
        if (! $runtime) {
            $settings = AiSettingsResolver::forUser($user);

            return [
                'reply' => ! ($settings['enabled'] ?? false)
                    ? 'AI assistant is disabled for this organization. An admin can enable it under Administration → Settings → AI.'
                    : 'AI assistant is not configured for this organization. An admin must add an OpenAI API key under Administration → Settings → AI.',
                'tools_used' => [],
            ];
        }

        if ($confirmAction && $pendingAction) {
            return $this->executeConfirmedAction($user, $pendingAction);
        }

        if ($pendingAction && $this->actionExecutor->isConfirmation($message)) {
            return $this->executeConfirmedAction($user, $pendingAction);
        }

        if (! $this->topicGuard->isErpRelated($message)) {
            return [
                'reply' => $this->topicGuard->declineMessage(),
                'tools_used' => [],
                'declined_off_topic' => true,
            ];
        }

        $systemContext = $this->contextBuilder->build($user, $message);
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
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
            $response = Http::withToken($runtime['api_key'])
                ->timeout(60)
                ->post($runtime['base_url'].'/chat/completions', [
                    'model' => $runtime['model'],
                    'messages' => $messages,
                    'max_tokens' => config('ai.defaults.max_tokens'),
                    'temperature' => 0.25,
                ]);

            if (! $response->successful()) {
                Log::warning('AI chat failed', ['status' => $response->status(), 'body' => $response->body()]);

                return [
                    'reply' => $this->formatApiFailure(
                        $response->status(),
                        $response->json('error.message') ?? $response->body(),
                    ),
                    'tools_used' => array_keys(array_diff_key($systemContext, array_flip(['organization', 'user']))),
                    'error_code' => $response->json('error.code') ?? (string) $response->status(),
                ];
            }

            $rawReply = trim($response->json('choices.0.message.content') ?? '');
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
            $reply = AiActionExecutor::stripActionBlock($rawReply);

            $result = [
                'reply' => $reply,
                'tools_used' => array_keys(array_diff_key(
                    $systemContext,
                    array_flip(['organization', 'user', 'enabled_modules']),
                )),
                'data' => $systemContext,
            ];

            $pending = null;
            if ($parsedAction) {
                $pending = [
                    'type' => $parsedAction['type'] ?? null,
                    'summary' => $parsedAction['summary'] ?? ($parsedAction['label'] ?? 'Proposed action'),
                    'params' => $parsedAction['params'] ?? [],
                ];
            } elseif ($pendingAction) {
                $pending = $pendingAction;
            } else {
                $inferred = $this->intentResolver->inferCreateAction($message, $history);
                if ($inferred) {
                    $pending = $inferred;
                    if ($this->looksLikeFetchingReply($reply)) {
                        $result['reply'] = 'Use the form below to complete the details. Options are loaded from your organization data.';
                    }
                }
            }

            if ($pending) {
                $actionType = (string) ($pending['type'] ?? '');
                if ($actionType !== '' && ! $this->actionExecutor->canExecute($user, $actionType)) {
                    $result['reply'] = $this->actionExecutor->permissionDeclineMessage($actionType);
                    unset($pending);
                } else {
                    $result['pending_action'] = $pending;
                    $result['form_spec'] = $this->formSpecBuilder->forAction($user, $pending);
                    if (empty(trim($result['reply'] ?? ''))) {
                        $result['reply'] = $actionType === 'record_customer_payment'
                            ? 'Fill in the form below, then click Confirm & record payment.'
                            : 'Fill in the form below, then click Confirm & create.';
                    }
                }
            }

            return $result;
        } catch (ConnectionException $e) {
            Log::error('AI chat connection failed', ['message' => $e->getMessage()]);

            return [
                'reply' => 'Could not connect to the AI provider. Check network access and the base URL in Admin → Settings → AI.',
                'tools_used' => [],
                'error_code' => 'connection_failed',
            ];
        } catch (\Throwable $e) {
            Log::error('AI chat exception', [
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return [
                'reply' => $this->formatInternalFailure($e),
                'tools_used' => [],
                'error_code' => 'internal_error',
            ];
        }
    }

    /** @param  array<string, mixed>  $pendingAction
     * @return array<string, mixed>
     */
    protected function executeConfirmedAction(User $user, array $pendingAction): array
    {
        try {
            $outcome = $this->actionExecutor->execute($user, $pendingAction);
            $result = $outcome['result'] ?? [];
            $path = $result['path'] ?? null;
            $linkHint = $path ? " Open: {$path}" : '';

            return [
                'reply' => ($outcome['message'] ?? 'Done.').$linkHint,
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
                'form_spec' => $this->formSpecBuilder->forAction($user, $pendingAction),
            ];
        } catch (ModelNotFoundException $e) {
            Log::error('AI action model not found', ['message' => $e->getMessage()]);

            return [
                'reply' => 'A referenced record was not found. Check product codes, customer numbers, and other selections, then try again.',
                'tools_used' => ['action_executor'],
                'action_error' => true,
                'pending_action' => $pendingAction,
                'form_spec' => $this->formSpecBuilder->forAction($user, $pendingAction),
            ];
        } catch (\Throwable $e) {
            Log::error('AI action failed', ['message' => $e->getMessage()]);

            return [
                'reply' => 'The action could not be completed: '.$e->getMessage(),
                'tools_used' => ['action_executor'],
                'action_error' => true,
                'pending_action' => $pendingAction,
                'form_spec' => $this->formSpecBuilder->forAction($user, $pendingAction),
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

        $entry = $this->knowledge->teach($user, 'User note', $content);

        return [
            'reply' => 'Got it — I saved that for your organization and will use it in future answers.',
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
                : 'I analyzed this screen. Please confirm to save what I learned for your organization.',
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

    protected function formatApiFailure(int $status, ?string $providerMessage): string
    {
        $detail = trim((string) $providerMessage);
        if (strlen($detail) > 240) {
            $detail = substr($detail, 0, 237).'…';
        }

        return match ($status) {
            401 => 'OpenAI rejected the API key (401). Verify the API key in Admin → Settings → AI.'
                .($detail ? " Provider: {$detail}" : ''),
            429 => 'OpenAI quota exceeded (429). Check billing on your OpenAI account.'
                .($detail ? " — {$detail}" : ''),
            default => 'AI request failed (HTTP '.$status.').'.($detail ? " {$detail}" : ''),
        };
    }

    protected function looksLikeFetchingReply(string $reply): bool
    {
        return (bool) preg_match('/\b(fetch|hold on|please wait|moment|loading|retrieve|look up)\b/i', $reply);
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
You are the in-app assistant for a Kenya-focused POS/ERP system (currency KES). You help users with EVERY module in this system.

Use entity_schemas in context — it lists every field, which are required, auto-generated, important, and FK relations (e.g. unit_id → uoms).
Use organization_knowledge for facts users or confirmed page exploration have taught you.

IN SCOPE: products, sales, inventory, purchasing, accounting, HR, logistics, reports, admin settings.
Image uploads are NOT supported — never ask for photos or images.

RULES:
1. Off-topic only for weather, recipes, trivia, unrelated coding → reply with DECLINE_OFF_TOPIC on its own line.
2. Use entity_schemas.field metadata: skip auto-generated fields unless user provides a value; use select options for FK fields.
3. Normal orders = create_sales_order; held/save-only = create_held_order only when explicitly requested.
4. When unsure about a screen/module, propose learning it:
```learn
{"path":"/products","reason":"Need product form field details"}
```
The user must confirm before saved knowledge is used.
5. Users can teach you with "Remember that …" or POST /ai/teach.
6. PERMISSIONS — read user_access in context:
   - user.is_admin=true or user_access.has_full_permissions=true → user has ALL permissions; never say they lack access or tell them to contact an administrator.
   - Answer read-only questions (sales totals, stock, debtors, etc.) using *_summary data in context. If sales_summary, product_summary, or receivables_summary is present, USE IT — do not refuse or claim you need reports module access.
   - Only decline when the user requests a WRITE action (create/update/delete) that is NOT in available_actions.
   - user_access.modules shows org_module_enabled vs user_has_permission — a disabled org module is not the same as missing user permission.

DEBTORS & PAYMENTS:
- Use receivables_summary (top_debtors, open_invoices) to analyze who owes money and outstanding balances.
- To record payment use record_customer_payment with sale_id (from open_invoices), payment_method_id, and optional amount.
- Omit amount or set mark_paid_full to pay the full outstanding balance; provide amount for partial payments.
- Reports: /reports/top-debtors, /accounting/accounts-receivable, customer statements at /customers/{customerNum}/statement.

INTERACTIVE FORMS: Select options (subcategories, UOMs, VAT, customers, open invoices, payment methods, etc.) are ALREADY in entity_detail / entity_schemas — never say you are fetching or ask the user to wait. Immediately append the action block; the API builds the form with live dropdown options.

```action
{"type":"create_product","summary":"New product Widget","params":{"product_name":"Widget","unit_price":150}}
```

Action types:
- create_sales_order: customer_num + lines [{product_code, quantity}]; optional payment_method_code, channel.
- create_held_order: save-only — only when user wants hold/defer payment.
- create_product: product_name required; product_code auto-generated if omitted; unit_id/subcategory_id/vat_id from selects.
- create_employee: first_name, last_name required; employee_code auto-generated if omitted.
- create_report_template: name + spec.
- record_customer_payment: sale_id (outstanding order), payment_method_id required; amount optional (full balance if omitted).

Tell users to fill the form and confirm, or reply "confirm" when params are complete.
PROMPT;
    }
}
