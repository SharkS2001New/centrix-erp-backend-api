<?php

namespace App\Services\Background;

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Laravel\Sanctum\NewAccessToken;
use RuntimeException;

/**
 * Run authenticated API routes in-process (no HTTP round-trip through ingress).
 * Used by queue workers so background fetches are fast and reliable in Kubernetes.
 */
class InternalBackgroundRequest
{
    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query, User $user, NewAccessToken $token): array
    {
        $normalized = '/api/v1/'.ltrim($path, '/');

        $request = Request::create($normalized, 'GET', $query);
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Authorization', 'Bearer '.$token->plainTextToken);

        /** @var Kernel $kernel */
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);

        try {
            if ($response->getStatusCode() >= 400) {
                $detail = $this->extractErrorMessage($response->getContent());

                throw new RuntimeException(
                    $detail !== null
                        ? $detail
                        : 'Internal API request failed (HTTP '.$response->getStatusCode().').'
                );
            }

            $payload = json_decode($response->getContent(), true);

            return is_array($payload) ? $payload : [];
        } finally {
            $kernel->terminate($request, $response);
        }
    }

    protected function extractErrorMessage(string $content): ?string
    {
        $payload = json_decode($content, true);
        if (! is_array($payload)) {
            return null;
        }

        $message = $payload['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }
}
