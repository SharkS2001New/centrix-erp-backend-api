<?php

namespace App\Services\Attendance;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;

class HrAttendanceSettingsResolver
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return config('erp.module_settings_defaults.hr_payroll', []);
    }

    /** @return array<string, mixed> */
    public static function forOrganization(Organization $organization): array
    {
        $stored = is_array($organization->module_settings['hr_payroll'] ?? null)
            ? $organization->module_settings['hr_payroll']
            : [];

        return self::normalize(array_merge(self::defaults(), $stored));
    }

    /** @return array<string, mixed> */
    public static function forOrganizationId(int $organizationId): array
    {
        $organization = Organization::find($organizationId);

        return $organization
            ? self::forOrganization($organization)
            : self::normalize(self::defaults());
    }

    /** @return array<string, mixed> */
    public static function forGate(CapabilityGate $gate): array
    {
        return self::normalize(array_merge(
            self::defaults(),
            $gate->moduleSettings('hr_payroll'),
        ));
    }

    /** @param  array<string, mixed>  $settings */
    public static function normalize(array $settings): array
    {
        $defaults = self::defaults();
        $out = array_merge($defaults, $settings);

        $mode = $out['attendance_capture_mode'] ?? 'clock_device';
        $out['attendance_capture_mode'] = in_array($mode, ['clock_device', 'company_mobile'], true)
            ? $mode
            : 'clock_device';

        $out['company_premises_radius_metres'] = max(
            1,
            min(500, (float) ($out['company_premises_radius_metres'] ?? 5)),
        );
        $out['company_face_match_threshold'] = max(
            0.5,
            min(0.99, (float) ($out['company_face_match_threshold'] ?? 0.72)),
        );
        $out['company_fingerprint_match_threshold'] = max(
            0.5,
            min(0.99, (float) ($out['company_fingerprint_match_threshold'] ?? 0.85)),
        );
        $out['company_fingerprint_auto_enroll_on_clock'] = filter_var(
            $out['company_fingerprint_auto_enroll_on_clock'] ?? true,
            FILTER_VALIDATE_BOOLEAN,
        );

        $out['company_premises_latitude'] = self::nullableCoordinate($out['company_premises_latitude'] ?? null);
        $out['company_premises_longitude'] = self::nullableCoordinate($out['company_premises_longitude'] ?? null);

        $verification = $out['company_mobile_verification_method'] ?? 'face_or_fingerprint';
        $verification = match ($verification) {
            'device_biometric' => 'fingerprint',
            'face_or_device_biometric' => 'face_or_fingerprint',
            default => $verification,
        };
        $out['company_mobile_verification_method'] = in_array($verification, [
            'face',
            'fingerprint',
            'face_or_fingerprint',
        ], true) ? $verification : 'face_or_fingerprint';

        return $out;
    }

    /** @return array<int, string> */
    public static function allowedVerificationMethods(array $settings): array
    {
        $settings = self::normalize($settings);

        return match ($settings['company_mobile_verification_method']) {
            'face' => ['face'],
            'fingerprint' => ['fingerprint'],
            default => ['face', 'fingerprint'],
        };
    }

    public static function allowsFaceVerification(array $settings): bool
    {
        return in_array('face', self::allowedVerificationMethods($settings), true);
    }

    public static function allowsFingerprintVerification(array $settings): bool
    {
        return in_array('fingerprint', self::allowedVerificationMethods($settings), true);
    }

    /** @deprecated Use allowsFingerprintVerification */
    public static function allowsDeviceBiometricVerification(array $settings): bool
    {
        return self::allowsFingerprintVerification($settings);
    }

    public static function assertVerificationMethodAllowed(array $settings, string $method): void
    {
        if (! in_array($method, self::allowedVerificationMethods($settings), true)) {
            throw new \InvalidArgumentException('This verification method is not enabled for your organization.');
        }
    }

    /** @return array<string, mixed> */
    public static function companyMobilePublicConfig(array $settings, ?array $branchPremises = null): array
    {
        $normalized = self::normalize($settings);
        $hasPremises = $branchPremises !== null
            ? true
            : self::hasPremisesLocation($normalized);

        return [
            'enabled' => $normalized['attendance_capture_mode'] === 'company_mobile',
            'radius_metres' => $branchPremises['radius_metres'] ?? $normalized['company_premises_radius_metres'],
            'has_premises_location' => $hasPremises,
            'face_match_threshold' => $normalized['company_face_match_threshold'],
            'premises_latitude' => $branchPremises['latitude'] ?? $normalized['company_premises_latitude'],
            'premises_longitude' => $branchPremises['longitude'] ?? $normalized['company_premises_longitude'],
            'verification_method' => $normalized['company_mobile_verification_method'],
            'allowed_verification_methods' => self::allowedVerificationMethods($normalized),
            'allows_face_verification' => self::allowsFaceVerification($normalized),
            'allows_fingerprint_verification' => self::allowsFingerprintVerification($normalized),
            'fingerprint_match_threshold' => $normalized['company_fingerprint_match_threshold'],
            'fingerprint_auto_enroll_on_clock' => (bool) $normalized['company_fingerprint_auto_enroll_on_clock'],
            'allows_device_biometric_verification' => self::allowsFingerprintVerification($normalized),
        ];
    }

    /** @param  array<string, mixed>  $settings */
    public static function hasPremisesLocation(array $settings): bool
    {
        $settings = self::normalize($settings);

        return $settings['company_premises_latitude'] !== null
            && $settings['company_premises_longitude'] !== null;
    }

    protected static function nullableCoordinate(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 7);
    }
}
