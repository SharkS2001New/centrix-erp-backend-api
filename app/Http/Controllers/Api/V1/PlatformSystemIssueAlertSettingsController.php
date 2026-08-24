<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SystemIssues\SystemIssueAlertService;
use App\Services\SystemIssues\SystemIssueAlertSettingsResolver;
use Illuminate\Http\Request;

class PlatformSystemIssueAlertSettingsController extends Controller
{
    public function show()
    {
        return response()->json(SystemIssueAlertSettingsResolver::describe());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'email_digest_enabled' => 'sometimes|boolean',
            'digest_email' => 'nullable|email|max:190',
            'instant_email_enabled' => 'sometimes|boolean',
            'whatsapp_instant_enabled' => 'sometimes|boolean',
            'whatsapp_number' => 'nullable|string|max:40',
        ]);

        return response()->json(SystemIssueAlertSettingsResolver::save($data));
    }

    public function test(Request $request, SystemIssueAlertService $alerts)
    {
        $data = $request->validate([
            'channels' => 'sometimes|array',
            'channels.*' => 'in:email,whatsapp',
        ]);

        $result = $alerts->sendTest($data['channels'] ?? ['email']);
        $status = $result['ok'] ? 200 : 422;

        return response()->json([
            'message' => $result['ok']
                ? 'Test notification sent.'
                : 'Test notification failed. Check SMTP / WhatsApp settings.',
            ...$result,
        ], $status);
    }
}
