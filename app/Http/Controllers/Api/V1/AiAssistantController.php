<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiEntitySchemaCatalog;
use App\Services\Ai\AiKnowledgeService;
use App\Services\Ai\AiPageExplorer;
use App\Services\Ai\AiSettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AiAssistantController extends Controller
{
    public function __construct(
        protected AiAssistantService $ai,
        protected AiEntitySchemaCatalog $schemas,
        protected AiKnowledgeService $knowledge,
        protected AiPageExplorer $explorer,
    ) {}

    public function status(Request $request)
    {
        $desc = AiSettingsResolver::describeForClient($request->user());

        return response()->json([
            'enabled' => $desc['available'],
            'organization_enabled' => (bool) ($desc['settings']['enabled'] ?? false),
            'api_key_set' => (bool) ($desc['settings']['api_key_set'] ?? false),
            'provider' => $desc['provider'] ?? config('ai.provider'),
            'model' => $desc['model'] ?? config('ai.defaults.model'),
            'scope' => 'organization',
            'supports_form_inputs' => true,
            'allows_images' => false,
            'supports_teaching' => true,
            'supports_page_explore' => true,
        ]);
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
            'workspace_id' => 'nullable|string|max:32|in:pos,backoffice,admin,accounting,hr',
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
            'confirm_learn_id' => 'nullable|integer',
        ]);

        if (! empty($data['form_values']) && ! empty($data['pending_action'])) {
            $data['pending_action']['params'] = array_merge(
                $data['pending_action']['params'] ?? [],
                $data['form_values'],
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
        );

        if (! empty($data['confirm_learn_id'])) {
            $result['learn_confirmed'] = $confirmed ?? null;
        }

        return response()->json($result);
    }

    public function teach(Request $request)
    {
        $data = $request->validate([
            'topic' => 'required|string|max:200',
            'content' => 'required|string|max:8000',
            'path' => 'nullable|string|max:200',
        ]);

        $entry = $this->knowledge->teach(
            $request->user(),
            $data['topic'],
            $data['content'],
            $data['path'] ?? null,
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
            'message' => 'Review the summary below. Confirm to save this knowledge for your organization.',
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
}
