<?php

/**
 * The legacy `/Api/V8/*` adapter's alias maps — docs/contracts/api-contract.md
 * Part 2. Kept in one file per §2.4 ("The alias map lives in one config file so it
 * can be read, tested and eventually deleted in one place") so the whole adapter
 * can be deleted in one place too, once the 133 n8n workflows have migrated to
 * `/api/v1` and this file (plus app/Support/LegacyApi and the V8 controller/routes)
 * is no longer needed.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Module aliases (§2.3)
    |--------------------------------------------------------------------------
    |
    | Each legacy module name maps to a v1 module key, optionally forcing a fixed
    | Lead `vertical` on every read (filtered) and write (set, overriding whatever
    | the payload sent). `GA_GALead` has no fixed vertical — its vertical comes
    | from the aliased `category_c` attribute instead (§2.4).
    |
    | A `module` of null is a *recognised* legacy name with no v1 module behind it
    | yet (the six activity modules — Email/Note/etc. are real entities but are
    | not yet metadata-registered or ACL-scoped, see Z-5.5 PR notes) — the adapter
    | reports these as 404 rather than crashing on an unresolvable module.
    |
    | `gone => true` produces a 410, per §2.3's `dt_sms` row.
    |
    */
    'modules' => [
        'GA_GALead' => ['module' => 'leads', 'vertical' => null],
        'GA_Imm_Biz' => ['module' => 'leads', 'vertical' => 'BusinessImmigration'],
        'GA_Imm_can' => ['module' => 'leads', 'vertical' => 'InCanada'],
        'GA_HQInvestor_' => ['module' => 'leads', 'vertical' => 'Investor'],
        'GA_Study' => ['module' => 'leads', 'vertical' => 'StudyPermit'],
        'GA_LMIA_MAIN' => ['module' => 'leads', 'vertical' => 'LMIA'],
        'GA_LMIA_Course' => ['module' => 'leads', 'vertical' => 'LMIA'],
        'GA_LMIAInquiry' => ['module' => 'leads', 'vertical' => 'LMIA'],

        'GA_HQ_Students' => ['module' => 'students', 'vertical' => null],
        'GA_Companies' => ['module' => 'companies', 'vertical' => null],
        'GA_Assessment_Score' => ['module' => 'assessments', 'vertical' => null],
        'GA_Assessment_Request' => ['module' => 'assessments', 'vertical' => null],
        'GA_Clients' => ['module' => 'clients', 'vertical' => null],
        'GA_ClientDevelopment2' => ['module' => 'clients', 'vertical' => null],
        'GA_ClientDevelopment3' => ['module' => 'clients', 'vertical' => null],
        'GA_Affiliate' => ['module' => 'affiliates', 'vertical' => null],
        'GA_Newsletter_Subscriber' => ['module' => 'newsletter_subscribers', 'vertical' => null],

        'Emails' => ['module' => null, 'vertical' => null],
        'Notes' => ['module' => null, 'vertical' => null],
        'Meetings' => ['module' => null, 'vertical' => null],
        'Tasks' => ['module' => null, 'vertical' => null],
        'Documents' => ['module' => null, 'vertical' => null],
        'Calls' => ['module' => null, 'vertical' => null],

        'dt_sms' => ['gone' => true, 'message' => 'The SMS module is not part of this system.'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Field aliases (§2.4)
    |--------------------------------------------------------------------------
    |
    | Legacy field name => canonical field name. Any field not listed here is
    | assumed identical between legacy and canonical (most fields — first_name,
    | phone_mobile, status, ... — already match). `deleted` is handled separately
    | (LegacyFieldAliasResolver) since it is a computed soft-delete flag, not a
    | real column.
    |
    */
    'field_aliases' => [
        'email1' => 'primary_email',
        'date_entered' => 'created_at',
        'date_modified' => 'updated_at',
        'category_c' => 'vertical',
        'lead_status_c' => 'stage',
        'hot_lead_c' => 'hot_lead',
        'warm_lead_c' => 'warm_lead',
        'whatsapp_number_c' => 'whatsapp_number',
        'call_status_c' => 'call_status',
        'call_attempts_c' => 'call_attempts',
        'last_call_outcome_c' => 'last_call_outcome',
        'last_call_summary_c' => 'last_call_summary',
        'last_contacted_at_c' => 'last_contacted_at',
    ],

    /*
    |--------------------------------------------------------------------------
    | Vertical-attribute aliases (§2.4, last row)
    |--------------------------------------------------------------------------
    |
    | These legacy fields don't map to a real column at all — they live inside
    | the `vertical_attributes` JSON bag (Z-2.4). Legacy key => the key they're
    | stored under inside vertical_attributes. `best_time_to_call_*_c` is a
    | wildcard family handled by pattern match in LegacyFieldAliasResolver, not
    | listed here individually.
    |
    */
    'vertical_attribute_aliases' => [
        'own_business_bi_c' => 'own_business_bi',
        'invest_in_canada' => 'invest_in_canada',
        'interested_in_pr' => 'interested_in_pr',
        'current_situation' => 'current_situation',
        'afraid_to_return' => 'afraid_to_return',
        'refugee_claim_process' => 'refugee_claim_process',
        'current_status_in_canada_h_c' => 'current_status_in_canada_h',
        'seeking_a_humanitarian_pr_c' => 'seeking_a_humanitarian_pr',
        'start_your_application_h_c' => 'start_your_application_h',
    ],

    /*
    |--------------------------------------------------------------------------
    | Max page size (§2.2 rule 5)
    |--------------------------------------------------------------------------
    */
    'max_page_size' => 1000,
];
