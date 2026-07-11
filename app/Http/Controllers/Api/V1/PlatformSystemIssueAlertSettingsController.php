<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
}
