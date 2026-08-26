<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Ai\AiProviderException;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiCredentialTestService;
use App\Services\Ai\AiKnowledgeService;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\AiSettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlatformAiTrainingController extends Controller
{
    public function __construct(
        protected AiKnowledgeService $knowledge,
        protected AiAssistantService $ai,
        protected AiProviderFactory $providers,
        protected AiCredentialTestService $credentialTest,
    ) {}

    public function status(Request $request)
    {
        $data = $request->validate([
            'preview_organization_id' => 'nullable|integer|exists:organizations,id',
        ]);

        $preview = $this->resolvePreviewOrganization($data['preview_organization_id'] ?? null);
        $training = AiSettingsResolver::describePlatformTraining();

        return response()->json([
            'scope' => 'platform',
            'knowledge_count' => count($this->knowledge->listGlobal()),
            'preview_organization_id' => $preview?->id,
            'preview_organization_name' => $preview?->org_name,
            'enabled' => (bool) $training['available'],
            'chat_ready' => (bool) $training['available'] && $preview !== null,
            'platform_training_enabled' => (bool) ($training['settings']['enabled'] ?? false),
            'api_key_set' => (bool) ($training['settings']['api_key_set'] ?? false),
            'model' => $training['model'] ?? config('ai.defaults.model'),
            'training_mode' => true,
        ]);
    }

    public function settings()
    {
        return response()->json(AiSettingsResolver::describePlatformTraining());
    }

    public function usage(Request $request, \App\Services\Ai\AiUsageAnalyticsService $analytics)
    {
        return response()->json($analytics->platformSummary($this->usageFilters($request)));
    }

    public function usageEvents(Request $request, \App\Services\Ai\AiUsageAnalyticsService $analytics)
    {
        $data = $this->usageFilters($request);
        $data['page'] = $request->integer('page', 1);
        $data['per_page'] = $request->integer('per_page', 25);

        return response()->json($analytics->platformEvents($data));
    }

    public function usageCommonQuestions(Request $request, \App\Services\Ai\AiUsageAnalyticsService $analytics)
    {
        $data = $this->usageFilters($request);
        $data['limit'] = $request->integer('limit', 20);

        return response()->json([
            'available' => true,
            'data' => $analytics->platformCommonQuestions($data),
        ]);
    }

    /**
     * Draft (and optionally save) a platform knowledge note from a frequent usage question.
     */
    public function trainFromUsage(Request $request)
    {
        $data = $request->validate([
            'question' => 'required|string|max:500',
            'examples' => 'nullable|array|max:5',
            'examples.*' => 'string|max:500',
            'count' => 'nullable|integer|min:1',
            'workspace_id' => 'nullable|string|max:40|in:'.implode(',', config('ai.workspace_ids')),
            'save' => 'sometimes|boolean',
        ]);

        $runtime = \App\Services\Ai\AiSettingsResolver::resolveRuntimeForPlatformTraining();
        if (! $runtime || empty($runtime['api_key'])) {
            return response()->json([
                'message' => 'No Gemini or OpenAI key is saved for platform tools. Add a Gemini key under Platform → AI training → Credentials (the same key used for tenant free AI).',
            ], 422);
        }

        $question = trim((string) $data['question']);
        $examples = array_values(array_filter(
            array_map(fn ($row) => trim((string) $row), $data['examples'] ?? []),
            fn ($row) => $row !== '',
        ));
        $workspaceIds = implode(', ', config('ai.workspace_ids', []));
        $exampleBlock = $examples !== []
            ? "Similar phrasings:\n- ".implode("\n- ", $examples)."\n"
            : '';
        $count = (int) ($data['count'] ?? 1);
        $hint = $data['workspace_id'] ?? '';

        $system = 'You write Centrix ERP training notes for the in-app AI assistant. '
            .'The note must teach how to answer this user question inside Centrix, including the real screen path. '
            .'Hotel & Hospitality (rooms, folios, hotel POS checks, F&B) is a first-class industry — do not assume retail-only. '
            .'Return JSON only: {"topic":"...","content":"...","path":"/...","workspace_id":"...or null"}. '
            .'workspace_id must be one of: '.$workspaceIds.', or null for all workspaces. '
            .'content should be 2–6 short sentences, Kenya business English, no markdown headings.';

        $userPrompt = "Users asked this {$count} time(s):\n{$question}\n{$exampleBlock}"
            .($hint !== '' ? "Suggested workspace: {$hint}\n" : '')
            ."Write a durable training note so the assistant answers this correctly next time.";

        try {
            $turn = $this->providers->make($runtime)->chat([
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.2,
                'max_output_tokens' => 800,
            ]);
        } catch (AiProviderException $e) {
            return response()->json(['message' => 'Could not draft training note: '.$e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not draft training note: '.$e->getMessage()], 422);
        }

        $raw = trim((string) ($turn['text'] ?? ''));
        $parsed = json_decode($raw, true);
        if (! is_array($parsed) && preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $parsed = json_decode($m[0], true);
        }
        if (! is_array($parsed)) {
            $parsed = [];
        }

        $allowed = config('ai.workspace_ids', []);
        $workspaceId = $parsed['workspace_id'] ?? $data['workspace_id'] ?? null;
        if (is_string($workspaceId) && $workspaceId !== '' && ! in_array($workspaceId, $allowed, true)) {
            $workspaceId = $data['workspace_id'] ?? null;
        }
        if ($workspaceId === '' || $workspaceId === 'null') {
            $workspaceId = null;
        }

        $draft = [
            'topic' => Str::limit(trim((string) ($parsed['topic'] ?? $question)), 200, ''),
            'content' => trim((string) ($parsed['content'] ?? $parsed['body'] ?? $raw)),
            'path' => trim((string) ($parsed['path'] ?? '')),
            'workspace_id' => $workspaceId,
        ];
        if ($draft['topic'] === '') {
            $draft['topic'] = Str::limit($question, 200, '');
        }
        if ($draft['content'] === '') {
            return response()->json(['message' => 'The model did not return a usable training note. Try again.'], 422);
        }

        $saved = null;
        if (! empty($data['save'])) {
            $saved = $this->knowledge->teachGlobal(
                $request->user(),
                $draft['topic'],
                Str::limit($draft['content'], 8000, ''),
                $draft['path'] !== '' ? $draft['path'] : null,
                $draft['workspace_id'],
                'usage_training',
            );
        }

        return response()->json([
            'draft' => $draft,
            'saved' => $saved,
        ], $saved ? 201 : 200);
    }

    /**
     * @return array{from: ?string, to: ?string, organization_id: ?int, user_id: ?int, provider: ?string}
     */
    protected function usageFilters(Request $request): array
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'provider' => 'nullable|string|max:40',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return [
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'organization_id' => isset($data['organization_id']) ? (int) $data['organization_id'] : null,
            'user_id' => isset($data['user_id']) ? (int) $data['user_id'] : null,
            'provider' => isset($data['provider']) && $data['provider'] !== '' ? $data['provider'] : null,
        ];
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'provider' => 'sometimes|in:openai,gemini',
            'model' => 'sometimes|nullable|string|max:80',
            'api_key' => 'sometimes|nullable|string|max:512',
            'base_url' => 'sometimes|nullable|string|max:500',
            'gemini_api_key' => 'sometimes|nullable|string|max:512',
            'gemini_model' => 'sometimes|nullable|string|max:80',
            'gemini_base_url' => 'sometimes|nullable|string|max:500',
            'free_ai_provider' => 'sometimes|in:gemini,openai',
        ]);

        return response()->json(AiSettingsResolver::savePlatformTraining($data));
    }

    /**
     * Live connectivity check for platform Gemini / OpenAI credentials (saved or draft from the form).
     */
    public function testCredentials(Request $request)
    {
        $data = $request->validate([
            'provider' => 'sometimes|in:gemini,openai',
            'gemini_api_key' => 'sometimes|nullable|string|max:512',
            'gemini_model' => 'sometimes|nullable|string|max:80',
            'api_key' => 'sometimes|nullable|string|max:512',
            'model' => 'sometimes|nullable|string|max:80',
            'base_url' => 'sometimes|nullable|string|max:500',
        ]);

        $provider = strtolower(trim((string) ($data['provider'] ?? AiSettingsResolver::platformFreeAiProvider())));
        if (! in_array($provider, ['gemini', 'openai'], true)) {
            $provider = 'gemini';
        }

        $result = $this->credentialTest->testForPlatform($provider, $data);

        return response()->json($result['body'], $result['status']);
    }

    public function listKnowledge(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => 'nullable|string|max:40|in:'.implode(',', config('ai.workspace_ids')),
            'limit' => 'nullable|integer|min:1|max:5000',
        ]);

        return response()->json([
            'scope' => 'platform',
            'data' => $this->knowledge->listGlobal(
                $data['workspace_id'] ?? null,
                (int) ($data['limit'] ?? 2000),
            ),
        ]);
    }

    public function teach(Request $request)
    {
        $data = $request->validate([
            'topic' => 'nullable|string|max:200',
            'question' => 'nullable|string|max:200',
            'content' => 'nullable|string|max:8000',
            'answer' => 'nullable|string|max:8000',
            'path' => 'nullable|string|max:200',
            'workspace_id' => 'nullable|string|max:40|in:'.implode(',', config('ai.workspace_ids')),
        ]);

        $topic = trim((string) ($data['topic'] ?? $data['question'] ?? ''));
        $content = trim((string) ($data['content'] ?? $data['answer'] ?? ''));
        if ($topic === '' || $content === '') {
            throw ValidationException::withMessages([
                'topic' => ['Provide a topic/question and content/answer.'],
            ]);
        }

        $entry = $this->knowledge->teachGlobal(
            $request->user(),
            $topic,
            $content,
            $data['path'] ?? null,
            $data['workspace_id'] ?? null,
        );

        return response()->json($entry, 201);
    }

    /**
     * Bulk-import Q&A training notes (platform-wide).
     */
    public function teachBulk(Request $request)
    {
        $data = $request->validate([
            'notes' => 'required|array|min:1|max:500',
            'notes.*.topic' => 'nullable|string|max:200',
            'notes.*.question' => 'nullable|string|max:200',
            'notes.*.content' => 'nullable|string|max:8000',
            'notes.*.answer' => 'nullable|string|max:8000',
            'notes.*.path' => 'nullable|string|max:200',
            'notes.*.workspace_id' => 'nullable|string|max:40|in:'.implode(',', config('ai.workspace_ids')),
        ]);

        $result = $this->knowledge->teachGlobalBulk($request->user(), $data['notes'], 'platform_bulk');

        return response()->json($result, 201);
    }

    /**
     * Install curated Centrix foundation notes (UoM, retail, navigation, etc.).
     */
    public function installFoundation(Request $request)
    {
        $result = $this->knowledge->installFoundationNotes($request->user());

        return response()->json($result);
    }

    public function updateKnowledge(Request $request, int $entry)
    {
        $data = $request->validate([
            'topic' => 'sometimes|required|string|max:200',
            'content' => 'sometimes|required|string|max:8000',
            'path' => 'nullable|string|max:200',
            'workspace_id' => 'nullable|string|max:40|in:'.implode(',', config('ai.workspace_ids')),
        ]);

        $updated = $this->knowledge->updateGlobal($request->user(), $entry, $data);
        if (! $updated) {
            abort(404);
        }

        return response()->json($updated);
    }

    public function deleteKnowledge(int $entry)
    {
        if (! $this->knowledge->deleteGlobal($entry)) {
            abort(404);
        }

        return response()->json(null, 204);
    }

    public function listKnowledgeDuplicates(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => 'nullable|string|max:40|in:'.implode(',', config('ai.workspace_ids')),
            'threshold' => 'nullable|numeric|min:50|max:100',
        ]);

        $result = $this->knowledge->findDuplicateClusters(
            $data['workspace_id'] ?? null,
            (float) ($data['threshold'] ?? 85),
        );

        return response()->json([
            'scope' => 'platform',
            ...$result,
        ]);
    }

    public function mergeKnowledge(Request $request)
    {
        $data = $request->validate([
            'keep_id' => 'required|integer|min:1',
            'merge_ids' => 'required|array|min:1|max:50',
            'merge_ids.*' => 'integer|min:1',
            'topic' => 'nullable|string|max:200',
            'content' => 'nullable|string|max:8000',
        ]);

        $merged = $this->knowledge->mergeGlobal(
            $request->user(),
            (int) $data['keep_id'],
            $data['merge_ids'],
            $data['topic'] ?? null,
            $data['content'] ?? null,
        );

        if (! $merged) {
            abort(404);
        }

        return response()->json($merged);
    }

    public function bulkDeleteKnowledge(Request $request)
    {
        $data = $request->validate([
            'entry_ids' => 'required|array|min:1|max:100',
            'entry_ids.*' => 'integer|min:1',
        ]);

        $deleted = $this->knowledge->deleteGlobalBulk($data['entry_ids']);

        return response()->json([
            'deleted' => $deleted,
        ]);
    }

    /**
     * Wipe platform training notes (all, or one workspace). Requires confirm=true.
     */
    public function deleteAllKnowledge(Request $request)
    {
        $data = $request->validate([
            'confirm' => 'accepted',
            'workspace_id' => 'nullable|string|max:40|in:'.implode(',', config('ai.workspace_ids')),
        ]);

        $deleted = $this->knowledge->deleteGlobalAll($data['workspace_id'] ?? null);

        return response()->json([
            'deleted' => $deleted,
            'scope' => ($data['workspace_id'] ?? null) ? 'workspace' : 'platform',
            'workspace_id' => $data['workspace_id'] ?? null,
        ]);
    }

    public function chat(Request $request)
    {
        $this->rejectImageContent($request);

        $data = $request->validate([
            'preview_organization_id' => 'required|integer|exists:organizations,id',
            'workspace_id' => 'nullable|string|max:40|in:'.implode(',', config('ai.workspace_ids')),
            'pathname' => 'nullable|string|max:300',
            'message' => ['required', 'string', 'max:4000', 'not_regex:/data:image\//i'],
            'history' => 'nullable|array|max:16',
            'history.*.role' => 'required_with:history|in:user,assistant',
            'history.*.content' => 'required_with:history|string|max:8000',
            'pending_action' => 'nullable|array',
            'pending_action.type' => 'required_with:pending_action|string|max:64',
            'pending_action.summary' => 'nullable|string|max:500',
            'pending_action.params' => 'nullable|array',
            'form_values' => 'nullable|array',
            'confirm_action' => 'nullable|boolean',
        ]);

        $preview = $this->resolvePreviewOrganization((int) $data['preview_organization_id']);
        if (! $preview) {
            abort(422, 'Choose a tenant organization for sample data context in the training preview.');
        }

        if (! empty($data['confirm_action'])) {
            return response()->json([
                'reply' => 'Training mode — actions are not executed. Platform knowledge still applies to all tenants.',
                'tools_used' => ['training_mode'],
                'training_mode' => true,
                'active_workspace' => $data['workspace_id'] ?? 'backoffice',
            ]);
        }

        if (! empty($data['form_values']) && ! empty($data['pending_action'])) {
            $data['pending_action']['params'] = array_merge(
                $data['pending_action']['params'] ?? [],
                $this->normalizeFormValues($data['form_values']),
            );
        }

        $result = $this->ai->chatForOrganization(
            $request->user(),
            $preview,
            $data['message'],
            $data['history'] ?? [],
            $data['pending_action'] ?? null,
            false,
            $data['workspace_id'] ?? null,
            $data['pathname'] ?? null,
            trainingMode: true,
        );

        $result['training_mode'] = true;
        $result['knowledge_scope'] = 'platform';

        return response()->json($result);
    }

    protected function resolvePreviewOrganization(?int $organizationId): ?Organization
    {
        if (! $organizationId) {
            return null;
        }

        $platformCode = config('erp.platform_company_code', 'PLATFORM');

        return Organization::query()
            ->whereKey($organizationId)
            ->where('company_code', '!=', $platformCode)
            ->first();
    }

    protected function rejectImageContent(Request $request): void
    {
        $blob = strtolower(json_encode($request->all()) ?: '');
        if (str_contains($blob, 'data:image/') || str_contains($blob, '"image/png"') || str_contains($blob, '"image/jpeg"')) {
            throw ValidationException::withMessages([
                'message' => ['Image uploads are not supported in the AI assistant.'],
            ]);
        }
    }

    /** @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function normalizeFormValues(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if ($value === '') {
                continue;
            }
            if (is_string($value) && is_numeric($value) && ! str_contains($key, 'phone') && ! str_contains($key, 'pin')) {
                $normalized[$key] = str_contains($value, '.') ? (float) $value : (int) $value;

                continue;
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    public function compose(Request $request)
    {
        $data = $request->validate([
            'task' => 'sometimes|string|max:50',
            'mode' => 'sometimes|string|max:50',
            'instruction' => 'nullable|string|max:5000',
            'subject' => 'nullable|string|max:500',
            'body' => 'nullable|string|max:20000',
            'placeholders' => 'sometimes|array',
            'use_knowledge' => 'sometimes|boolean',
            'skip_training' => 'sometimes|boolean',
            'system_hint' => 'nullable|string|max:5000',
            'output_format' => 'sometimes|string|max:20',
            'inbound_email' => 'nullable|array',
            'similar_replies' => 'nullable|array',
        ]);

        $runtime = \App\Services\Ai\AiSettingsResolver::resolveRuntimeForPlatformTraining();
        if (! $runtime) {
            return response()->json([
                'message' => 'No Gemini or OpenAI key is saved for platform tools. Add a Gemini key under Platform → AI training → Credentials (the same key used for tenant free AI).',
            ], 422);
        }

        $instruction = trim((string) ($data['instruction'] ?? ''));
        $mode = $data['mode'] ?? 'improve';
        $subject = (string) ($data['subject'] ?? '');
        $body = (string) ($data['body'] ?? '');
        $system = $data['system_hint']
            ?? 'You help write Centrix platform emails. Return JSON only with subject and body keys. Keep placeholders unchanged.';

        $userPrompt = "Mode: {$mode}\nInstruction: {$instruction}\nCurrent subject:\n{$subject}\n\nCurrent body:\n{$body}\n";

        if ($mode === 'reply' || ! empty($data['inbound_email'])) {
            $inbound = is_array($data['inbound_email'] ?? null) ? $data['inbound_email'] : [];
            $similar = is_array($data['similar_replies'] ?? null) ? $data['similar_replies'] : [];
            $inboundBlock = "From: ".($inbound['from_name'] ?? '')." <".($inbound['from_address'] ?? '').">\n"
                ."Subject: ".($inbound['subject'] ?? '')."\n\n"
                .($inbound['body_text'] ?? '');
            $memoryBlock = '';
            foreach (array_slice($similar, 0, 6) as $i => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $n = $i + 1;
                $memoryBlock .= "\n--- Past reply {$n} ---\n"
                    .'Subject: '.($row['subject'] ?? '')."\n"
                    .'Body: '.($row['body_text'] ?? '')."\n";
                if (! empty($row['inbound_snippet'])) {
                    $memoryBlock .= 'Original inbound snippet: '.$row['inbound_snippet']."\n";
                }
            }
            $userPrompt =
                "Mode: reply\nInstruction: ".($instruction !== '' ? $instruction : 'Draft a professional reply to the inbound email.')."\n\n"
                ."Inbound email to answer:\n{$inboundBlock}\n\n"
                .($memoryBlock !== ''
                    ? "How we replied to similar emails before (match tone and approach; do not copy blindly):\n{$memoryBlock}\n"
                    : "No prior similar replies on file — write a sensible first reply.\n")
                ."Draft reply so far (may be empty):\nSubject:\n{$subject}\n\nBody:\n{$body}\n";
            if (empty($data['system_hint'])) {
                $system = 'You help the Centrix platform admin reply to inbound mailbox emails. '
                    .'Read the inbound message and any past similar replies, then suggest a sensible response. '
                    .'Match how similar emails were answered when memory is provided. '
                    .'Return JSON only: {"subject":"...","body":"..."}. Kenya business English, professional and concise.';
            }
        }
        if (empty($runtime['api_key'])) {
            return response()->json(['message' => 'Platform AI API key missing.'], 422);
        }

        try {
            $turn = $this->providers->make($runtime)->chat([
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.4,
                'max_output_tokens' => (int) config('ai.defaults.max_output_tokens', 2048),
            ]);
        } catch (AiProviderException $e) {
            return response()->json(['message' => 'AI compose failed: '.$e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'AI compose failed: '.$e->getMessage()], 422);
        }

        $raw = trim((string) ($turn['text'] ?? ''));
        $parsed = json_decode($raw, true);
        if (! is_array($parsed) && preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $parsed = json_decode($m[0], true);
        }

        $subject = is_array($parsed) ? (string) ($parsed['subject'] ?? $parsed['title'] ?? $subject) : $subject;
        $body = is_array($parsed)
            ? (string) ($parsed['body'] ?? $parsed['message'] ?? ((isset($parsed['title']) || isset($parsed['reference'])) ? json_encode($parsed) : $body))
            : ($raw !== '' ? $raw : $body);

        return response()->json([
            'subject' => $subject,
            'body' => $body,
            'title' => is_array($parsed) ? (string) ($parsed['title'] ?? $subject) : $subject,
            'reference' => is_array($parsed) ? (string) ($parsed['reference'] ?? '') : '',
            'notes' => is_array($parsed) ? (string) ($parsed['notes'] ?? '') : '',
            'reply' => $raw,
        ]);
    }
}
