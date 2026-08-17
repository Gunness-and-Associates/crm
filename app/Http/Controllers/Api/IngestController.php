<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMetaLeadJob;
use App\Support\Ingest\IngestPipeline;
use App\Support\Ingest\Sources\WordPressPayloadNormalizer;
use App\Support\Settings;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Inbound integration endpoints — docs/contracts/api-contract.md Part 3.
 * WordPress and generic sources run the pipeline synchronously (form-submission
 * volume, no aggressive-retry pressure) and respond with the real record id.
 * Meta is queued instead (see ProcessMetaLeadJob) because Meta "retries
 * aggressively on slow responses" and a webhook batch has no single record id to
 * return synchronously anyway.
 */
final class IngestController extends Controller
{
    public function __construct(
        private readonly WordPressPayloadNormalizer $wordpressNormalizer,
        private readonly IngestPipeline $pipeline,
        private readonly Settings $settings,
    ) {}

    public function wordpress(Request $request): JsonResponse
    {
        $flat = $this->wordpressNormalizer->normalize($this->stringKeyed($request->all()));
        $result = $this->pipeline->run('wordpress', $flat);

        return response()->json(['id' => $result->record->getKey(), 'status' => $result->status], 202);
    }

    public function generic(Request $request, string $source): JsonResponse
    {
        $result = $this->pipeline->run($source, $this->stringKeyed($request->all()));

        return response()->json(['id' => $result->record->getKey(), 'status' => $result->status], 202);
    }

    /**
     * Meta's webhook subscription handshake: `hub.mode`/`hub.verify_token`/
     * `hub.challenge` query params — PHP's own query-string parser turns the dots
     * into underscores (an invalid-variable-name quirk, not a Meta one), so these
     * arrive as `hub_mode` etc.
     */
    public function verifyMeta(Request $request): Response
    {
        $verifyToken = $this->settings->get('ingest.meta.verify_token');
        $challenge = $request->query('hub_challenge');
        $providedToken = $request->query('hub_verify_token');

        $ok = $request->query('hub_mode') === 'subscribe'
            && is_string($verifyToken) && $verifyToken !== ''
            && is_string($providedToken)
            && hash_equals($verifyToken, $providedToken)
            && is_string($challenge);

        if (! $ok) {
            throw new AuthenticationException('Meta webhook verification failed.');
        }

        return response($challenge);
    }

    /**
     * Acks immediately (Meta retries aggressively otherwise) and defers the
     * per-lead Graph API fetch + pipeline run to ProcessMetaLeadJob.
     */
    public function meta(Request $request): JsonResponse
    {
        $this->verifyMetaSignature($request);

        foreach ($this->listOf($request->input('entry', [])) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach ($this->listOf($entry['changes'] ?? []) as $change) {
                $this->dispatchIfLeadgen($change);
            }
        }

        return response()->json(['status' => 'ok']);
    }

    private function dispatchIfLeadgen(mixed $change): void
    {
        if (! is_array($change) || ($change['field'] ?? null) !== 'leadgen') {
            return;
        }

        $value = $change['value'] ?? null;
        $leadgenId = is_array($value) ? ($value['leadgen_id'] ?? null) : null;

        if (is_string($leadgenId) && $leadgenId !== '') {
            ProcessMetaLeadJob::dispatch($leadgenId)->onQueue('integrations');
        }
    }

    private function verifyMetaSignature(Request $request): void
    {
        $secret = $this->settings->get('ingest.meta.app_secret');
        $header = $request->header('X-Hub-Signature-256');

        $expected = is_string($secret) ? 'sha256='.hash_hmac('sha256', $request->getContent(), $secret) : null;

        if (! is_string($secret) || $secret === '' || ! is_string($header) || $expected === null || ! hash_equals($expected, $header)) {
            throw new AuthenticationException('Missing or invalid Meta webhook signature.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyed(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * @return list<mixed>
     */
    private function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
