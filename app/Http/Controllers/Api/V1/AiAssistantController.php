<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiSettingsResolver;
use Illuminate\Http\Request;

class AiAssistantController extends Controller
{
    public function __construct(protected AiAssistantService $ai) {}

    public function status(Request $request)
    {
        $desc = AiSettingsResolver::describeForClient($request->user());

        return response()->json([
            'enabled' => $desc['available'],
            'organization_enabled' => (bool) ($desc['settings']['enabled'] ?? false),
            'api_key_set' => (bool) ($desc['settings']['api_key_set'] ?? false),
            'provider' => $desc['provider'] ?? config('ai.provider'),
            'model' => $desc['model'] ?? config('ai.defaults.model'),
        ]);
    }

    public function chat(Request $request)
    {
        $data = $request->validate([
            'context' => 'required|string|in:products,reports,report_builder,general',
            'message' => 'required|string|max:4000',
            'history' => 'nullable|array|max:12',
            'history.*.role' => 'required_with:history|in:user,assistant',
            'history.*.content' => 'required_with:history|string|max:8000',
        ]);

        $result = $this->ai->chat(
            $request->user(),
            $data['context'],
            $data['message'],
            $data['history'] ?? []
        );

        return response()->json($result);
    }
}
