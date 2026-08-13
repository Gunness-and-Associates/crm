<?php

namespace App\Exceptions\Api;

/**
 * Throw this for any API error that doesn't already map from a framework
 * exception (409 conflict, 410 gone, 403 insufficient_scope, ...) — the
 * exception renderer turns it straight into the RFC 7807 shape.
 */
class ApiException extends \RuntimeException
{
    /**
     * @param  array<string, list<string>>|null  $errors
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $detail,
        public readonly ?array $errors = null,
    ) {
        parent::__construct($detail);
    }

    public static function conflict(string $detail = 'The record has been modified since it was last read.'): self
    {
        return new self(409, 'conflict', $detail);
    }

    public static function gone(string $detail): self
    {
        return new self(410, 'gone', $detail);
    }

    public static function insufficientScope(string $detail = 'This token does not have the required scope.'): self
    {
        return new self(403, 'insufficient_scope', $detail);
    }

    public static function notFound(string $detail = 'The requested resource could not be found.'): self
    {
        return new self(404, 'not_found', $detail);
    }

    public static function badRequest(string $detail): self
    {
        return new self(400, 'bad_request', $detail);
    }
}
