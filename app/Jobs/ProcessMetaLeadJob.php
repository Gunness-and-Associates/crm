<?php

namespace App\Jobs;

use App\Support\Ingest\IngestPipeline;
use App\Support\Ingest\Sources\MetaLeadFetcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Meta "retries aggressively on slow responses" (api-contract.md Part 3), so the
 * webhook handler acks immediately and defers the Graph API fetch + pipeline run
 * here — on the `integrations` queue, 5 tries exponential to 1 hour (BACKEND_BRIEF
 * §11's retry policy for that queue).
 */
final class ProcessMetaLeadJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 1800, 3600];

    public int $tries = 5;

    public function __construct(public readonly string $leadgenId) {}

    public function handle(MetaLeadFetcher $fetcher, IngestPipeline $pipeline): void
    {
        $fields = $fetcher->fetch($this->leadgenId);
        if ($fields === []) {
            return;
        }

        $pipeline->run('meta', $fields);
    }
}
