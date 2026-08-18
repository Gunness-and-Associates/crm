<?php

namespace App\Support\Etl;

use App\Models\Affiliate;
use App\Support\Etl\Concerns\DisambiguatesUniqueColumn;
use App\Support\Etl\Concerns\MapsContactableFields;
use App\Support\Etl\Concerns\NormalizesLegacyValues;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Load order position after Leads/Students (BACKEND_BRIEF §13) — source
 * `ga_affiliate` (+ `ga_affiliate_cstm`), referral partners.
 */
final class AffiliateTransformer implements LegacyTransformer
{
    use DisambiguatesUniqueColumn;
    use MapsContactableFields;
    use NormalizesLegacyValues;

    /** @var array<string, string>|null */
    private ?array $usernameOverrides = null;

    public function key(): string
    {
        return 'affiliates';
    }

    public function modelClass(): string
    {
        return Affiliate::class;
    }

    public function query(?string $fromId): Builder
    {
        $query = DB::connection('legacy')
            ->table('ga_affiliate')
            ->leftJoin('ga_affiliate_cstm', 'ga_affiliate_cstm.id_c', '=', 'ga_affiliate.id')
            ->select([
                'ga_affiliate.*', 'ga_affiliate_cstm.commission_c', 'ga_affiliate_cstm.status_c',
            ]);

        if ($fromId !== null) {
            $query->where('ga_affiliate.id', '>=', $fromId);
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

        // affiliates.username is unique — real duplicate usernames exist in
        // the audited source (up to 6 rows sharing one), same class of bug
        // discovered on UserTransformer's api_user collision.
        $username = $this->usernameOverrides()[$id] ?? $this->nullableString($row['username'] ?? null);

        return array_merge(
            $this->contactableAttributes($row, $id, 'GA_Affiliate'),
            [
                'username' => $username,
                'affiliate_link' => $this->nullableString($row['affiliate_link'] ?? null),
                'commission' => $this->nullableString($row['commission_c'] ?? null),
                'status' => $this->nullableString($row['status_c'] ?? null) ?? 'active',
            ],
        );
    }

    /**
     * @return array<string, string>
     */
    private function usernameOverrides(): array
    {
        return $this->usernameOverrides ??= $this->uniqueColumnOverrides('ga_affiliate', 'username');
    }
}
