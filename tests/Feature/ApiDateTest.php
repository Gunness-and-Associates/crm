<?php

use App\Support\Api\ApiDate;
use Illuminate\Support\Carbon;

it('formats an outbound datetime as UTC, Y-m-d H:i:s, no offset', function () {
    $date = Carbon::create(2026, 6, 12, 9, 3, 11, 'America/Toronto');

    expect(ApiDate::out($date))->toBe($date->clone()->utc()->format('Y-m-d H:i:s'))
        ->and(ApiDate::out($date))->not->toContain('T')
        ->and(ApiDate::out($date))->not->toContain('+');
});

it('returns null for a null outbound date', function () {
    expect(ApiDate::out(null))->toBeNull();
});

it('parses the wire format Y-m-d H:i:s as UTC', function () {
    $parsed = ApiDate::in('2026-06-12 14:03:11');

    expect($parsed->toDateTimeString())->toBe('2026-06-12 14:03:11')
        ->and($parsed->timezoneName)->toBe('UTC');
});

it('parses full ISO-8601 and converts it to UTC', function () {
    $parsed = ApiDate::in('2026-06-12T09:03:11-05:00');

    expect($parsed->toDateTimeString())->toBe('2026-06-12 14:03:11')
        ->and($parsed->timezoneName)->toBe('UTC');
});

it('returns null for a null or empty inbound value', function () {
    expect(ApiDate::in(null))->toBeNull()
        ->and(ApiDate::in(''))->toBeNull();
});

it('rejects an unparseable or ambiguous datetime rather than guessing', function () {
    foreach (['12/06/2026', 'next tuesday', '2026-06-12', 'not a date at all'] as $bad) {
        expect(fn () => ApiDate::in($bad))->toThrow(InvalidArgumentException::class);
    }
});
