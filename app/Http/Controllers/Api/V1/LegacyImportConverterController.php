<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Legacy\LightStoresCentrixImportCsvGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LegacyImportConverterController extends Controller
{
    /** POST /admin/legacy-import-converter/convert */
    public function convert(Request $request): BinaryFileResponse|Response
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'max:51200'],
        ]);

        $files = $request->file('files', []);
        if ($files === []) {
            return response([
                'message' => 'Upload at least one LightStores SQL dump file.',
            ], 422);
        }

        try {
            $generator = LightStoresCentrixImportCsvGenerator::fromUploadedFiles($files);
            $zipPath = $generator->zipToTempFile();
        } catch (\Throwable $e) {
            return response([
                'message' => 'Could not convert SQL dumps: '.$e->getMessage(),
            ], 422);
        }

        return response()->download(
            $zipPath,
            'centrix-import-csv.zip',
            ['Content-Type' => 'application/zip'],
        )->deleteFileAfterSend(true);
    }
}
