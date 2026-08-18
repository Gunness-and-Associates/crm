<?php

namespace App\Support\Etl;

use App\Models\NewsletterSubscriber;
use App\Support\Etl\Concerns\MapsContactableFields;
use App\Support\Etl\Concerns\NormalizesLegacyValues;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Load order position after Affiliates (BACKEND_BRIEF §13) — source
 * `ga_newsletter_subscriber`, single table, no `_cstm` sidecar.
 */
final class NewsletterSubscriberTransformer implements LegacyTransformer
{
    use MapsContactableFields;
    use NormalizesLegacyValues;

    public function key(): string
    {
        return 'newsletter_subscribers';
    }

    public function modelClass(): string
    {
        return NewsletterSubscriber::class;
    }

    public function query(?string $fromId): Builder
    {
        $query = DB::connection('legacy')->table('ga_newsletter_subscriber');

        if ($fromId !== null) {
            $query->where('id', '>=', $fromId);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public function transform(array $row): ?array
    {
        $id = $this->stringValue($row['id'] ?? null);
        if ($id === '') {
            return null;
        }

        return array_merge(
            $this->contactableAttributes($row, $id, 'GA_Newsletter_Subscriber'),
            [
                'status' => $this->nullableString($row['status'] ?? null) ?? 'subscribed',
                'source' => $this->nullableString($row['source'] ?? null),
                'referred_by' => $this->nullableString($row['referred_by'] ?? null),
            ],
        );
    }
}
