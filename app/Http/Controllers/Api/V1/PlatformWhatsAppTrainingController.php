<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WhatsappBotTrainingReply;
use App\Services\WhatsApp\WhatsAppTrainingReplyMatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformWhatsAppTrainingController extends Controller
{
    public function __construct(
        protected WhatsAppTrainingReplyMatcher $matcher,
    ) {}

    public function index()
    {
        return response()->json([
            'data' => $this->matcher->listForAdmin(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        return response()->json(
            $this->matcher->create($data, $request->user()?->id),
            201,
        );
    }

    public function update(Request $request, int $id)
    {
        $row = WhatsappBotTrainingReply::query()->findOrFail($id);
        $data = $this->validated($request, partial: true);

        return response()->json(
            $this->matcher->update($row, $data, $request->user()?->id),
        );
    }

    public function destroy(int $id)
    {
        $row = WhatsappBotTrainingReply::query()->findOrFail($id);
        $this->matcher->delete($row);

        return response()->json(['ok' => true]);
    }

    public function preview(Request $request)
    {
        $data = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $match = $this->matcher->match($data['message']);

        return response()->json([
            'matched' => $match !== null,
            'match' => $match,
            'reply' => $match['response_text'] ?? null,
        ]);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'title' => 'nullable|string|max:120',
            'keywords' => [$required, function (string $attribute, mixed $value, \Closure $fail) {
                $list = is_array($value)
                    ? $value
                    : (is_string($value) ? preg_split('/[\n,;]+/', $value) : []);
                $normalized = app(WhatsAppTrainingReplyMatcher::class)->normalizeKeywords($list ?: []);
                if ($normalized === []) {
                    $fail('Add at least one keyword (2+ characters).');
                }
            }],
            'response_text' => [$required, 'string', 'max:4000'],
            'match_mode' => ['sometimes', Rule::in(['any', 'all'])],
            'priority' => 'sometimes|integer|min:0|max:9999',
            'is_active' => 'sometimes|boolean',
        ]);

        if (isset($data['keywords'])) {
            $data['keywords'] = $this->matcher->normalizeKeywords($data['keywords']);
        }

        return $data;
    }
}
