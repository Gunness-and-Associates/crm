<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Support\Etl\AffiliateTransformer;
use App\Support\Etl\AssessmentRequestTransformer;
use App\Support\Etl\AssessmentScoreTransformer;
use App\Support\Etl\BareContactableTransformer;
use App\Support\Etl\ClientDevelopment2Transformer;
use App\Support\Etl\ClientTransformer;
use App\Support\Etl\CompanyTransformer;
use App\Support\Etl\LeadModuleSpec;
use App\Support\Etl\LeadModuleTransformer;
use App\Support\Etl\LegacyTransformer;
use App\Support\Etl\MigrationResult;
use App\Support\Etl\NewsletterSubscriberTransformer;
use App\Support\Etl\StudentTransformer;
use App\Support\Etl\UserTransformer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * BACKEND_BRIEF §13 — runs locally against a copy, never production.
 *
 *   php artisan crm:migrate-legacy --dry-run        # reports counts, writes nothing
 *   php artisan crm:migrate-legacy --only=companies # one entity
 *   php artisan crm:migrate-legacy                  # idempotent, resumable
 *
 * The load order below matches §13's foreign-key dependency chain. Each
 * transformer owns its own source query and row mapping; this command owns the
 * shared mechanics only: batching (500 rows/transaction), `--from-id` resume,
 * idempotency (upsert keyed on the preserved source id), and dry-run reporting.
 */
final class MigrateLegacyCommand extends Command
{
    protected $signature = 'crm:migrate-legacy {--dry-run} {--only=} {--from-id=}';

    protected $description = 'Migrate the legacy crmga database into the new CRM (BACKEND_BRIEF §13)';

    private const BATCH_SIZE = 500;

    public function handle(
        UserTransformer $users,
        CompanyTransformer $companies,
        StudentTransformer $students,
        AssessmentRequestTransformer $assessmentRequests,
        AssessmentScoreTransformer $assessmentScores,
        ClientTransformer $clients,
        ClientDevelopment2Transformer $clientsDevelopment2,
        AffiliateTransformer $affiliates,
        NewsletterSubscriberTransformer $newsletterSubscribers,
    ): int {
        /** @var list<LegacyTransformer> $transformers */
        $transformers = [
            $users,
            $companies,
            ...$this->leadModuleTransformers(),
            ...$this->remainingLeadModuleTransformers(),
            $students,
            $assessmentRequests,
            $assessmentScores,
            $clients,
            $clientsDevelopment2,
            new BareContactableTransformer('clients_development3', 'ga_clientdevelopment3', 'GA_ClientDevelopment3', Client::class),
            new BareContactableTransformer('clients_imm_client', 'ga_imm_client', 'GA_Imm_Client', Client::class),
            $affiliates,
            $newsletterSubscribers,
            // Appended in load order as each is built: activities -> email
            // addresses -> audit.
        ];

        $only = $this->stringOption('only');
        $dryRun = (bool) $this->option('dry-run');
        $fromId = $this->stringOption('from-id');

        $matched = false;
        foreach ($transformers as $transformer) {
            if ($only !== null && $transformer->key() !== $only) {
                continue;
            }

            $matched = true;
            $this->migrate($transformer, $dryRun, $fromId);
        }

        if (! $matched) {
            $this->error("No transformer matches --only={$only}.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function migrate(LegacyTransformer $transformer, bool $dryRun, ?string $fromId): void
    {
        $result = new MigrationResult($transformer->key());
        $modelClass = $transformer->modelClass();

        $transformer->query($fromId)->orderBy('id')->chunk(
            self::BATCH_SIZE,
            function ($rows) use ($transformer, $modelClass, $dryRun, $result): void {
                DB::transaction(function () use ($rows, $transformer, $modelClass, $dryRun, $result): void {
                    foreach ($rows as $row) {
                        $this->migrateRow($this->stringKeyed($row), $transformer, $modelClass, $dryRun, $result);
                    }
                });
            },
        );

        $this->report($result, $dryRun);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  class-string<Model>  $modelClass
     */
    private function migrateRow(array $row, LegacyTransformer $transformer, string $modelClass, bool $dryRun, MigrationResult $result): void
    {
        $result->total++;
        $sourceId = $this->stringValue($row['id'] ?? null);

        try {
            $attributes = $transformer->transform($row);
        } catch (\Throwable $e) {
            $result->recordError($sourceId, $e->getMessage());

            return;
        }

        if ($attributes === null) {
            $result->skipped++;

            return;
        }

        if ($dryRun) {
            return;
        }

        try {
            // updateOrCreate() won't do — it mass-assigns via fill(), which drops
            // 'id' silently since it's never in $fillable, so the "preserve source
            // id, idempotent re-run" rule needs forceFill() instead.
            $id = $attributes['id'] ?? null;
            $found = $modelClass::withoutGlobalScopes()->find($id);
            $model = $found instanceof Model ? $found : new $modelClass;
            $wasNew = ! $found instanceof Model;

            // UserTransformer generates a fresh random "please reset" password
            // every call — re-running the migration must never clobber a real
            // password an admin has since set via the normal reset flow.
            if (! $wasNew) {
                unset($attributes['password']);
            }

            $model->forceFill($attributes)->save();
        } catch (\Throwable $e) {
            $result->recordError($sourceId, $e->getMessage());

            return;
        }

        if ($wasNew) {
            $result->created++;
        } else {
            $result->updated++;
        }
    }

    private function report(MigrationResult $result, bool $dryRun): void
    {
        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info(sprintf(
            '%s%s: total=%d created=%d updated=%d skipped=%d errors=%d',
            $prefix,
            $result->key,
            $result->total,
            $result->created,
            $result->updated,
            $result->skipped,
            count($result->errors),
        ));

        foreach ($result->errors as $error) {
            $this->warn("  [{$result->key}:{$error['id']}] {$error['message']}");
        }
    }

    /**
     * The 18 highest-value legacy lead modules (Z-6.2 part 1 — the 10 with a
     * named reconciliation target in BACKEND_BRIEF §13, plus the dedup-group
     * siblings that feed the same target: ga_immcan1/2/3 alongside ga_imm_can
     * for "in-Canada", ga_bd2/ga_client_development1 alongside ga_bd1 for
     * "BD1", and the full ga_lmia_* cluster for "LMIA course"). The remaining
     * ~10 smaller/untargeted modules (ga_canadavisa, ga_pnp, ga_refugee_book,
     * ga_entrepreneur, ga_resumes, ga_hqinvestor_, ga_gunnessassociates,
     * ga_associates, ga_inland, ...) land in a follow-up pass.
     *
     * Within each dedup group the named-target table is registered LAST, so
     * if a source id ever collided across sibling tables (none do in the
     * audited local dataset — verified 2026-08-18), the more-authoritative
     * table would win the overwrite rather than whichever happened to run
     * last by chance.
     *
     * @return list<LeadModuleTransformer>
     */
    private function leadModuleTransformers(): array
    {
        return array_map(
            fn (LeadModuleSpec $spec): LeadModuleTransformer => new LeadModuleTransformer($spec),
            [
                new LeadModuleSpec(
                    key: 'leads_galead',
                    table: 'ga_galead',
                    cstmTable: 'ga_galead_cstm',
                    emailBeanModule: 'GA_GALead',
                    fixedVertical: null,
                    verticalDeriveColumn: 'category_c',
                    stageColumn: 'lead_status_c',
                    hotLeadColumn: 'hot_lead_c',
                    warmLeadColumn: 'warm_lead_c',
                    verticalAttributeColumns: [
                        'current_status_in_canada', 'afraid_to_return', 'current_situation', 'best_describes',
                        'looking_to_start', 'interested_in_pr', 'invest_in_canada', 'start_your_pr_pathway',
                        'interested_in', 'start_your_application', 'refugee_claim_process',
                        'current_status_in_canada_h_c', 'seeking_a_humanitarian_pr_c', 'start_your_application_h_c',
                        'best_time_to_call_c', 'best_time_to_call_r_c', 'best_time_to_call_bi_c',
                        'best_time_to_call_ss_c', 'own_business_bi_c',
                    ],
                ),
                new LeadModuleSpec(
                    key: 'leads_imm_biz',
                    table: 'ga_imm_biz',
                    cstmTable: 'ga_imm_biz_cstm',
                    emailBeanModule: 'GA_Imm_Biz',
                    fixedVertical: 'BusinessImmigration',
                    verticalDeriveColumn: null,
                    stageColumn: 'lead_status',
                    hotLeadColumn: 'hot_lead_c',
                    warmLeadColumn: 'warm_lead_c',
                    declineReasonColumn: 'decline_reason_c',
                    lastContactedAtColumn: 'last_contacted_at_c',
                    verticalAttributeColumns: [
                        'status', 'canadian_permanent_residency', 'estimated_investment_budget',
                        'immigration_timeline', 'call_status_c', 'call_attempts_c', 'last_call_summary_c',
                        'last_call_outcome_c', 'immigration_goal_c', 'net_worth_range_c',
                    ],
                ),
                new LeadModuleSpec(
                    key: 'leads_immcan1',
                    table: 'ga_immcan1',
                    cstmTable: null,
                    emailBeanModule: 'GA_ImmCan1',
                    fixedVertical: 'InCanada',
                    verticalDeriveColumn: null,
                    stageColumn: 'status',
                    verticalAttributeColumns: ['status_details', 'source', 'source_details', 'referred_by', 'campaign_id_c', 'opportunity_amount'],
                ),
                new LeadModuleSpec(
                    key: 'leads_immcan2',
                    table: 'ga_immcan2',
                    cstmTable: null,
                    emailBeanModule: 'GA_ImmCan2',
                    fixedVertical: 'InCanada',
                    verticalDeriveColumn: null,
                    stageColumn: 'status',
                    verticalAttributeColumns: ['status_details', 'source', 'source_details', 'referred_by', 'campaign_id_c', 'opportunity_amount'],
                ),
                new LeadModuleSpec(
                    key: 'leads_immcan3',
                    table: 'ga_immcan3',
                    cstmTable: null,
                    emailBeanModule: 'GA_ImmCan3',
                    fixedVertical: 'InCanada',
                    verticalDeriveColumn: null,
                    stageColumn: 'status',
                    verticalAttributeColumns: ['status_details', 'source', 'source_details', 'referred_by', 'campaign_id_c', 'opportunity_amount'],
                ),
                new LeadModuleSpec(
                    key: 'leads_imm_can',
                    table: 'ga_imm_can',
                    cstmTable: 'ga_imm_can_cstm',
                    emailBeanModule: 'GA_Imm_can',
                    fixedVertical: 'InCanada',
                    verticalDeriveColumn: null,
                    stageColumn: 'status',
                    hotLeadColumn: 'hot_lead_c',
                    warmLeadColumn: 'warm_lead_c',
                    verticalAttributeColumns: [
                        'status_details', 'source', 'referred_by', 'referral_source', 'opportunity_amount',
                        'occupation', 'marital_status', 'length_of_stay', 'help_type', 'date_of_birth',
                        'campaign_id_c', 'source_details_c', 'date_of_birth2_c',
                    ],
                ),
                new LeadModuleSpec(
                    key: 'leads_usa',
                    table: 'ga_usa',
                    cstmTable: null,
                    emailBeanModule: 'GA_USA',
                    fixedVertical: 'USA',
                    verticalDeriveColumn: null,
                    stageColumn: 'status',
                    verticalAttributeColumns: ['help_type', 'cv', 'dob'],
                ),
                new LeadModuleSpec(
                    key: 'leads_expressentryrequests',
                    table: 'ga_expressentryrequests',
                    cstmTable: null,
                    emailBeanModule: 'GA_ExpressEntryRequests',
                    fixedVertical: 'ExpressEntry',
                    verticalDeriveColumn: null,
                    stageColumn: 'status',
                    verticalAttributeColumns: ['status_details', 'source_details', 'source', 'referred_by', 'opportunity_amount', 'occupation', 'field_of_study', 'campaign_id_c'],
                ),
                new LeadModuleSpec(
                    key: 'leads_studypermitrequests',
                    table: 'ga_studypermitrequests',
                    cstmTable: null,
                    emailBeanModule: 'GA_StudyPermitRequests',
                    fixedVertical: 'StudyPermit',
                    verticalDeriveColumn: null,
                    stageColumn: null,
                ),
                new LeadModuleSpec(
                    key: 'leads_bd2',
                    table: 'ga_bd2',
                    cstmTable: null,
                    emailBeanModule: 'GA_BD2',
                    fixedVertical: 'BusinessDevelopment',
                    verticalDeriveColumn: null,
                    stageColumn: null,
                ),
                new LeadModuleSpec(
                    key: 'leads_client_development1',
                    table: 'ga_client_development1',
                    cstmTable: null,
                    emailBeanModule: 'GA_Client_Development1',
                    fixedVertical: 'BusinessDevelopment',
                    verticalDeriveColumn: null,
                    stageColumn: null,
                ),
                new LeadModuleSpec(
                    key: 'leads_bd1',
                    table: 'ga_bd1',
                    cstmTable: null,
                    emailBeanModule: 'GA_BD1',
                    fixedVertical: 'BusinessDevelopment',
                    verticalDeriveColumn: null,
                    stageColumn: 'status',
                ),
                new LeadModuleSpec(
                    key: 'leads_applicant',
                    table: 'ga_applicant',
                    cstmTable: null,
                    emailBeanModule: 'GA_Applicant',
                    fixedVertical: 'General',
                    verticalDeriveColumn: null,
                    stageColumn: 'status',
                    verticalAttributeColumns: ['job_applied_for'],
                ),
                new LeadModuleSpec(
                    key: 'leads_study',
                    table: 'ga_study',
                    cstmTable: 'ga_study_cstm',
                    emailBeanModule: 'GA_Study',
                    fixedVertical: 'StudyPermit',
                    verticalDeriveColumn: null,
                    stageColumn: 'status',
                    verticalAttributeColumns: [
                        'qualified', 'program_interest', 'preferred_study_level', 'institution_type',
                        'highest_education_completed', 'field_of_study', 'active_leads', 'dob',
                    ],
                ),
                new LeadModuleSpec(
                    key: 'leads_lmiainquiry',
                    table: 'ga_lmiainquiry',
                    cstmTable: null,
                    emailBeanModule: 'GA_LMIAInquiry',
                    fixedVertical: 'LMIA',
                    verticalDeriveColumn: null,
                    stageColumn: 'status',
                ),
                new LeadModuleSpec(
                    key: 'leads_lmia_affiliate',
                    table: 'lmia_affiliate',
                    cstmTable: null,
                    emailBeanModule: 'GA_LMIA_Affiliate',
                    fixedVertical: 'LMIA',
                    verticalDeriveColumn: null,
                    stageColumn: null,
                    verticalAttributeColumns: ['affiliate_link', 'username'],
                ),
                new LeadModuleSpec(
                    key: 'leads_lmia_main',
                    table: 'ga_lmia_main',
                    cstmTable: 'ga_lmia_main_cstm',
                    emailBeanModule: 'GA_LMIA_MAIN',
                    fixedVertical: 'LMIA',
                    verticalDeriveColumn: null,
                    stageColumn: 'status_c',
                    verticalAttributeColumns: ['commission', 'ga_affiliate_id_c', 'username_c', 'amount_c'],
                ),
                new LeadModuleSpec(
                    key: 'leads_lmia_course',
                    table: 'ga_lmia_course',
                    cstmTable: 'ga_lmia_course_cstm',
                    emailBeanModule: 'GA_LMIA_Course',
                    fixedVertical: 'LMIA',
                    verticalDeriveColumn: null,
                    stageColumn: 'status_c',
                    verticalAttributeColumns: ['country', 'have_invest_c'],
                ),
            ],
        );
    }

    /**
     * The remaining ~10 smaller/untargeted legacy lead modules (Z-6.2 part 2)
     * — none of these have a named reconciliation target in BACKEND_BRIEF
     * §13, unlike the 18 in leadModuleTransformers().
     *
     * @return list<LeadModuleTransformer>
     */
    private function remainingLeadModuleTransformers(): array
    {
        return array_map(
            fn (LeadModuleSpec $spec): LeadModuleTransformer => new LeadModuleTransformer($spec),
            [
                new LeadModuleSpec(
                    key: 'leads_canadavisa',
                    table: 'ga_canadavisa',
                    cstmTable: 'ga_canadavisa_cstm',
                    emailBeanModule: 'GA_CanadaVisa',
                    fixedVertical: 'CanadaVisa',
                    verticalDeriveColumn: null,
                    stageColumn: 'status_c',
                ),
                new LeadModuleSpec(
                    key: 'leads_new_pnp_form',
                    table: 'ga_new_pnp_form',
                    cstmTable: null,
                    emailBeanModule: 'GA_New_PNP_Form',
                    fixedVertical: 'PNP',
                    verticalDeriveColumn: null,
                    stageColumn: null,
                    // Raw webform capture fields, kept for traceability even
                    // though lbl_phone/lbl_email duplicate the base columns.
                    verticalAttributeColumns: ['name', 'lbl_phone', 'lbl_lname', 'lbl_hearabout', 'lbl_email', 'lbl_country'],
                ),
                new LeadModuleSpec(
                    key: 'leads_pnp',
                    table: 'ga_pnp',
                    cstmTable: null,
                    emailBeanModule: 'GA_PNP',
                    fixedVertical: 'PNP',
                    verticalDeriveColumn: null,
                    stageColumn: null,
                ),
                new LeadModuleSpec(
                    key: 'leads_refugee_book',
                    table: 'ga_refugee_book',
                    cstmTable: null,
                    emailBeanModule: 'GA_Refugee_Book',
                    fixedVertical: 'Refugee',
                    verticalDeriveColumn: null,
                    stageColumn: 'status',
                ),
                new LeadModuleSpec(
                    key: 'leads_entrepreneur',
                    table: 'ga_entrepreneur',
                    cstmTable: null,
                    emailBeanModule: 'GA_Entrepreneur',
                    fixedVertical: 'Entrepreneur',
                    verticalDeriveColumn: null,
                    stageColumn: null,
                ),
                new LeadModuleSpec(
                    key: 'leads_resumes',
                    table: 'ga_resumes',
                    cstmTable: null,
                    emailBeanModule: 'GA_Resumes',
                    fixedVertical: 'Resume',
                    verticalDeriveColumn: null,
                    // status_id is a numeric FK to a lookup table, not a
                    // status label — canonicalising it against lead_stage
                    // would never match, so it stays raw in
                    // vertical_attributes instead of driving `stage`.
                    stageColumn: null,
                    verticalAttributeColumns: [
                        'document_name', 'filename', 'file_ext', 'file_mime_type', 'active_date',
                        'exp_date', 'category_id', 'subcategory_id', 'status_id', 'category',
                    ],
                ),
                new LeadModuleSpec(
                    key: 'leads_hqinvestor',
                    table: 'ga_hqinvestor_',
                    cstmTable: 'ga_hqinvestor__cstm',
                    emailBeanModule: 'GA_HQInvestor_',
                    fixedVertical: 'Investor',
                    verticalDeriveColumn: null,
                    stageColumn: 'status',
                    hotLeadColumn: 'hot_lead_c',
                    warmLeadColumn: 'warm_lead_c',
                    verticalAttributeColumns: ['participate', 'source'],
                ),
                new LeadModuleSpec(
                    key: 'leads_gunnessassociates',
                    table: 'ga_gunnessassociates',
                    cstmTable: 'ga_gunnessassociates_cstm',
                    emailBeanModule: 'GA_GunnessAssociates',
                    fixedVertical: 'General',
                    verticalDeriveColumn: null,
                    stageColumn: 'status',
                    hotLeadColumn: 'hot_lead_c',
                    warmLeadColumn: 'warm_lead_c',
                    verticalAttributeColumns: ['help_type', 'source'],
                ),
                new LeadModuleSpec(
                    key: 'leads_associates',
                    table: 'ga_associates',
                    cstmTable: 'ga_associates_cstm',
                    emailBeanModule: 'GA_Associates',
                    fixedVertical: 'General',
                    verticalDeriveColumn: null,
                    stageColumn: 'status_c',
                ),
                new LeadModuleSpec(
                    key: 'leads_inland',
                    table: 'ga_inland',
                    cstmTable: null,
                    emailBeanModule: 'GA_Inland',
                    fixedVertical: 'InCanada',
                    verticalDeriveColumn: null,
                    stageColumn: null,
                ),
            ],
        );
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyed(mixed $row): array
    {
        $array = (array) $row;
        $result = [];
        foreach ($array as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? (string) $value : '';
    }
}
