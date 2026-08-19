<?php

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;

/**
 * BACKEND_BRIEF §7 — "No secret, token or personal datum in application logs.
 * Scrub known keys in the log formatter." Registered as a `tap` on every
 * channel in config/logging.php, so it applies no matter which call site
 * (or future call site) logs a context array — a backstop, not a substitute
 * for still being careful about what gets logged in the first place.
 *
 * Only redacts by KEY within array context/extra data (recursively, since a
 * whole model's or request's array can nest a sensitive key at any depth) —
 * scrubbing the free-text message string itself is deliberately out of
 * scope, both because "known keys" is what the brief asks for and because a
 * blind regex over message text risks corrupting legitimate log content.
 */
final class ScrubsSensitiveLogData
{
    /**
     * Matched case-insensitively against the array key, not the whole
     * string, so `password`, `passwordConfirmation`, `oauth_client_secret`,
     * `x-api-key` etc. are all caught by a short substring list.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEY_SUBSTRINGS = [
        'password', 'secret', 'token', 'api_key', 'apikey', 'authorization',
        'private_key', 'client_secret', 'access_token', 'refresh_token',
        'two_factor_secret', 'recovery_code',
    ];

    private const REDACTED = '[REDACTED]';

    public function __invoke(Logger $logger): void
    {
        // getLogger() is typed to the PSR-3 interface, but every channel this
        // app configures is built on Monolog (Laravel's own default) --
        // pushProcessor() is Monolog-specific, so narrow to it explicitly
        // rather than assuming.
        $underlying = $logger->getLogger();
        if ($underlying instanceof MonologLogger) {
            $underlying->pushProcessor($this->process(...));
        }
    }

    private function process(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->scrub($record->context),
            extra: $this->scrub($record->extra),
        );
    }

    /**
     * @param  array<mixed, mixed>  $data
     * @return array<mixed, mixed>
     */
    private function scrub(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $result[$key] = self::REDACTED;

                continue;
            }

            $result[$key] = is_array($value) ? $this->scrub($value) : $value;
        }

        return $result;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::SENSITIVE_KEY_SUBSTRINGS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}
