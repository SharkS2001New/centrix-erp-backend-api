<?php

namespace App\Services\Returns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ReturnProofService
{
    public const MAX_KB = 10240;

    /** @return array<int, string> */
    public static function fileRules(bool $required = false): array
    {
        $rules = ['file', 'max:'.self::MAX_KB, 'mimes:pdf,jpeg,jpg,png,webp,doc,docx'];

        array_unshift($rules, $required ? 'required' : 'nullable');

        return $rules;
    }

    public function store(object $record, UploadedFile $file, string $storageDirectory): void
    {
        $this->deleteExisting($record);

        $path = $file->store(trim($storageDirectory, '/'), 'public');

        $record->forceFill([
            'proof_file_path' => $path,
            'proof_file_name' => $file->getClientOriginalName(),
            'proof_file_mime_type' => $file->getMimeType(),
            'proof_file_size' => $file->getSize(),
        ])->save();
    }

    public function deleteExisting(object $record): void
    {
        $path = $record->proof_file_path ?? null;
        if (is_string($path) && $path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /** @return array<string, mixed>|null */
    public function meta(object $record, string $fileUrlPath): ?array
    {
        if (! is_string($record->proof_file_path ?? null) || $record->proof_file_path === '') {
            return null;
        }

        return [
            'file_name' => $record->proof_file_name,
            'mime_type' => $record->proof_file_mime_type,
            'file_size' => $record->proof_file_size !== null ? (int) $record->proof_file_size : null,
            'url' => $fileUrlPath,
        ];
    }

    public function absolutePath(object $record): ?string
    {
        if (! is_string($record->proof_file_path ?? null) || $record->proof_file_path === '') {
            return null;
        }

        if (! Storage::disk('public')->exists($record->proof_file_path)) {
            return null;
        }

        return Storage::disk('public')->path($record->proof_file_path);
    }
}
