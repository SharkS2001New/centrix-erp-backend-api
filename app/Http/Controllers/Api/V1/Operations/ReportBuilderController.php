<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\CustomReportTemplate;
use App\Services\Reports\ReportBuilderService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReportBuilderController extends Controller
{
    public function __construct(protected ReportBuilderService $builder) {}

    public function schema()
    {
        return response()->json($this->builder->schema());
    }

    public function indexTemplates(Request $request)
    {
        $user = $request->user();
        $rows = CustomReportTemplate::query()
            ->where('organization_id', $user->organization_id)
            ->where(function ($q) use ($user) {
                $q->where('is_shared', true)->orWhere('created_by', $user->id);
            })
            ->orderByDesc('updated_at')
            ->get(['id', 'name', 'description', 'is_shared', 'created_by', 'updated_at']);

        return response()->json(['data' => $rows]);
    }

    public function storeTemplate(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'spec' => 'required|array',
            'is_shared' => 'boolean',
        ]);

        $spec = $this->builder->validateSpec($data['spec']);

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
        $template = $this->findTemplate($request, $templateId);

        return response()->json([
            'template' => $template,
            'definition' => $this->builder->toUiDefinition($template),
        ]);
    }

    public function updateTemplate(Request $request, int $templateId)
    {
        $template = $this->findTemplate($request, $templateId, requireOwner: true);

        $data = $request->validate([
            'name' => 'sometimes|string|max:200',
            'description' => 'nullable|string|max:2000',
            'spec' => 'sometimes|array',
            'is_shared' => 'boolean',
        ]);

        if (isset($data['spec'])) {
            $data['spec'] = $this->builder->validateSpec($data['spec']);
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
        ]);

        $spec = $this->builder->validateSpec($data['spec']);
        $result = $this->builder->run($request->user(), $spec, $data);

        return response()->json($result);
    }

    public function runTemplate(Request $request, int $templateId)
    {
        $template = $this->findTemplate($request, $templateId);
        $filters = $request->only(['from_date', 'to_date', 'branch_id', 'per_page', 'page']);
        $result = $this->builder->run($request->user(), $template->spec, $filters);

        return response()->json($result);
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
