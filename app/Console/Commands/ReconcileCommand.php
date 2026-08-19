<?php

namespace App\Console\Commands;

use App\Models\Assessment;
use App\Models\Client;
use App\Models\Company;
use App\Models\Lead;
use App\Models\NewsletterSubscriber;
use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * BACKEND_BRIEF §13 / Z-6.4 — the acceptance bar for `crm:migrate-legacy`.
 * Prints target/loaded/difference per entity against the audited source
 * counts in §13, exiting non-zero on ANY mismatch (never a tolerance band —
 * a human reviews the printed diff and judges whether a gap is the known
 * irreducible "FK references a user id that never existed in the legacy
 * source at all" class or a real regression).
 *
 * "Loaded" is computed fresh against the real databases every run, not
 * remembered from any past `crm:migrate-legacy` invocation: for each
 * audited line, every active (deleted=0) row in its legacy source table(s)
 * is checked for a matching preserved-id row in the target table. Several
 * lines combine more than one legacy table — e.g. "in-Canada" spans
 * ga_imm_can/ga_immcan1/ga_immcan2/ga_immcan3, "BD1" spans ga_bd1/ga_bd2/
 * ga_client_development1 — because BACKEND_BRIEF audits those dedup groups
 * as one combined figure, not per source table.
 */
final class ReconcileCommand extends Command
{
    protected $signature = 'crm:reconcile';

    protected $description = 'Reconcile migrated record counts against BACKEND_BRIEF §13\'s audited targets (Z-6.4)';

    /**
     * @var list<array{label: string, target: int, legacyTables: list<string>, modelClass: class-string<Model>}>
     */
    private const TARGETS = [
        ['label' => 'companies', 'target' => 21014, 'legacyTables' => ['ga_companies'], 'modelClass' => Company::class],
        ['label' => 'assessment scores', 'target' => 8147, 'legacyTables' => ['ga_assessment_score'], 'modelClass' => Assessment::class],
        ['label' => 'study', 'target' => 4782, 'legacyTables' => ['ga_study'], 'modelClass' => Lead::class],
        ['label' => 'LMIA course', 'target' => 4320, 'legacyTables' => ['ga_lmia_course'], 'modelClass' => Lead::class],
        ['label' => 'newsletter', 'target' => 2023, 'legacyTables' => ['ga_newsletter_subscriber'], 'modelClass' => NewsletterSubscriber::class],
        ['label' => 'in-Canada', 'target' => 785, 'legacyTables' => ['ga_imm_can', 'ga_immcan1', 'ga_immcan2', 'ga_immcan3'], 'modelClass' => Lead::class],
        ['label' => 'applicant', 'target' => 560, 'legacyTables' => ['ga_applicant'], 'modelClass' => Lead::class],
        ['label' => 'students', 'target' => 548, 'legacyTables' => ['ga_hq_students'], 'modelClass' => Student::class],
        ['label' => 'USA', 'target' => 535, 'legacyTables' => ['ga_usa'], 'modelClass' => Lead::class],
        ['label' => 'assessment requests', 'target' => 484, 'legacyTables' => ['ga_assessment_request'], 'modelClass' => Assessment::class],
        ['label' => 'BD1', 'target' => 404, 'legacyTables' => ['ga_bd1', 'ga_bd2', 'ga_client_development1'], 'modelClass' => Lead::class],
        ['label' => 'leads', 'target' => 394, 'legacyTables' => ['ga_galead'], 'modelClass' => Lead::class],
        ['label' => 'express entry', 'target' => 290, 'legacyTables' => ['ga_expressentryrequests'], 'modelClass' => Lead::class],
        ['label' => 'clients', 'target' => 265, 'legacyTables' => ['ga_clients'], 'modelClass' => Client::class],
        ['label' => 'study permit requests', 'target' => 115, 'legacyTables' => ['ga_studypermitrequests'], 'modelClass' => Lead::class],
        ['label' => 'business immigration', 'target' => 112, 'legacyTables' => ['ga_imm_biz'], 'modelClass' => Lead::class],
    ];

    public function handle(): int
    {
        $mismatch = false;
        $rows = [];

        foreach (self::TARGETS as $row) {
            $loaded = $this->loadedCount($row['legacyTables'], $row['modelClass']);
            $diff = $loaded - $row['target'];
            if ($diff !== 0) {
                $mismatch = true;
            }

            $rows[] = [$row['label'], $row['target'], $loaded, $diff];
        }

        $this->table(['Entity', 'Target', 'Loaded', 'Diff'], $rows);

        if ($mismatch) {
            $this->error('Reconciliation found a mismatch -- review the diff column above.');
        } else {
            $this->info('Reconciliation clean -- every audited target matches.');
        }

        return $mismatch ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<string>  $legacyTables
     * @param  class-string<Model>  $modelClass
     */
    private function loadedCount(array $legacyTables, string $modelClass): int
    {
        $ids = [];
        foreach ($legacyTables as $table) {
            $ids = [...$ids, ...DB::connection('legacy')->table($table)->where('deleted', 0)->pluck('id')->all()];
        }

        $ids = array_values(array_unique(array_filter($ids, 'is_string')));
        if ($ids === []) {
            return 0;
        }

        return $modelClass::withoutGlobalScopes()->whereIn('id', $ids)->count();
    }
}
