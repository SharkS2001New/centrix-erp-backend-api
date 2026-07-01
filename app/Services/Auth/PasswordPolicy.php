<?php

namespace App\Services\Auth;

use Illuminate\Validation\ValidationException;

class PasswordPolicy
{
    /** Strip BOM and accidental whitespace from copy-paste (Slack, email, PDF). */
    public static function normalizeInput(string $password): string
    {
        $password = str_replace("\u{FEFF}", '', $password);

        return preg_replace('/^[\h\x00-\x1F\x7F]+|[\h\x00-\x1F\x7F]+$/u', '', $password) ?? $password;
    }

    /** @return array<int, string> */
    public static function validationRules(?int $organizationId, bool $confirmed = true): array
    {
        $settings = SecuritySettingsResolver::forOrganizationId($organizationId);
        $min = (int) $settings['password_min_length'];
        $rules = ['required', 'string', 'min:'.$min];

        if ($confirmed) {
            $rules[] = 'confirmed';
        }

        if ($settings['require_strong_passwords']) {
            $rules[] = 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/';
        }

        return $rules;
    }

    public static function assertValid(?int $organizationId, string $password, string $field = 'password'): void
    {
        $password = self::normalizeInput($password);
        $settings = SecuritySettingsResolver::forOrganizationId($organizationId);
        $min = (int) $settings['password_min_length'];

        if (strlen($password) < $min) {
            throw ValidationException::withMessages([
                $field => ["Password must be at least {$min} characters."],
            ]);
        }

        if ($settings['require_strong_passwords']
            && ! preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/', $password)) {
            throw ValidationException::withMessages([
                $field => ['Password must include uppercase, lowercase, and a number.'],
            ]);
        }
    }
}
