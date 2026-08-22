<?php

namespace App\Services\Ai\Tools;

use App\Models\User;

interface AiToolInterface
{
    public function name(): string;

    public function description(): string;

    /**
     * Gemini / OpenAI-compatible JSON Schema for parameters.
     *
     * @return array<string, mixed>
     */
    public function parametersSchema(): array;

    /**
     * Execute with authenticated Centrix user — must enforce tenant + permissions.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(User $user, array $arguments): array;
}
