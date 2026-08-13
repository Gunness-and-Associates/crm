<?php

namespace App\Support\Api;

use Illuminate\Support\Str;

/**
 * One instance per request (bound `scoped()` in AppServiceProvider) — the single
 * source of the request's trace_id, so a `LogApiRequest` log line and the trace_id
 * in that same request's RFC 7807 error body (ProblemDetails::render()) always
 * match, letting support correlate the two.
 */
final class ApiTrace
{
    private readonly string $id;

    public function __construct()
    {
        $this->id = (string) Str::ulid();
    }

    public function id(): string
    {
        return $this->id;
    }
}
