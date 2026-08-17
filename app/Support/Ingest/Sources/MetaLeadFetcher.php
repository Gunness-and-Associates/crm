<?php

namespace App\Support\Ingest\Sources;

use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BACKEND_BRIEF §10: "Webhook verification (hub.challenge), signature check, then
 * fetch the lead by id from the Graph API and map it." Meta's leadgen webhook
 * event only carries a `leadgen_id` reference, not the actual field data — the
 * full lead has to be pulled separately.
 */
final class MetaLeadFetcher
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @return array<string, mixed> flattened `field_data[].name => value`, or
     *                              empty if the token is missing or the call failed
     */
    public function fetch(string $leadgenId): array
    {
        $token = $this->settings->get('ingest.meta.access_token');
        if (! is_string($token) || $token === '') {
            Log::channel('api')->error('meta_lead_fetch_no_access_token', ['leadgen_id' => $leadgenId]);

            return [];
        }

        $configuredVersion = config('ingest.meta.graph_version', 'v21.0');
        $version = is_string($configuredVersion) ? $configuredVersion : 'v21.0';
        $response = Http::get("https://graph.facebook.com/{$version}/{$leadgenId}", [
            'access_token' => $token,
        ]);

        if ($response->failed()) {
            Log::channel('api')->error('meta_lead_fetch_failed', [
                'leadgen_id' => $leadgenId,
                'status' => $response->status(),
            ]);

            return [];
        }

        $fieldData = $response->json('field_data');

        return $this->flatten(is_array($fieldData) ? $fieldData : []);
    }

    /**
     * @param  array<mixed, mixed>  $fieldData
     * @return array<string, mixed>
     */
    private function flatten(array $fieldData): array
    {
        $flat = [];

        foreach ($fieldData as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null)) {
                continue;
            }

            $values = $entry['values'] ?? null;
            if (is_array($values) && array_key_exists(0, $values)) {
                $flat[$entry['name']] = $values[0];
            }
        }

        return $flat;
    }
}
