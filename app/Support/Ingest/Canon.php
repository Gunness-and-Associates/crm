<?php

namespace App\Support\Ingest;

use App\Models\Metadata\OptionItem;
use App\Models\Metadata\OptionList;
use Illuminate\Support\Facades\Log;

/**
 * Canonicalisation — api-contract.md Part 3, "mandatory, this is a real bug we are
 * designing out." Incoming choice values arrive lower-cased, with underscores for
 * spaces, inconsistent punctuation, sometimes a trailing period.
 */
final class Canon
{
    public static function value(?string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($value ?? ''));
    }

    /**
     * Match an incoming raw choice value against an option list's items by
     * comparing canon(incoming) against canon(item->value) AND canon(item->label).
     * No match => null, never a guess, never the raw unmatched string stored in an
     * enum field. Every unmatched value is logged with its source and field so the
     * option list can be corrected.
     */
    public static function matchOption(?string $incoming, OptionList $list, string $source, string $field): ?string
    {
        $canonIncoming = self::value($incoming);
        if ($canonIncoming === '') {
            return null;
        }

        foreach ($list->items as $item) {
            /** @var OptionItem $item */
            if (self::value($item->value) === $canonIncoming || self::value($item->label) === $canonIncoming) {
                return $item->value;
            }
        }

        Log::channel('api')->warning('ingest_unmatched_option', [
            'source' => $source,
            'field' => $field,
            'raw_value' => $incoming,
            'option_list' => $list->key,
        ]);

        return null;
    }
}
