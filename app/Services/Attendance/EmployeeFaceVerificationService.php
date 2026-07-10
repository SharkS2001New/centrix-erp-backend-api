<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeFaceProfile;
use App\Support\UploadedImageProcessor;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

class EmployeeFaceVerificationService
{
    public function parseEmbedding(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (! is_array($decoded)) {
                throw new InvalidArgumentException('Face embedding must be a JSON array.');
            }
            $value = $decoded;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('Face embedding is required.');
        }

        $embedding = array_values(array_map(static fn ($item) => (float) $item, $value));
        if (count($embedding) < 64) {
            throw new InvalidArgumentException('Face embedding is too short.');
        }

        return $this->normalizeEmbedding($embedding);
    }

    /** @return array{matched: bool, score: float|null, enrolled: bool, profile: EmployeeFaceProfile|null} */
    public function verifyOrEnroll(
        Employee $employee,
        array $embedding,
        UploadedFile $photo,
        float $threshold,
        ?string $deviceIdentifier = null,
    ): array {
        $profile = EmployeeFaceProfile::query()
            ->where('employee_id', $employee->id)
            ->first();

        if (! $profile) {
            $profile = $this->enroll($employee, $embedding, $photo, $deviceIdentifier);

            return [
                'matched' => true,
                'score' => 1.0,
                'enrolled' => true,
                'profile' => $profile,
            ];
        }

        $score = $this->cosineSimilarity($embedding, $profile->face_embedding ?? []);
        $matched = $score >= $threshold;

        if (! $matched) {
            throw new InvalidArgumentException('Face did not match the enrolled employee profile.');
        }

        return [
            'matched' => true,
            'score' => round($score, 4),
            'enrolled' => false,
            'profile' => $profile,
        ];
    }

    public function enroll(
        Employee $employee,
        array $embedding,
        UploadedFile $photo,
        ?string $deviceIdentifier = null,
    ): EmployeeFaceProfile {
        $path = app(UploadedImageProcessor::class)->storePublicImagePath(
            $photo,
            \App\Support\OrganizationPublicStorage::path($employee->organization_id, 'employees', (string) $employee->id, 'face-profile'),
        );

        return EmployeeFaceProfile::query()->updateOrCreate(
            ['employee_id' => $employee->id],
            [
                'organization_id' => (int) $employee->organization_id,
                'enrollment_photo_path' => $path,
                'face_embedding' => $embedding,
                'enrolled_at' => now(),
                'enrolled_device_identifier' => $deviceIdentifier,
            ],
        );
    }

    /** @param  array<int, float>  $a @param  array<int, float>  $b */
    public function cosineSimilarity(array $a, array $b): float
    {
        $length = min(count($a), count($b));
        if ($length === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /** @param  array<int, float>  $embedding */
    protected function normalizeEmbedding(array $embedding): array
    {
        $norm = 0.0;
        foreach ($embedding as $value) {
            $norm += $value * $value;
        }

        if ($norm <= 0.0) {
            return $embedding;
        }

        $scale = sqrt($norm);

        return array_map(static fn (float $value) => $value / $scale, $embedding);
    }
}
