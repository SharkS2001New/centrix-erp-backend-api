<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiKnowledgeService;
use App\Services\Ai\AiSettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PlatformAiTrainingController extends Controller
{
    public function __construct(
        protected AiKnowledgeService $knowledge,
        protected AiAssistantService $ai,
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

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'provider' => 'sometimes|in:openai',
            'model' => 'sometimes|nullable|string|max:80',
            'api_key' => 'sometimes|nullable|string|max:250',
            'base_url' => 'sometimes|nullable|string|max:500',
        ]);

        return response()->json(AiSettingsResolver::savePlatformTraining($data));
    }

    public function listKnowledge(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => 'nullable|string|max:32|in:pos,backoffice,admin,accounting,hr,distribution',
        ]);

        return response()->json([
            'scope' => 'platform',
            'data' => $this->knowledge->listGlobal($data['workspace_id'] ?? null),
        ]);
    }

    public function teach(Request $request)
    {
        $data = $request->validate([
            'topic' => 'required|string|max:200',
            'content' => 'required|string|max:8000',
            'path' => 'nullable|string|max:200',
            'workspace_id' => 'nullable|string|max:32|in:pos,backoffice,admin,accounting,hr,distribution',
        ]);

        $entry = $this->knowledge->teachGlobal(
            $request->user(),
            $data['topic'],
            $data['content'],
            $data['path'] ?? null,
            $data['workspace_id'] ?? null,
        );

        return response()->json($entry, 201);
    }

    public function updateKnowledge(Request $request, int $entry)
    {
        $data = $request->validate([
            'topic' => 'sometimes|required|string|max:200',
            'content' => 'sometimes|required|string|max:8000',
            'path' => 'nullable|string|max:200',
            'workspace_id' => 'nullable|string|max:32|in:pos,backoffice,admin,accounting,hr,distribution',
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

    public function chat(Request $request)
    {
        $this->rejectImageContent($request);

        $data = $request->validate([
            'preview_organization_id' => 'required|integer|exists:organizations,id',
            'workspace_id' => 'nullable|string|max:32|in:pos,backoffice,admin,accounting,hr,distribution',
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
        ]);

        $runtime = \App\Services\Ai\AiSettingsResolver::resolveRuntimeForPlatformTraining();
        if (! $runtime) {
            return response()->json([
                'message' => 'Platform AI is not configured. Add credentials under Platform → AI training → Credentials.',
            ], 422);
        }

        $instruction = trim((string) ($data['instruction'] ?? ''));
        $mode = $data['mode'] ?? 'improve';
        $subject = (string) ($data['subject'] ?? '');
        $body = (string) ($data['body'] ?? '');
        $system = $data['system_hint']
            ?? 'You help write Centrix platform emails. Return JSON only with subject and body keys. Keep placeholders unchanged.';

        $userPrompt = "Mode: {$mode}\nInstruction: {$instruction}\nCurrent subject:\n{$subject}\n\nCurrent body:\n{$body}\n";
        $baseUrl = rtrim((string) ($runtime['base_url'] ?? 'https://api.openai.com/v1'), '/');
        $model = $runtime['model'] ?? 'gpt-4o-mini';
        $apiKey = $runtime['api_key'] ?? null;
        if (! $apiKey) {
            return response()->json(['message' => 'Platform AI API key missing.'], 422);
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
                ->timeout(60)
                ->post($baseUrl.'/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.4,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'response_format' => ['type' => 'json_object'],
                ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'AI compose failed: '.$e->getMessage()], 422);
        }

        if (! $response->successful()) {
            return response()->json([
                'message' => 'AI compose failed: '.$response->body(),
            ], 422);
        }

        $raw = (string) data_get($response->json(), 'choices.0.message.content', '');
        $parsed = json_decode($raw, true);
        if (! is_array($parsed) && preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $parsed = json_decode($m[0], true);
        }

        return response()->json([
            'subject' => is_array($parsed) ? (string) ($parsed['subject'] ?? $subject) : $subject,
            'body' => is_array($parsed) ? (string) ($parsed['body'] ?? $parsed['message'] ?? $body) : ($raw !== '' ? $raw : $body),
            'reply' => $raw,
        ]);
    }

}
