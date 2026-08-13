<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * RFC 7807 error envelope — docs/contracts/api-contract.md §1.5. Every `/api/v1/*`
 * error response is built here, never a raw Laravel/Symfony error page or the
 * framework's default JSON error shape.
 */
final class ProblemDetails
{
    /**
     * The `type` slug for each known error code — not always the code itself
     * (`validation_failed` → `validation`, matching the contract's own example).
     */
    private const TYPE_SLUGS = [
        'bad_request' => 'bad-request',
        'unauthenticated' => 'unauthenticated',
        'forbidden' => 'forbidden',
        'insufficient_scope' => 'insufficient-scope',
        'not_found' => 'not-found',
        'conflict' => 'conflict',
        'gone' => 'gone',
        'validation_failed' => 'validation',
        'rate_limited' => 'rate-limited',
        'server_error' => 'server-error',
    ];

    /**
     * @param  array<string, list<string>>|null  $errors
     */
    public static function render(int $status, string $code, string $detail, ?array $errors = null): JsonResponse
    {
        $slug = self::TYPE_SLUGS[$code] ?? Str::slug($code);

        $payload = [
            'type' => "https://crmga.local/errors/{$slug}",
            'title' => self::titleFor($status),
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
            'trace_id' => app(ApiTrace::class)->id(),
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status, ['Content-Type' => 'application/problem+json']);
    }

    private static function titleFor(int $status): string
    {
        return match ($status) {
            400 => 'The request could not be understood',
            401 => 'Authentication is required',
            403 => 'This action is not permitted',
            404 => 'The requested resource could not be found',
            409 => 'The request conflicts with the current state of the resource',
            410 => 'This endpoint has been removed',
            422 => 'The request could not be processed',
            429 => 'Too many requests',
            default => 'An unexpected error occurred',
        };
    }
}
