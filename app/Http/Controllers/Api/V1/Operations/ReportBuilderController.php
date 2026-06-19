<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\CustomReportTemplate;
use App\Services\Reports\ReportBuilderService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReportBuilderController extends Controller
{
    public function __construct(protected ReportBuilderService $builder) {}

    public function schema(Request $request)
    {
        $workspaceId = $this->workspaceIdFromRequest($request);

        return response()->json($this->builder->schema($workspaceId));
    }

    public function sources(Request $request)
    {
        $workspaceId = $this->workspaceIdFromRequest($request);
        $schema = $this->builder->schema($workspaceId);
        $grouped = [];
        foreach ($schema['sources'] as $source) {
            $module = $source['module'] ?? 'General';
            $grouped[$module][] = [
                'key' => $source['key'],
                'label' => $source['label'],
                'description' => $source['description'] ?? '',
                'field_count' => count($source['fields'] ?? []),
                'has_date_filter' => ! empty($source['default_date_column']),
            ];
        }
        ksort($grouped);

        return response()->json([
            'workspace_id' => $schema['workspace_id'],
            'modules' => $schema['modules'] ?? [],
            'source_count' => count($schema['sources']),
            'sources_by_module' => $grouped,
        ]);
    }

    public function indexTemplates(Request $request)
    {
        $workspaceId = $this->workspaceIdFromRequest($request);
        $allowedSources = array_flip($this->builder->allowedSourceKeys($workspaceId));
        $user = $request->user();
        $rows = CustomReportTemplate::query()
            ->where('organization_id', $user->organization_id)
            ->where(function ($q) use ($user) {
                $q->where('is_shared', true)->orWhere('created_by', $user->id);
            })
            ->orderByDesc('updated_at')
            ->get(['id', 'name', 'description', 'is_shared', 'created_by', 'updated_at', 'spec'])
            ->filter(function (CustomReportTemplate $template) use ($allowedSources) {
                foreach ($this->builder->templateSpecSources($template->spec ?? []) as $source) {
                    if (! isset($allowedSources[$source])) {
                        return false;
                    }
                }

                return count($this->builder->templateSpecSources($template->spec ?? [])) > 0;
            })
            ->map(fn (CustomReportTemplate $template) => array_merge(
                $template->only([
                    'id', 'name', 'description', 'is_shared', 'created_by', 'updated_at',
                ]),
                $this->builder->templateListMeta($template->spec ?? []),
            ))
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function storeTemplate(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'spec' => 'required|array',
            'is_shared' => 'boolean',
            'workspace_id' => 'nullable|string|max:50',
        ]);

        $workspaceId = $this->workspaceIdFromRequest($request);
        $spec = $this->builder->validateSpec($data['spec'], $workspaceId);

        $template = CustomReportTemplate::create([
            'organization_id' => $request->user()->organization_id,
            'created_by' => $request->user()->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'spec' => $spec,
            'is_shared' => (bool) ($data['is_shared'] ?? false),
        ]);

        return response()->json($template, 201);
    }

    public function showTemplate(Request $request, int $templateId)
    {
        $workspaceId = $this->workspaceIdFromRequest($request);
        $template = $this->findTemplate($request, $templateId);
        $this->assertTemplateSourceAllowed($template, $workspaceId);

        return response()->json([
            'template' => $template,
            'definition' => $this->builder->toUiDefinition($template, $workspaceId),
        ]);
    }

    public function updateTemplate(Request $request, int $templateId)
    {
        $template = $this->findTemplate($request, $templateId, requireOwner: true);
        $workspaceId = $this->workspaceIdFromRequest($request);

        $data = $request->validate([
            'name' => 'sometimes|string|max:200',
            'description' => 'nullable|string|max:2000',
            'spec' => 'sometimes|array',
            'is_shared' => 'boolean',
            'workspace_id' => 'nullable|string|max:50',
        ]);

        if (isset($data['spec'])) {
            $data['spec'] = $this->builder->validateSpec($data['spec'], $workspaceId);
        }

        $template->update($data);

        return response()->json($template->fresh());
    }

    public function destroyTemplate(Request $request, int $templateId)
    {
        $template = $this->findTemplate($request, $templateId, requireOwner: true);
        $template->delete();

        return response()->json(['deleted' => true]);
    }

    public function preview(Request $request)
    {
        $data = $request->validate([
            'spec' => 'required|array',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'branch_id' => 'nullable|integer',
            'per_page' => 'nullable|integer|min:1|max:200',
            'workspace_id' => 'nullable|string|max:50',
        ]);

        $workspaceId = $this->workspaceIdFromRequest($request);
        $spec = $this->builder->validateSpec($data['spec'], $workspaceId);

        try {
            $result = $this->builder->run($request->user(), $spec, $data, $workspaceId);
        } catch (QueryException $e) {
            report($e);
            throw ValidationException::withMessages([
                'spec' => 'The selected sources and columns could not be combined into a valid report. '
                    .'Try different fields, add a linking source, or use side-by-side metrics for unrelated sources.',
            ]);
        }

        return response()->json($result);
    }

    public function runTemplate(Request $request, int $templateId)
    {
        $workspaceId = $this->workspaceIdFromRequest($request);
        $template = $this->findTemplate($request, $templateId);
        $this->assertTemplateSourceAllowed($template, $workspaceId);
        $filters = $request->only(['from_date', 'to_date', 'branch_id', 'per_page', 'page']);
        $result = $this->builder->run($request->user(), $template->spec, $filters, $workspaceId);

        return response()->json($result);
    }

    protected function workspaceIdFromRequest(Request $request): ?string
    {
        return $request->query('workspace_id') ?: $request->input('workspace_id');
    }

    protected function assertTemplateSourceAllowed(CustomReportTemplate $template, ?string $workspaceId): void
    {
        $source = $template->spec['source'] ?? null;
        if (! $source) {
            throw ValidationException::withMessages(['template' => 'Report template has no data source.']);
        }

        $this->builder->assertSourceAllowed($source, $workspaceId);
    }

    protected function findTemplate(Request $request, int $templateId, bool $requireOwner = false): CustomReportTemplate
    {
        $user = $request->user();
        $template = CustomReportTemplate::query()
            ->where('organization_id', $user->organization_id)
            ->where('id', $templateId)
            ->firstOrFail();

        if ($requireOwner && (int) $template->created_by !== (int) $user->id) {
            throw ValidationException::withMessages(['template' => 'Only the creator can modify this template.']);
        }

        if (! $template->is_shared && (int) $template->created_by !== (int) $user->id) {
            abort(403, 'You do not have access to this report template.');
        }

        return $template;
    }
}
