<?php

namespace App\Exceptions\Api;

use App\Support\Api\ProblemDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Maps any exception raised on an `/api/v1/*` request to the RFC 7807 shape
 * (docs/contracts/api-contract.md §1.5) — registered from bootstrap/app.php.
 * A 500 here never includes the exception message: that goes to the log, not
 * the wire ("never leaks a stack trace").
 */
final class ApiExceptionRenderer
{
    public function render(\Throwable $e): JsonResponse
    {
        if ($e instanceof ApiException) {
            return ProblemDetails::render($e->status, $e->errorCode, $e->getMessage(), $e->errors);
        }

        if ($e instanceof ValidationException) {
            return ProblemDetails::render(422, 'validation_failed', 'One or more fields are invalid.', $this->normalizeValidationErrors($e->errors()));
        }

        if ($e instanceof AuthenticationException) {
            return ProblemDetails::render(401, 'unauthenticated', 'A valid access token is required.');
        }

        if ($e instanceof AuthorizationException) {
            return ProblemDetails::render(403, 'forbidden', 'You are not permitted to perform this action.');
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return ProblemDetails::render(404, 'not_found', 'The requested resource could not be found.');
        }

        if ($e instanceof TooManyRequestsHttpException) {
            return ProblemDetails::render(429, 'rate_limited', 'Too many requests. Please slow down.');
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return ProblemDetails::render($status, $this->codeForStatus($status), $this->detailForStatus($status));
        }

        return ProblemDetails::render(500, 'server_error', 'An unexpected error occurred.');
    }

    /**
     * @param  array<int|string, mixed>  $errors
     * @return array<string, list<string>>
     */
    private function normalizeValidationErrors(array $errors): array
    {
        $normalized = [];

        foreach ($errors as $field => $messages) {
            if (! is_string($field)) {
                continue;
            }

            $normalized[$field] = array_values(array_filter(
                is_array($messages) ? $messages : [$messages],
                fn (mixed $message): bool => is_string($message),
            ));
        }

        return $normalized;
    }

    private function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            409 => 'conflict',
            410 => 'gone',
            422 => 'validation_failed',
            429 => 'rate_limited',
            default => 'server_error',
        };
    }

    private function detailForStatus(int $status): string
    {
        return match ($status) {
            400 => 'The request was malformed.',
            401 => 'A valid access token is required.',
            403 => 'You are not permitted to perform this action.',
            404 => 'The requested resource could not be found.',
            409 => 'The request conflicts with the current state of the resource.',
            410 => 'This endpoint has been removed.',
            422 => 'One or more fields are invalid.',
            429 => 'Too many requests. Please slow down.',
            default => 'An unexpected error occurred.',
        };
    }
}
