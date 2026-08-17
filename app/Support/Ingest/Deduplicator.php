<?php

namespace App\Support\Ingest;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;

/**
 * BACKEND_BRIEF §10: "Match on primary_email, then on normalised phone_mobile.
 * Configurable per module: warn or merge. Always record which rule matched."
 */
final class Deduplicator
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function find(string $modelClass, ?string $primaryEmail, ?string $phoneMobile): ?DedupeMatch
    {
        if ($primaryEmail !== null && $primaryEmail !== '') {
            $existing = $modelClass::query()->where('primary_email', $primaryEmail)->first();
            if ($existing !== null) {
                return new DedupeMatch($existing, 'primary_email');
            }
        }

        if ($phoneMobile !== null && $phoneMobile !== '') {
            $existing = $modelClass::query()->where('phone_mobile', $phoneMobile)->first();
            if ($existing !== null) {
                return new DedupeMatch($existing, 'phone_mobile');
            }
        }

        return null;
    }

    /**
     * 'merge' updates the matched record in place; 'warn' creates a new record
     * anyway but flags it (ingest_logs.status = duplicate) for manual review.
     */
    public function modeFor(string $moduleKey): string
    {
        $mode = $this->settings->get("ingest.dedupe.{$moduleKey}.mode", 'warn');

        return $mode === 'merge' ? 'merge' : 'warn';
    }
}
