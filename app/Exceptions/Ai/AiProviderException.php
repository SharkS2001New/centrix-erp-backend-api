<?php

namespace App\Exceptions\Ai;

use RuntimeException;

class AiProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $codeKey = 'provider_error',
        public readonly ?int $httpStatus = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    public static function rateLimited(string $detail = ''): self
    {
        return new self(
            $detail !== '' ? $detail : 'Centrix AI is temporarily limited by external provider API rate limits. Please try again shortly.',
            'rate_limited',
            429,
            true,
        );
    }

    public static function unauthorized(): self
    {
        return new self(
            'AI provider credentials are invalid. Contact your administrator.',
            'invalid_api_key',
            401,
            false,
        );
    }

    public static function unavailable(string $detail = ''): self
    {
        return new self(
            'The AI service is temporarily unavailable. Please try again shortly.',
            'unavailable',
            503,
            true,
        );
    }

    public static function timeout(): self
    {
        return new self(
            'The AI request timed out. Please try again.',
            'timeout',
            504,
            true,
        );
    }

    public static function malformed(): self
    {
        return new self(
            'The AI service returned an unexpected response. Please try again.',
            'malformed_response',
            502,
            false,
        );
    }
}
