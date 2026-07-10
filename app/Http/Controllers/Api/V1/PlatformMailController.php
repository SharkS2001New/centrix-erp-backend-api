<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformMailSettingsResolver;
use Illuminate\Http\Request;

class PlatformMailController extends Controller
{
    public function show()
    {
        return response()->json([
            'settings' => PlatformMailSettingsResolver::resolve(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'from_name' => 'sometimes|string|max:200',
            'from_address' => 'sometimes|email|max:200',
            'reply_to' => 'nullable|email|max:200',
            'smtp_host' => 'nullable|string|max:200',
            'smtp_port' => 'nullable|integer|min:1',
            'smtp_username' => 'nullable|string|max:200',
            'smtp_password' => 'nullable|string|max:500',
            'smtp_encryption' => 'nullable|in:tls,ssl,none',
            'contract_email_subject' => 'nullable|string|max:500',
            'contract_email_body' => 'nullable|string',
        ]);

        return response()->json([
            'settings' => PlatformMailSettingsResolver::save($data),
            'message' => 'Platform email settings saved.',
        ]);
    }

    public function test(Request $request)
    {
        $data = $request->validate(['to' => 'required|email']);
        PlatformMailSettingsResolver::sendRaw(
            $data['to'],
            'Centrix platform mail test',
            "This is a test email from Centrix platform mail settings.\n\nIf you received this, SMTP is working."
        );

        return response()->json(['message' => 'Test email sent.']);
    }
}
