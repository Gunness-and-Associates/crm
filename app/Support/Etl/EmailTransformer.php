<?php

namespace App\Support\Etl;

use App\Models\Assessment;
use App\Models\Company;
use App\Models\Email;
use App\Models\Lead;
use App\Models\NewsletterSubscriber;
use App\Models\Student;
use App\Support\Etl\Concerns\NormalizesLegacyValues;
use App\Support\Etl\Concerns\ResolvesActivitySubjects;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The biggest and most heterogeneous activity type (45,725 rows). Unlike
 * Notes/Calls/Meetings, `emails.parent_type` genuinely carries the real
 * linkage signal (per-module junction tables exist for only a handful of
 * modules here) — but it mixes current `GA_*` names with historical aliases
 * (`hamid_*`, `SZ_*`) that predate a module rename and no longer correspond
 * to any live table. Every alias below was verified empirically (not
 * guessed from the name): its `parent_id` values match real ids in the
 * current-day table at a high rate (88-100%, confirmed 2026-08-19). The
 * rows that don't match are correctly dropped by
 * ResolvesActivitySubjects' existence check, not silently kept.
 *
 * `emails.status` is a much richer legacy vocabulary (sent/replied/archived/
 * read/unread/received/draft/...) than the target's draft|sent|failed — only
 * `draft` has a clean 1:1 mapping; every other real status represents an
 * email that existed/was sent, so it collapses to 'sent' (the schema's own
 * default). There is no legacy signal for 'failed'.
 */
final class EmailTransformer implements LegacyTransformer
{
    use NormalizesLegacyValues;
    use ResolvesActivitySubjects;

    /** @var array<string, array{from: list<string>, to: list<string>, cc: list<string>}>|null */
    private ?array $addressMap = null;

    public function key(): string
    {
        return 'activities_emails';
    }

    public function modelClass(): string
    {
        return Email::class;
    }

    public function query(?string $fromId): Builder
    {
        $ids = array_keys($this->resolveActivitySubjects($this->specs(), 'emails'));

        $query = DB::connection('legacy')
            ->table('emails')
            ->leftJoin('emails_text', 'emails_text.email_id', '=', 'emails.id')
            ->select(['emails.*', 'emails_text.description', 'emails_text.description_html', 'emails_text.from_addr'])
            ->whereIn('emails.id', $ids);

        if ($fromId !== null) {
            $query->where('emails.id', '>=', $fromId);
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

        $subject = $this->resolveActivitySubjects($this->specs(), 'emails')[$id] ?? null;
        if ($subject === null) {
            return null;
        }

        $deletedAt = (bool) ($row['deleted'] ?? false)
            ? LegacyDate::parse($this->nullableString($row['date_modified'] ?? null))
            : null;

        $addresses = $this->resolveAddresses()[$id] ?? ['from' => [], 'to' => [], 'cc' => []];
        $fromAddress = $addresses['from'][0]
            ?? $this->nullableString($row['from_addr'] ?? null)
            ?? sprintf('%s@migrated.invalid', $id);

        $legacyStatus = $this->nullableString($row['status'] ?? null);
        $status = $legacyStatus !== null && strtolower($legacyStatus) === 'draft' ? 'draft' : 'sent';

        return [
            'id' => $id,
            'deleted_at' => $deletedAt,
            'subject_type' => $subject['class'],
            'subject_id' => $subject['id'],
            'assigned_user_id' => $this->nullableUuid($row['assigned_user_id'] ?? null),
            'created_by' => $this->nullableUuid($row['created_by'] ?? null),
            'subject_line' => $this->nullableString($row['name'] ?? null),
            'from_address' => $fromAddress,
            'to_addresses' => $addresses['to'],
            'cc_addresses' => $addresses['cc'] === [] ? null : $addresses['cc'],
            'body_html' => $this->nullableString($row['description_html'] ?? null),
            'body_text' => $this->nullableString($row['description'] ?? null),
            'status' => $status,
            'sent_at' => LegacyDate::parse($this->nullableString($row['date_sent_received'] ?? null)),
        ];
    }

    /**
     * @return array<string, array{from: list<string>, to: list<string>, cc: list<string>}>
     */
    private function resolveAddresses(): array
    {
        if ($this->addressMap !== null) {
            return $this->addressMap;
        }

        $ids = array_keys($this->resolveActivitySubjects($this->specs(), 'emails'));

        $rows = DB::connection('legacy')
            ->table('emails_email_addr_rel')
            ->join('email_addresses', 'email_addresses.id', '=', 'emails_email_addr_rel.email_address_id')
            ->whereIn('emails_email_addr_rel.email_id', $ids)
            ->whereIn('emails_email_addr_rel.address_type', ['from', 'to', 'cc'])
            ->where('emails_email_addr_rel.deleted', 0)
            ->where('email_addresses.deleted', 0)
            ->get(['emails_email_addr_rel.email_id', 'emails_email_addr_rel.address_type', 'email_addresses.email_address']);

        $map = [];
        foreach ($rows as $row) {
            $emailId = $this->stringValue($row->email_id);
            $address = $this->nullableString($row->email_address);
            if ($address === null) {
                continue;
            }

            $map[$emailId] ??= ['from' => [], 'to' => [], 'cc' => []];
            match ($this->stringValue($row->address_type)) {
                'from' => $map[$emailId]['from'][] = $address,
                'to' => $map[$emailId]['to'][] = $address,
                'cc' => $map[$emailId]['cc'][] = $address,
                default => null,
            };
        }

        return $this->addressMap = $map;
    }

    /**
     * @return list<ActivitySourceSpec>
     */
    private function specs(): array
    {
        return [
            // Real per-module junction tables (same mechanism as Notes).
            ActivitySourceSpec::viaJunction('ga_lmia_course_emails_c', 'ga_lmia_course', Lead::class),
            ActivitySourceSpec::viaJunction('ga_study_emails_c', 'ga_study', Lead::class),
            ActivitySourceSpec::viaJunction('ga_imm_can_emails_c', 'ga_imm_can', Lead::class),
            ActivitySourceSpec::viaJunction('ga_usa_emails_c', 'ga_usa', Lead::class),
            ActivitySourceSpec::viaJunction('ga_lmiainquiry_emails_c', 'ga_lmiainquiry', Lead::class),
            // Current module names via emails.parent_type directly.
            ActivitySourceSpec::viaParentType('GA_Companies', Company::class),
            ActivitySourceSpec::viaParentType('GA_GALead', Lead::class),
            ActivitySourceSpec::viaParentType('GA_HQInvestor_', Lead::class),
            ActivitySourceSpec::viaParentType('GA_Imm_Biz', Lead::class),
            ActivitySourceSpec::viaParentType('GA_Applicant', Lead::class),
            ActivitySourceSpec::viaParentType('GA_GunnessAssociates', Lead::class),
            ActivitySourceSpec::viaParentType('GA_Refugee_Book', Lead::class),
            ActivitySourceSpec::viaParentType('GA_HQ_Students', Student::class),
            ActivitySourceSpec::viaParentType('GA_Assessment_Score', Assessment::class),
            ActivitySourceSpec::viaParentType('GA_Imm_can', Lead::class),
            ActivitySourceSpec::viaParentType('GA_Resumes', Lead::class),
            // Historical pre-rename aliases -- verified empirically (see class
            // docblock), not guessed. The existence check drops any row whose
            // parent_id doesn't actually match, so a lower confirmed match
            // rate (e.g. ~72% for the express-entry alias) is not a risk.
            ActivitySourceSpec::viaParentType('hamid_assessment_score', Assessment::class),
            ActivitySourceSpec::viaParentType('hamid_assessment_', Assessment::class),
            ActivitySourceSpec::viaParentType('hamid_companies', Company::class),
            ActivitySourceSpec::viaParentType('SZ_Study2', Lead::class),
            ActivitySourceSpec::viaParentType('SZ_Study', Lead::class),
            ActivitySourceSpec::viaParentType('SZ_USA', Lead::class),
            ActivitySourceSpec::viaParentType('hamid_express_entry_requests', Lead::class),
            ActivitySourceSpec::viaParentType('hamid_newsletter', NewsletterSubscriber::class),
            ActivitySourceSpec::viaParentType('hamid_immcan', Lead::class),
            ActivitySourceSpec::viaParentType('hamid_bd1', Lead::class),
        ];
    }
}
