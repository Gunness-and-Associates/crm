<?php

namespace App\Console\Commands;

use App\Models\Metadata\Field;
use App\Models\Metadata\Layout;
use App\Models\Metadata\Module;
use App\Support\Etl\LegacyViewDefReader;
use App\Support\SchemaManager\FieldChangeRequest;
use App\Support\SchemaManager\SchemaManager;
use App\Support\SchemaManager\SchemaValidationException;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * BACKEND_BRIEF §13 / Z-6.3 — imports legacy Studio custom fields (the
 * `fields_meta_data` table) as real metadata `fields`, creating their backing
 * `{table}_custom` sidecar column via SchemaManager (the same path a live
 * Studio "add field" action would take).
 *
 * Deliberately metadata only, not data: `crm:migrate-legacy` (Z-6.2) already
 * decides per source column whether its VALUE is promoted to a base column,
 * folded into Lead's `vertical_attributes` bag, or dropped as a dead
 * duplicate. Re-importing every legacy custom field verbatim would recreate
 * confusing, redundant columns next to ones that already hold the same data
 * under a different name -- verified empirically against the real migrated
 * data and every transformer's source (2026-08-19): of the 92 real
 * `fields_meta_data` rows, only 60 belong to a module with a migrated target
 * at all (the rest are dead stock modules -- Accounts/Cases/Leads/Contacts/
 * Meetings/Opportunities/Project/Prospects -- same class of gap as the
 * activities' native parent_type), and of those 60, all but 6 are already
 * captured: `hot_lead_c`/`warm_lead_c`/`decline_reason_c`/`last_contacted_at_c`
 * promoted to real base columns; `status_c`/`lead_status_c` drive `stage` or
 * `company_contact_status`/`client_status`; every other `_c` name appears
 * verbatim (minus the trailing `_c`) as a real key inside real migrated
 * `vertical_attributes` JSON, or is referenced in a LeadModuleSpec's
 * verticalAttributeColumns list even where real data for it happens to be
 * empty. self::ALREADY_CAPTURED lists every excluded name with which of
 * those reasons applies.
 *
 * SuiteCRM's `relate` field type has no storage of its own -- a relate row's
 * real value lives in a *separate* `id`-type row (named in its `ext3`
 * column). Both genuinely-new relate pairs here point at Users with no
 * migrated `users` metadata Module to attach to yet, so this command
 * registers one first.
 */
final class ImportStudioMetadataCommand extends Command
{
    protected $signature = 'crm:import-studio-metadata {--dry-run} {--only=}';

    protected $description = 'Import legacy Studio custom fields (fields_meta_data) into the metadata registry (BACKEND_BRIEF §13/Z-6.3)';

    /**
     * custom_module -> already-migrated metadata module key. Every real
     * `fields_meta_data.custom_module` value with a migrated target, verified
     * against `crm:migrate-legacy`'s own emailBeanModule/LeadModuleSpec
     * registrations (2026-08-19). Deliberately excludes the dead stock
     * modules (Leads, Accounts, Contacts, Cases, Meetings, Opportunities,
     * Project, Prospects) -- same "no migrated target, skip entirely" policy
     * already used for activities.
     */
    private const MODULE_KEY_BY_CUSTOM_MODULE = [
        'GA_GALead' => 'leads',
        'GA_Imm_Biz' => 'leads',
        'GA_LMIA_MAIN' => 'leads',
        'GA_Imm_can' => 'leads',
        'GA_Study' => 'leads',
        'GA_GunnessAssociates' => 'leads',
        'GA_HQInvestor_' => 'leads',
        'GA_LMIA_Course' => 'leads',
        'GA_Associates' => 'leads',
        'GA_canadaVisa' => 'leads',
        'GA_Companies' => 'companies',
        'GA_HQ_Students' => 'students',
        'GA_Affiliate' => 'affiliates',
        'GA_Clients' => 'clients',
    ];

    /**
     * (module key, field name) already represented in the target data —
     * verified 2026-08-19 against the real migrated database and every
     * Contactable/Lead transformer's source, per the class docblock.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const ALREADY_CAPTURED = [
        // Promoted to a real base column.
        ['leads', 'hot_lead_c'], ['leads', 'warm_lead_c'], ['leads', 'decline_reason_c'], ['leads', 'last_contacted_at_c'],
        ['leads', 'whatsapp_number_c'], ['leads', 'lead_status_c'],
        // status_c is also a stageColumn for four leads-mapped source modules
        // (GA_LMIA_MAIN, GA_LMIA_Course, GA_canadaVisa, GA_Associates) -- drives
        // `stage`, same as lead_status_c above.
        ['leads', 'status_c'],
        ['companies', 'status_c'], ['companies', 'hot_lead_c'], ['companies', 'warm_lead_c'],
        ['companies', 'email1_c'], ['companies', 'pnp_program_c'], ['companies', 'resume_submitted_c'],
        ['clients', 'status_c'],
        ['students', 'hot_lead_c'], ['students', 'warm_lead_c'],
        ['affiliates', 'commission_c'], ['affiliates', 'status_c'],
        // Verbatim (minus trailing _c) as a real key of Lead.vertical_attributes,
        // or referenced in a LeadModuleSpec's verticalAttributeColumns even
        // where real data for it happens to be empty -- the ETL pipe already
        // exists regardless of current data.
        ['leads', 'category_c'], ['leads', 'source_details_c'], ['leads', 'date_of_birth2_c'], ['leads', 'date_of_birth_c'],
        ['leads', 'have_invest_c'], ['leads', 'current_status_in_canada_h_c'], ['leads', 'seeking_a_humanitarian_pr_c'],
        ['leads', 'start_your_application_h_c'], ['leads', 'best_time_to_call_c'], ['leads', 'best_time_to_call_r_c'],
        ['leads', 'best_time_to_call_bi_c'], ['leads', 'best_time_to_call_ss_c'], ['leads', 'own_business_bi_c'],
        ['leads', 'call_status_c'], ['leads', 'call_attempts_c'], ['leads', 'last_call_summary_c'],
        ['leads', 'last_call_outcome_c'], ['leads', 'immigration_goal_c'], ['leads', 'net_worth_range_c'],
        ['leads', 'username_c'], ['leads', 'amount_c'], ['leads', 'ga_affiliate_id_c'],
        // The relate row has no storage of its own (see class docblock) --
        // only its shadow id row matters, and that is the ga_affiliate_id_c
        // entry just above.
        ['leads', 'affiliate_c'],
    ];

    /**
     * Legacy relate/id pairs that ARE genuinely new. SuiteCRM splits one
     * relationship into a "relate" row (display-only, ext2 = target module)
     * and a separate "id" row (ext3-named, the real storage) -- collapsed
     * here into one real `relate`-type field, named for the relationship
     * rather than reusing the generic legacy id-column name.
     *
     * @var array<string, array{fieldName: string, idColumn: string, relatedModuleKey: string, relatedDisplayField: string}>
     */
    private const RELATE_PAIRS = [
        'leads.previously_assigned_to_c' => ['fieldName' => 'previously_assigned_to_id', 'idColumn' => 'user_id_c', 'relatedModuleKey' => 'users', 'relatedDisplayField' => 'name'],
        'companies.recruiter_c' => ['fieldName' => 'recruiter_id', 'idColumn' => 'user_id_c', 'relatedModuleKey' => 'users', 'relatedDisplayField' => 'name'],
    ];

    /**
     * Target module -> the ONE legacy module whose view-defs describe it.
     * Deliberately excludes every consolidated entity (leads: 23 source GA_*
     * modules; assessments: 2) -- there is no single legacy view-def to
     * import for those, and Leads already has a hand-curated layout from
     * Z-1.5 for exactly that reason. Only entities with a clean 1:1 legacy
     * source module are covered here.
     *
     * @var array<string, string>
     */
    private const LEGACY_MODULE_BY_TARGET_MODULE = [
        'companies' => 'GA_Companies',
        'affiliates' => 'GA_Affiliate',
        'students' => 'GA_HQ_Students',
        'clients' => 'GA_Clients',
        'newsletter_subscribers' => 'GA_Newsletter_Subscriber',
    ];

    /**
     * Legacy view-def column/field names that don't match a real target
     * field name directly or after stripping a trailing `_c`, verified
     * against the real legacy files and this app's registered Contactable
     * base fields (2026-08-19). `name` -> `full_name` is SuiteCRM's own
     * "Name" list-view column, backed here by the same virtual field every
     * Contactable module already registers (see MetadataFixtureSeeder).
     *
     * @var array<string, string>
     */
    private const NAME_ALIASES = [
        'name' => 'full_name',
        'email1' => 'primary_email',
        'email' => 'primary_email',
        'date_entered' => 'created_at',
        'date_modified' => 'updated_at',
        'created_by_name' => 'created_by',
        'assigned_user_name' => 'assigned_user_id',
        'address_street' => 'primary_address_street',
        'address_city' => 'primary_address_city',
        'address_state' => 'primary_address_state',
        'address_postalcode' => 'primary_address_postalcode',
        'address_country' => 'primary_address_country',
    ];

    public function __construct(
        private readonly SchemaManager $schemaManager,
        private readonly LegacyViewDefReader $viewDefReader,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->stringOption('only');

        $fieldsOk = $only === null || $only === 'fields' ? $this->importFields($dryRun) : true;
        $layoutsOk = $only === null || $only === 'layouts' ? $this->importLayouts($dryRun) : true;

        return $fieldsOk && $layoutsOk ? self::SUCCESS : self::FAILURE;
    }

    private function importFields(bool $dryRun): bool
    {
        // Not gated on $dryRun: this registers our OWN metadata Module row
        // (no legacy data touched), a prerequisite the two relate fields'
        // related_module_id needs to validate at all -- without it, even a
        // --dry-run would misreport them as errors before the 'users'
        // module ever exists to point at.
        Module::query()->firstOrCreate(
            ['key' => 'users'],
            ['label' => 'Users', 'table_name' => 'users', 'base_type' => 'person', 'enabled' => true, 'is_system' => true],
        );

        $rows = DB::connection('legacy')->table('fields_meta_data')
            ->where('deleted', 0)
            ->whereIn('custom_module', array_keys(self::MODULE_KEY_BY_CUSTOM_MODULE))
            ->orderBy('custom_module')
            ->orderBy('name')
            ->get();

        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach ($this->candidates($rows) as $candidate) {
            [$moduleKey, $name, $request] = $candidate;

            if (Field::query()->withTrashed()->whereHas('module', fn ($q) => $q->where('key', $moduleKey))->where('name', $request->name)->exists()) {
                $skipped++;

                continue;
            }

            try {
                // plan() validates with no side effects -- run it even in
                // dry-run mode so a dry-run surfaces the same errors a real
                // run would, instead of only counting rows.
                $plan = $this->schemaManager->plan($request);
                if (! $dryRun) {
                    $this->schemaManager->apply($plan, null);
                }
                $created++;
            } catch (SchemaValidationException $e) {
                $errors[] = "{$moduleKey}.{$name}: ".implode('; ', $e->errors);
            }
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info(sprintf('%sstudio_metadata_fields: created=%d skipped=%d errors=%d', $prefix, $created, $skipped, count($errors)));
        foreach ($errors as $error) {
            $this->warn("  {$error}");
        }

        return $errors === [];
    }

    private function importLayouts(bool $dryRun): bool
    {
        $created = 0;
        $skipped = 0;

        foreach (self::LEGACY_MODULE_BY_TARGET_MODULE as $moduleKey => $legacyModule) {
            $module = Module::query()->where('key', $moduleKey)->first();
            if (! $module instanceof Module) {
                continue;
            }

            $realFieldNames = $this->stringList(Field::query()->where('module_id', $module->id)->pluck('name')->all());

            foreach ($this->buildLayouts($legacyModule, $realFieldNames) as $view => $definition) {
                $definition['module'] = $moduleKey;

                $exists = Layout::query()->where('module_id', $module->id)->where('view', $view)->exists();
                if ($exists) {
                    $skipped++;

                    continue;
                }

                if (! $dryRun) {
                    Layout::query()->create([
                        'module_id' => $module->id,
                        'view' => $view,
                        // Mechanically translated, never hand-reviewed -- never
                        // published as if it were a curated, approved layout.
                        'definition' => $definition,
                        'version' => 1,
                        'is_published' => false,
                    ]);
                }
                $created++;
            }
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info(sprintf('%sstudio_metadata_layouts: created=%d skipped=%d', $prefix, $created, $skipped));

        return true;
    }

    /**
     * @param  list<string>  $realFieldNames
     * @return array<string, array<string, mixed>> view => layout.schema.json content (module key not yet set)
     */
    private function buildLayouts(string $legacyModule, array $realFieldNames): array
    {
        $layouts = [];

        $list = $this->buildColumns($this->viewDefReader->listView($legacyModule) ?? [], $realFieldNames);
        if ($list !== null) {
            $layouts['list'] = ['version' => 1, 'view' => 'list', 'content' => $list];
        }

        $search = $this->buildColumns($this->viewDefReader->searchView($legacyModule) ?? [], $realFieldNames);
        if ($search !== null) {
            $layouts['search'] = ['version' => 1, 'view' => 'search', 'content' => $search];
        }

        $detail = $this->buildPanels($this->viewDefReader->detailView($legacyModule) ?? [], $realFieldNames);
        if ($detail !== null) {
            $layouts['detail'] = ['version' => 1, 'view' => 'detail', 'content' => $detail];
        }

        $edit = $this->buildPanels($this->viewDefReader->editView($legacyModule) ?? [], $realFieldNames);
        if ($edit !== null) {
            $layouts['edit'] = ['version' => 1, 'view' => 'edit', 'content' => $edit];
        }

        return $layouts;
    }

    /**
     * List/search views: a flat dict keyed by column/field name.
     *
     * @param  array<string, mixed>  $rawColumns
     * @param  list<string>  $realFieldNames
     * @return array<string, mixed>|null
     */
    private function buildColumns(array $rawColumns, array $realFieldNames): ?array
    {
        $columns = [];
        $linkAssigned = false;

        foreach ($rawColumns as $key => $rawDef) {
            if (! is_array($rawDef)) {
                continue;
            }

            $field = $this->resolveFieldName((string) $key, $realFieldNames);
            if ($field === null) {
                continue;
            }

            $column = ['field' => $field, 'priority' => ($rawDef['default'] ?? false) === true ? 1 : 3];
            if (($rawDef['link'] ?? false) === true && ! $linkAssigned) {
                $column['link'] = true;
                $linkAssigned = true;
            }

            $columns[] = $column;
        }

        return $columns === [] ? null : ['columns' => $columns];
    }

    /**
     * Detail/edit views: a dict of panel key -> list of rows, each row a
     * list of 1-2 field-slot dicts -- already the same shape
     * layout.schema.json's `panels[].rows` expects, so this only needs to
     * resolve field names and drop anything unresolved (dropping an empty
     * row, then an empty panel, when everything inside it was unresolved).
     *
     * @param  array<string, mixed>  $rawPanels
     * @param  list<string>  $realFieldNames
     * @return array<string, mixed>|null
     */
    private function buildPanels(array $rawPanels, array $realFieldNames): ?array
    {
        $panels = [];
        $order = 0;

        foreach ($rawPanels as $panelKey => $rawRows) {
            if ($panelKey === 'templateMeta' || ! is_array($rawRows)) {
                continue;
            }

            $rows = [];
            foreach ($rawRows as $rawRow) {
                if (! is_array($rawRow)) {
                    continue;
                }

                $slots = [];
                foreach ($rawRow as $rawSlot) {
                    if (! is_array($rawSlot) || ! isset($rawSlot['name']) || ! is_string($rawSlot['name'])) {
                        continue;
                    }

                    $field = $this->resolveFieldName($rawSlot['name'], $realFieldNames);
                    if ($field !== null) {
                        $slots[] = ['field' => $field];
                    }
                }

                if ($slots !== [] && count($slots) <= 2) {
                    $rows[] = $slots;
                }
            }

            if ($rows === []) {
                continue;
            }

            $panels[] = [
                'key' => $this->panelKey((string) $panelKey),
                'label' => $this->panelLabel((string) $panelKey),
                'order' => $order++,
                'rows' => $rows,
            ];
        }

        return $panels === [] ? null : ['panels' => $panels];
    }

    /**
     * @param  list<string>  $realFieldNames
     */
    private function resolveFieldName(string $legacyName, array $realFieldNames): ?string
    {
        $lower = strtolower($legacyName);
        if (in_array($lower, $realFieldNames, true)) {
            return $lower;
        }

        $stripped = str_ends_with($lower, '_c') ? substr($lower, 0, -2) : $lower;
        if ($stripped !== $lower && in_array($stripped, $realFieldNames, true)) {
            return $stripped;
        }

        $alias = self::NAME_ALIASES[$lower] ?? self::NAME_ALIASES[$stripped] ?? null;

        return $alias !== null && in_array($alias, $realFieldNames, true) ? $alias : null;
    }

    private function panelKey(string $legacyPanelKey): string
    {
        $key = strtolower($legacyPanelKey);
        $key = str_starts_with($key, 'lbl_') ? substr($key, 4) : $key;
        $key = preg_replace('/[^a-z0-9_]/', '_', $key) ?? $key;

        return $key === '' || ! preg_match('/^[a-z]/', $key) ? 'panel_'.$key : $key;
    }

    private function panelLabel(string $legacyPanelKey): string
    {
        $key = strtolower($legacyPanelKey);
        $key = str_starts_with($key, 'lbl_') ? substr($key, 4) : $key;

        return Str::of($key)->replace('_', ' ')->title()->toString();
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return list<array{0: string, 1: string, 2: FieldChangeRequest}>
     */
    private function candidates(Collection $rows): array
    {
        $seenModuleAndName = [];
        $candidates = [];

        foreach ($rows as $raw) {
            $row = $this->stringKeyed($raw);
            $customModule = $this->stringValue($row['custom_module'] ?? null);
            $name = $this->stringValue($row['name'] ?? null);
            $type = $this->stringValue($row['type'] ?? null);
            $moduleKey = self::MODULE_KEY_BY_CUSTOM_MODULE[$customModule] ?? null;
            if ($moduleKey === null || $type === 'id') {
                // 'id'-type rows are only ever consumed as a relate pair's
                // shadow storage column, never registered on their own.
                continue;
            }

            if (in_array([$moduleKey, $name], self::ALREADY_CAPTURED, true)) {
                continue;
            }

            $dedupeKey = "{$moduleKey}.{$name}";
            if (isset($seenModuleAndName[$dedupeKey])) {
                // The same custom field name, contributed by more than one
                // legacy source module that consolidates onto this same
                // target module (e.g. status_c from several GA_* Lead
                // sources) -- register it once.
                continue;
            }
            $seenModuleAndName[$dedupeKey] = true;

            $relatePairKey = "{$moduleKey}.{$name}";
            if ($type === 'relate' && isset(self::RELATE_PAIRS[$relatePairKey])) {
                $candidates[] = [$moduleKey, $name, $this->relateRequest($moduleKey, self::RELATE_PAIRS[$relatePairKey])];

                continue;
            }

            if ($type === 'relate') {
                // A relate row with no known pairing -- nothing to store.
                continue;
            }

            $candidates[] = [$moduleKey, $name, $this->fieldRequest($moduleKey, $name, $type, $row)];
        }

        return $candidates;
    }

    /**
     * @param  array{fieldName: string, idColumn: string, relatedModuleKey: string, relatedDisplayField: string}  $pair
     */
    private function relateRequest(string $moduleKey, array $pair): FieldChangeRequest
    {
        return new FieldChangeRequest(
            action: 'add',
            moduleKey: $moduleKey,
            name: $pair['fieldName'],
            type: 'relate',
            options: [
                'related_module_id' => Module::query()->where('key', $pair['relatedModuleKey'])->value('id'),
                'related_display_field' => $pair['relatedDisplayField'],
                'help' => 'Imported from legacy Studio field.',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function fieldRequest(string $moduleKey, string $name, string $legacyType, array $row): FieldChangeRequest
    {
        $type = $this->mapType($legacyType, $name);

        $options = ['help' => 'Imported from legacy Studio field.'];
        if ($type === 'text') {
            $len = $row['len'] ?? null;
            $options['length'] = is_numeric($len) && (int) $len > 0 ? min((int) $len, 1000) : 255;
        }

        return new FieldChangeRequest(action: 'add', moduleKey: $moduleKey, name: $name, type: $type, options: $options);
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? (string) $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyed(\stdClass $row): array
    {
        $result = [];
        foreach ((array) $row as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Legacy `varchar`/`text` don't distinguish email/phone from plain text —
     * SuiteCRM encodes that in each field's own validation config, not in
     * `fields_meta_data.type`. The name itself is the only remaining signal,
     * the same way BACKEND_BRIEF's own hand-written metadata fixture types
     * `primary_email`/`phone_mobile` by name.
     */
    private function mapType(string $legacyType, string $name): string
    {
        if ($legacyType === 'varchar' && str_contains($name, 'email')) {
            return 'email';
        }

        if ($legacyType === 'varchar' && (str_contains($name, 'phone') || str_contains($name, 'whatsapp'))) {
            return 'phone';
        }

        return match ($legacyType) {
            'varchar' => 'text',
            'text' => 'textarea',
            'bool' => 'bool',
            'float' => 'decimal',
            'datetimecombo' => 'datetime',
            'enum' => 'enum',
            default => 'text',
        };
    }
}
