<?php

namespace App\Http\Middleware\Api;

use App\Support\Settings;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/contracts/api-contract.md Part 3 — `POST /api/v1/ingest/{source}` carries
 * `X-Signature: sha256=<hmac of the raw body>`, compared with `hash_equals`. The
 * secret is per-source (route param), stored via Settings — never `.env`.
 */
final class VerifyHmacSignature
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $source = $request->route('source');
        $source = is_string($source) ? $source : '';

        $secret = $this->settings->get("ingest.{$source}.secret");
        $header = $request->header('X-Signature');

        $expected = is_string($secret) && $secret !== ''
            ? 'sha256='.hash_hmac('sha256', $request->getContent(), $secret)
            : null;

        if ($expected === null || ! is_string($header) || ! hash_equals($expected, $header)) {
            throw new AuthenticationException('Missing or invalid X-Signature.');
        }

        return $next($request);
    }
}
