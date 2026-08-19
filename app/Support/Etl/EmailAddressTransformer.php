<?php

namespace App\Support\Etl;

use App\Models\Affiliate;
use App\Models\Assessment;
use App\Models\Client;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\EmailAddressRelation;
use App\Models\Lead;
use App\Models\NewsletterSubscriber;
use App\Models\Student;
use App\Models\User;
use App\Support\Etl\Concerns\NormalizesLegacyValues;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BACKEND_BRIEF §13/§7.2's final ETL step: every Contactable transformer so
 * far only denormalised a single `primary_email` string (via RecoversLegacyEmail's
 * one-row `ORDER BY primary_address DESC LIMIT 1` query) — this backfills the
 * real polymorphic `EmailAddress`/`email_address_relations` records for every
 * row in `email_addr_bean_rel`, superseding that with the full picture (every
 * address a bean ever had, not just its primary one).
 *
 * `bean_id` IS the already-migrated target record's own preserved id (the same
 * idempotency scheme every other transformer uses), so resolving the subject
 * is a plain lookup, not a junction/parent_type puzzle like the activities. The
 * bean_module -> subject mapping below is exactly the `emailBeanModule` value
 * each Contactable transformer already passes to RecoversLegacyEmail — verified
 * empirically 2026-08-19: of 29,491 real rows, all but 10 (bean_module
 * `Leads`/`Accounts`/`Contacts` — dead stock modules with no migrated target,
 * same as Notes/Calls' native parent_type) resolve to one of these.
 *
 * Saving through `EmailAddressRelation` (rather than a raw insert) means its
 * existing `saved` hook re-derives `primary_email` from the real relations,
 * which is the one authoritative place BACKEND_BRIEF §7.2 wants that sync.
 */
final class EmailAddressTransformer implements LegacyTransformer
{
    use NormalizesLegacyValues;

    /** @var array<string, true>|null */
    private ?array $forcedPrimaryRelIds = null;

    public function key(): string
    {
        return 'email_addresses';
    }

    public function modelClass(): string
    {
        return EmailAddressRelation::class;
    }

    public function query(?string $fromId): Builder
    {
        // Deliberately not joined to `email_addresses` here -- that table also
        // has its own `id` column, and MigrateLegacyCommand always appends its
        // own `->orderBy('id')` to whatever query() returns, which would be
        // ambiguous against a join. Looked up per row in transform() instead
        // (a single indexed lookup, the same cost every other transformer
        // already pays via RecoversLegacyEmail).
        $query = DB::connection('legacy')
            ->table('email_addr_bean_rel')
            ->whereIn('bean_module', array_keys($this->subjectClassesByBeanModule()))
            ->where('deleted', 0);

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
        $beanId = $this->stringValue($row['bean_id'] ?? null);
        $beanModule = $this->stringValue($row['bean_module'] ?? null);
        if ($id === '' || $beanId === '') {
            return null;
        }

        $subjectClass = $this->subjectClassesByBeanModule()[$beanModule] ?? null;
        if ($subjectClass === null) {
            return null;
        }

        $subject = $subjectClass::withoutGlobalScopes()->find($beanId);
        if (! $subject instanceof Model) {
            return null;
        }

        $legacyAddress = DB::connection('legacy')->table('email_addresses')
            ->where('id', $this->stringValue($row['email_address_id'] ?? null))
            ->where('deleted', 0)
            ->first();
        if ($legacyAddress === null) {
            return null;
        }

        $email = strtolower(trim($this->stringValue($legacyAddress->email_address ?? null)));
        if ($email === '') {
            return null;
        }

        $address = EmailAddress::withoutGlobalScopes()->updateOrCreate(
            ['email' => $email],
            ['is_invalid' => $this->legacyBool($legacyAddress->invalid_email ?? null), 'opted_out' => $this->legacyBool($legacyAddress->opt_out ?? null)],
        );

        // A handful of legacy bean+address pairs are duplicated across two
        // distinct email_addr_bean_rel rows (verified: 28 real pairs, always
        // both flagged primary_address=1) -- email_address_relations' own
        // unique constraint only allows one, so once the first occurrence has
        // created it, every later duplicate is correctly a no-op skip.
        $alreadyLinked = EmailAddressRelation::withoutGlobalScopes()
            ->where('email_address_id', $address->id)
            ->where('related_type', $subjectClass)
            ->where('related_id', $beanId)
            ->where('id', '!=', $id)
            ->exists();
        if ($alreadyLinked) {
            return null;
        }

        $isPrimary = $this->legacyBool($row['primary_address'] ?? null)
            || isset($this->forcedPrimaryRelIds()[$id]);

        return [
            'id' => $id,
            'email_address_id' => $address->id,
            'related_type' => $subjectClass,
            'related_id' => $beanId,
            'is_primary' => $isPrimary,
            'is_reply_to' => false,
        ];
    }

    /**
     * A bean with email rows but none flagged `primary_address = 1` (verified:
     * 40 real GA_Companies rows) would otherwise get NO primary relation at
     * all once this runs -- silently erasing the `primary_email` its own
     * transformer already set via RecoversLegacyEmail's more lenient
     * "first match regardless of flag" fallback. Force exactly one relation
     * (the lowest source id, for a deterministic pick) to stay primary per
     * such bean so `primary_email` never regresses to null.
     *
     * @return array<string, true> email_addr_bean_rel id => true
     */
    private function forcedPrimaryRelIds(): array
    {
        if ($this->forcedPrimaryRelIds !== null) {
            return $this->forcedPrimaryRelIds;
        }

        $modules = array_keys($this->subjectClassesByBeanModule());

        $noPrimaryBeans = DB::connection('legacy')
            ->table('email_addr_bean_rel')
            ->whereIn('bean_module', $modules)
            ->where('deleted', 0)
            ->groupBy('bean_module', 'bean_id')
            ->havingRaw('SUM(primary_address) = 0')
            ->get(['bean_module', 'bean_id']);

        $forced = [];
        foreach ($noPrimaryBeans as $bean) {
            $firstId = DB::connection('legacy')
                ->table('email_addr_bean_rel')
                ->where('bean_module', $bean->bean_module)
                ->where('bean_id', $bean->bean_id)
                ->where('deleted', 0)
                ->orderBy('id')
                ->value('id');

            if (is_string($firstId) && $firstId !== '') {
                $forced[$firstId] = true;
            }
        }

        return $this->forcedPrimaryRelIds = $forced;
    }

    /**
     * @return array<string, class-string<Model>>
     */
    private function subjectClassesByBeanModule(): array
    {
        return [
            'Users' => User::class,
            'GA_Companies' => Company::class,
            'GA_HQ_Students' => Student::class,
            'GA_Assessment_Score' => Assessment::class,
            'GA_Assessment_Request' => Assessment::class,
            'GA_Clients' => Client::class,
            'GA_ClientDevelopment2' => Client::class,
            'GA_ClientDevelopment3' => Client::class,
            'GA_Affiliate' => Affiliate::class,
            'GA_Newsletter_Subscriber' => NewsletterSubscriber::class,
            // Lead source modules -- same bean_module strings already used as
            // each LeadModuleSpec's emailBeanModule.
            'GA_GALead' => Lead::class,
            'GA_Imm_Biz' => Lead::class,
            'GA_ImmCan1' => Lead::class,
            'GA_ImmCan2' => Lead::class,
            'GA_ImmCan3' => Lead::class,
            'GA_Imm_can' => Lead::class,
            'GA_USA' => Lead::class,
            'GA_ExpressEntryRequests' => Lead::class,
            'GA_StudyPermitRequests' => Lead::class,
            'GA_BD2' => Lead::class,
            'GA_Client_Development1' => Lead::class,
            'GA_BD1' => Lead::class,
            'GA_Applicant' => Lead::class,
            'GA_Study' => Lead::class,
            'GA_LMIAInquiry' => Lead::class,
            'GA_LMIA_Affiliate' => Lead::class,
            'GA_LMIA_MAIN' => Lead::class,
            'GA_LMIA_Course' => Lead::class,
            'GA_CanadaVisa' => Lead::class,
            'GA_New_PNP_Form' => Lead::class,
            'GA_PNP' => Lead::class,
            'GA_Refugee_Book' => Lead::class,
            'GA_Entrepreneur' => Lead::class,
            'GA_Resumes' => Lead::class,
            'GA_HQInvestor_' => Lead::class,
            'GA_GunnessAssociates' => Lead::class,
            'GA_Associates' => Lead::class,
            'GA_Inland' => Lead::class,
        ];
    }
}
