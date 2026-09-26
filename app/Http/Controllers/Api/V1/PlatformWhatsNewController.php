<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformWhatsNewNote;
use App\Services\Platform\PlatformWhatsNewService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformWhatsNewController extends Controller
{
    public function __construct(protected PlatformWhatsNewService $service) {}

    public function index(Request $request)
    {
        $status = $request->query('status');
        $notes = PlatformWhatsNewNote::query()
            ->with(['creator:id,full_name', 'publisher:id,full_name'])
            ->when(
                is_string($status) && $status !== '',
                fn ($q) => $q->where('status', $status),
            )
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $notes->map(fn (PlatformWhatsNewNote $note) => $this->service->present($note))->values(),
            'workspaces' => $this->workspaceOptions(),
        ]);
    }

    public function show(int $id)
    {
        $note = PlatformWhatsNewNote::query()
            ->with(['creator:id,full_name', 'publisher:id,full_name'])
            ->findOrFail($id);

        return response()->json([
            'data' => $this->service->present($note),
            'workspaces' => $this->workspaceOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $note = $this->service->create($request->user(), $data);
        $note->load(['creator:id,full_name', 'publisher:id,full_name']);

        return response()->json([
            'data' => $this->service->present($note),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $note = PlatformWhatsNewNote::query()->findOrFail($id);
        $data = $this->validated($request, partial: true);
        $note = $this->service->update($note, $data);
        $note->load(['creator:id,full_name', 'publisher:id,full_name']);

        return response()->json([
            'data' => $this->service->present($note),
        ]);
    }

    public function publish(Request $request, int $id)
    {
        $note = PlatformWhatsNewNote::query()->findOrFail($id);
        $note = $this->service->publish($note, $request->user());
        $note->load(['creator:id,full_name', 'publisher:id,full_name']);

        return response()->json([
            'data' => $this->service->present($note),
            'message' => 'Published. In-app notifications are being sent to matching users.',
        ]);
    }

    public function destroy(int $id)
    {
        $note = PlatformWhatsNewNote::query()->findOrFail($id);
        $this->service->delete($note);

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, bool $partial = false): array
    {
        $allowedWorkspaces = $this->service->allowedWorkspaceIds();
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:200'],
            'body' => [$required, 'string', 'max:10000'],
            'link_url' => ['nullable', 'string', 'max:500'],
            'audience' => [
                $partial ? 'sometimes' : 'nullable',
                Rule::in([
                    PlatformWhatsNewNote::AUDIENCE_ALL_USERS,
                    PlatformWhatsNewNote::AUDIENCE_ADMINS_ONLY,
                ]),
            ],
            'organization_ids' => ['nullable', 'array'],
            'organization_ids.*' => ['integer', 'min:1'],
            'workspace_ids' => [$required, 'array', 'min:1'],
            'workspace_ids.*' => ['string', Rule::in($allowedWorkspaces)],
            'show_on_login' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return list<array{id: string, label: string, description: string}> */
    protected function workspaceOptions(): array
    {
        $out = [];
        foreach (config('erp_workspaces', []) as $id => $def) {
            $out[] = [
                'id' => (string) $id,
                'label' => (string) ($def['label'] ?? $id),
                'description' => (string) ($def['description'] ?? ''),
            ];
        }

        return $out;
    }
}
