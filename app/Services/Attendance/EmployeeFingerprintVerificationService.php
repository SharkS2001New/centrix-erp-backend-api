<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeFingerprintProfile;
use InvalidArgumentException;

class EmployeeFingerprintVerificationService
{
    /** @return array<int, float> */
    public function parseTemplate(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Fingerprint template is required.');
        }

        $raw = base64_decode(trim($value), true);
        if ($raw === false || $raw === '') {
            throw new InvalidArgumentException('Fingerprint template must be valid base64.');
        }

        if (strlen($raw) < 32) {
            throw new InvalidArgumentException('Fingerprint template is too short.');
        }

        return $this->normalizeTemplateVector($raw);
    }

    /** @return array{matched: bool, score: float|null, enrolled: bool, profile: EmployeeFingerprintProfile|null} */
    public function verifyOrEnroll(
        Employee $employee,
        array $templateVector,
        string $encodedTemplate,
        float $threshold,
        ?string $deviceIdentifier = null,
        ?string $scannerModel = null,
    ): array {
        if ($this->isDeviceBiometricScanner($scannerModel)) {
            return $this->verifyDeviceBiometricAttestation(
                $employee,
                $encodedTemplate,
                $deviceIdentifier,
                $scannerModel,
            );
        }

        $profile = EmployeeFingerprintProfile::query()
            ->where('employee_id', $employee->id)
            ->first();

        if (! $profile) {
            $profile = $this->enroll(
                $employee,
                $encodedTemplate,
                strlen(base64_decode($encodedTemplate, true) ?: ''),
                $deviceIdentifier,
                $scannerModel,
            );

            return [
                'matched' => true,
                'score' => 1.0,
                'enrolled' => true,
                'profile' => $profile,
            ];
        }

        $stored = $this->normalizeTemplateVector(
            base64_decode($profile->fingerprint_template, true) ?: '',
        );
        $score = $this->matchScore($templateVector, $stored);
        if ($score < $threshold) {
            throw new InvalidArgumentException('Fingerprint did not match the enrolled employee profile.');
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
        string $encodedTemplate,
        int $templateSize,
        ?string $deviceIdentifier = null,
        ?string $scannerModel = null,
    ): EmployeeFingerprintProfile {
        return EmployeeFingerprintProfile::query()->updateOrCreate(
            ['employee_id' => $employee->id],
            [
                'organization_id' => (int) $employee->organization_id,
                'fingerprint_template' => $encodedTemplate,
                'template_size' => max(0, $templateSize),
                'scanner_model' => $scannerModel ? mb_substr(trim($scannerModel), 0, 120) : null,
                'enrolled_at' => now(),
                'enrolled_device_identifier' => $deviceIdentifier,
            ],
        );
    }

    /** @param  array<int, float>  $a @param  array<int, float>  $b */
    public function matchScore(array $a, array $b): float
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

    /** @return array<int, float> */
    protected function normalizeTemplateVector(string $raw): array
    {
        $bytes = unpack('C*', $raw) ?: [];
        $vector = array_map(static fn (int $byte) => $byte / 255, array_values($bytes));

        $norm = 0.0;
        foreach ($vector as $value) {
            $norm += $value * $value;
        }

        if ($norm <= 0.0) {
            return $vector;
        }

        $scale = sqrt($norm);

        return array_map(static fn (float $value) => $value / $scale, $vector);
    }

    protected function isDeviceBiometricScanner(?string $scannerModel): bool
    {
        $model = strtolower(trim((string) ($scannerModel ?? '')));

        return str_starts_with($model, 'device_biometric:')
            || str_starts_with($model, 'device:');
    }

    /** @return array{matched: bool, score: float|null, enrolled: bool, profile: EmployeeFingerprintProfile|null} */
    protected function verifyDeviceBiometricAttestation(
        Employee $employee,
        string $encodedTemplate,
        ?string $deviceIdentifier,
        ?string $scannerModel,
    ): array {
        $encodedTemplate = trim($encodedTemplate);
        if ($encodedTemplate === '') {
            throw new InvalidArgumentException('Device biometric confirmation is required.');
        }

        $raw = base64_decode($encodedTemplate, true);
        if ($raw === false || strlen($raw) < 32) {
            throw new InvalidArgumentException('Device biometric confirmation is invalid.');
        }

        $profile = EmployeeFingerprintProfile::query()
            ->where('employee_id', $employee->id)
            ->first();

        if (! $profile) {
            $profile = $this->enroll(
                $employee,
                $encodedTemplate,
                strlen($raw),
                $deviceIdentifier,
                $scannerModel,
            );

            return [
                'matched' => true,
                'score' => 1.0,
                'enrolled' => true,
                'profile' => $profile,
            ];
        }

        if (! hash_equals((string) $profile->fingerprint_template, $encodedTemplate)) {
            throw new InvalidArgumentException(
                'This employee was not enrolled on this attendance phone. Select your name and scan again to enroll.',
            );
        }

        return [
            'matched' => true,
            'score' => 1.0,
            'enrolled' => false,
            'profile' => $profile,
        ];
    }
}
