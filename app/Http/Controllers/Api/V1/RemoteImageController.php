<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\RemoteProductImageImporter;
use Illuminate\Http\Request;

/** Download a public image URL and return a copy the client can store. */
class RemoteImageController extends Controller
{
    public function import(Request $request)
    {
        $data = $request->validate([
            'url' => 'required|string|max:2000',
        ]);

        $image = (new RemoteProductImageImporter)->download($data['url']);

        return response()->json([
            'mime_type' => $image['mime'],
            'data_url' => 'data:'.$image['mime'].';base64,'.base64_encode($image['contents']),
        ]);
    }
}
