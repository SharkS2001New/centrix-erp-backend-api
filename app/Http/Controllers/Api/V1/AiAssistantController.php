<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiEntitySchemaCatalog;
use App\Services\Ai\AiKnowledgeService;
use App\Services\Ai\AiPageExplorer;
use App\Services\Ai\AiSettingsResolver;
use App\Services\Ai\AiRuntimeGuard;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AiAssistantController extends Controller
{
    public function __construct(
        protected AiAssistantService $ai,
        protected AiEntitySchemaCatalog $schemas,
        protected AiKnowledgeService $knowledge,
        protected AiPageExplorer $explorer,
        protected AiRuntimeGuard $runtimeGuard,
    ) {}

    /**Test API endpoint */
    public function status(Request $request)
    {
        $user = $request->user();
        $gate = app(\App\Services\Erp\ErpContext::class)->gateForUser($user);
        $desc = AiSettingsResolver::describeForClient($user);
        $health = $this->runtimeGuard->health();

        return response()->json([
            'enabled' => $desc['available'],
            'platform_enabled' => (bool) ($desc['platform_enabled'] ?? $gate->aiPlatformEnabled()),
            'organization_enabled' => (bool) ($desc['settings']['enabled'] ?? false),
            'api_key_set' => (bool) ($desc['settings']['api_key_set'] ?? false),
            'provider' => $desc['provider'] ?? config('ai.provider'),
            'model' => $desc['model'] ?? config('ai.defaults.model'),
            'scope' => 'organization',
            'supports_form_inputs' => true,
            'allows_images' => false,
            'supports_teaching' => true,
            'supports_page_explore' => true,
            'runtime' => [
                'status' => $health['status'],
                'available' => $health['available'],
                'inflight' => $this->runtimeGuard->inflight(),
            ],
        ]);
    }

    /**
     * Safe AI runtime health (no secrets / internal paths).
     */
    public function health()
    {
        $payload = $this->runtimeGuard->health();
        $payload['inflight'] = $this->runtimeGuard->inflight();
        $payload['max_concurrent'] = (int) config('ai.max_concurrent_requests', 32);

        return response()->json($payload);
    }

    public function schemas(Request $request)
    {
        $entity = $request->query('entity');
        if ($entity) {
            $schema = $this->schemas->forEntityWithOptions($request->user(), (string) $entity);
            if (! $schema) {
                abort(404, 'Unknown entity schema.');
            }

            return response()->json(['entity' => $entity, 'schema' => $schema]);
        }

        $keys = $this->schemas->entityKeys();
        $list = [];
        foreach ($keys as $key) {
            $list[$key] = $this->schemas->summaryForContext($request->user(), $key)[$key] ?? null;
        }

        return response()->json(['entities' => $keys, 'schemas' => $list]);
    }

    public function chat(Request $request)
    {
        $this->rejectImageContent($request);

        $data = $request->validate([
            'context' => 'nullable|string|in:products,reports,report_builder,general,erp',
            'workspace_id' => 'nullable|string|max:32|in:pos,backoffice,admin,accounting,hr,distribution',
            'pathname' => 'nullable|string|max:300',
            'page_context' => 'nullable|array',
            'page_context.screen_key' => 'nullable|string|max:80',
            'page_context.title' => 'nullable|string|max:200',
            'page_context.pathname' => 'nullable|string|max:300',
            'page_context.entity' => 'nullable|string|max:64',
            'page_context.entity_id' => 'nullable|string|max:64',
            'page_context.branch_id' => 'nullable',
            'page_context.filters' => 'nullable|array',
            'page_context.summary' => 'nullable|array',
            'page_context.rows' => 'nullable|array|max:80',
            'message' => ['required', 'string', 'max:4000', 'not_regex:/data:image\//i'],
            'conversation_id' => 'nullable|uuid',
            'history' => 'nullable|array|max:16',
            'history.*.role' => 'required_with:history|in:user,assistant',
            'history.*.content' => 'required_with:history|string|max:8000',
            'pending_action' => 'nullable|array',
            'pending_action.type' => 'required_with:pending_action|string|max:64',
            'pending_action.summary' => 'nullable|string|max:500',
            'pending_action.params' => 'nullable|array',
            'form_values' => 'nullable|array',
            'confirm_action' => 'nullable|boolean',
            'confirm_learn_id' => 'nullable|integer',
        ]);

        if (! empty($data['form_values']) && ! empty($data['pending_action'])) {
            $data['pending_action']['params'] = array_merge(
                $data['pending_action']['params'] ?? [],
                $this->normalizeFormValues($data['form_values']),
            );
        }

        if (! empty($data['confirm_learn_id'])) {
            $confirmed = $this->knowledge->confirm($request->user(), (int) $data['confirm_learn_id']);
            if (! $confirmed) {
                throw ValidationException::withMessages([
                    'confirm_learn_id' => ['Learning entry not found or already confirmed.'],
                ]);
            }
        }

        $result = $this->ai->chat(
            $request->user(),
            $data['message'],
            $data['history'] ?? [],
            $data['pending_action'] ?? null,
            (bool) ($data['confirm_action'] ?? false),
            $data['workspace_id'] ?? null,
            $data['pathname'] ?? null,
            $data['conversation_id'] ?? null,
            $data['page_context'] ?? null,
        );

        if (! empty($data['confirm_learn_id'])) {
            $result['learn_confirmed'] = $confirmed ?? null;
        }

        return response()->json($result);
    }

    public function teach(Request $request)
    {
        if (! $request->user()?->is_super_admin) {
            abort(403, 'AI training notes are managed platform-wide under Platform → AI training.');
        }

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
            'user_teaching',
        );

        return response()->json($entry, 201);
    }

    public function explore(Request $request)
    {
        $data = $request->validate([
            'path' => 'required|string|max:200',
            'confirm' => 'nullable|boolean',
            'draft_id' => 'nullable|integer',
        ]);

        $analysis = $this->explorer->analyze($request->user(), $data['path']);

        if (! empty($data['confirm']) && ! empty($data['draft_id'])) {
            $confirmed = $this->knowledge->confirm($request->user(), (int) $data['draft_id']);

            return response()->json([
                'analysis' => $analysis,
                'confirmed' => $confirmed,
            ]);
        }

        $draft = $this->knowledge->storeDraft(
            $request->user(),
            (string) $analysis['topic'],
            (string) $analysis['summary'],
            'page_explore',
            $analysis['path'],
        );

        return response()->json([
            'analysis' => $analysis,
            'draft' => $draft,
            'requires_confirmation' => true,
            'message' => 'Review the summary below. Confirm to save this as platform-wide ERP training (all tenants).',
        ]);
    }

    public function confirmKnowledge(Request $request, int $id)
    {
        $confirmed = $this->knowledge->confirm($request->user(), $id);
        if (! $confirmed) {
            abort(404);
        }

        return response()->json($confirmed);
    }

    public function discardKnowledge(Request $request, int $id)
    {
        if (! $this->knowledge->discard($request->user(), $id)) {
            abort(404);
        }

        return response()->json(null, 204);
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
}
