<?php

use App\Support\Logging\ScrubsSensitiveLogData;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;

/**
 * BACKEND_BRIEF §7 -- "No secret, token or personal datum in application
 * logs. Scrub known keys in the log formatter." Verifies the tap actually
 * redacts sensitive keys on a real Monolog record, at any nesting depth,
 * without touching unrelated context.
 */
it('redacts known sensitive keys from log context, including nested arrays, via the registered tap', function () {
    config(['logging.channels.scrub_test' => [
        'driver' => 'monolog',
        'handler' => TestHandler::class,
        'tap' => [ScrubsSensitiveLogData::class],
    ]]);

    $logger = Log::channel('scrub_test');

    $logger->info('user action', [
        'password' => 'super-secret-pw',
        'oauth_client_secret' => 'abc123',
        'access_token' => 'tok_live_xyz',
        'user' => ['email' => 'zain@example.com', 'two_factor_secret' => 'otpseed'],
        'note' => 'not sensitive',
    ]);

    $handler = $logger->getLogger()->getHandlers()[0];
    expect($handler)->toBeInstanceOf(TestHandler::class);

    $record = $handler->getRecords()[0];

    expect($record->context['password'])->toBe('[REDACTED]')
        ->and($record->context['oauth_client_secret'])->toBe('[REDACTED]')
        ->and($record->context['access_token'])->toBe('[REDACTED]')
        ->and($record->context['user']['email'])->toBe('zain@example.com')
        ->and($record->context['user']['two_factor_secret'])->toBe('[REDACTED]')
        ->and($record->context['note'])->toBe('not sensitive')
        ->and($record->level)->toBe(Level::Info);
});
