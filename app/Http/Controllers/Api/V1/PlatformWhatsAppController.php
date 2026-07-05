<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppSettingsResolver;
use Illuminate\Http\Request;

class PlatformWhatsAppController extends Controller
{
    public function show()
    {
        return response()->json(WhatsAppSettingsResolver::describePlatform());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'webhook_verify_token' => 'sometimes|nullable|string|max:120',
            'graph_api_version' => 'sometimes|nullable|string|max:16',
        ]);

        return response()->json(WhatsAppSettingsResolver::savePlatform($data));
    }
}
