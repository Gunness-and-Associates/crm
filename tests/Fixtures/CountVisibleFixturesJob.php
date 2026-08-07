<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Proves the ACL query scope applies inside a queued job too, not just an
 * HTTP request (BACKEND_BRIEF §8.6) — the acting user's scope must hold
 * however the query is triggered.
 */
class CountVisibleFixturesJob implements ShouldQueue
{
    use Dispatchable;

    public static int $result = -1;

    public function handle(): void
    {
        self::$result = ContactableFixture::query()->count();
    }
}
