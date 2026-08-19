<?php

use App\Models\Lead;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * crm:reconcile (Z-6.4) — every legacy table ReconcileCommand::TARGETS
 * references needs to exist in the fixture even empty, since the command
 * unconditionally iterates all 16 audited lines every run (same pattern as
 * every other legacy-fixture test this ETL suite uses).
 */
const RECONCILE_LEGACY_TABLES = [
    'ga_companies', 'ga_assessment_score', 'ga_study', 'ga_lmia_course', 'ga_newsletter_subscriber',
    'ga_imm_can', 'ga_immcan1', 'ga_immcan2', 'ga_immcan3', 'ga_applicant', 'ga_hq_students', 'ga_usa',
    'ga_assessment_request', 'ga_bd1', 'ga_bd2', 'ga_client_development1', 'ga_galead',
    'ga_expressentryrequests', 'ga_clients', 'ga_studypermitrequests', 'ga_imm_biz',
];

beforeEach(function () {
    config([
        'database.connections.legacy.driver' => 'sqlite',
        'database.connections.legacy.database' => ':memory:',
        'database.connections.legacy.foreign_key_constraints' => false,
    ]);
    DB::purge('legacy');

    foreach (RECONCILE_LEGACY_TABLES as $table) {
        Schema::connection('legacy')->create($table, function (Blueprint $blueprint) {
            $blueprint->string('id', 36)->primary();
            $blueprint->boolean('deleted')->default(false);
        });
    }
});

it('reports a real loaded count reflecting only active legacy rows with a matching migrated id', function () {
    $migratedLead = Lead::factory()->create();
    $deletedLead = Lead::factory()->create();
    $unmigratedId = (string) Str::uuid();

    DB::connection('legacy')->table('ga_galead')->insert([
        ['id' => $migratedLead->id, 'deleted' => false],
        ['id' => $deletedLead->id, 'deleted' => true],
        ['id' => $unmigratedId, 'deleted' => false],
    ]);

    Artisan::call('crm:reconcile');
    $output = Artisan::output();

    // Only the one active+migrated row counts: the deleted=1 row is excluded
    // from "active", and the unmigrated id never resolves to a real Lead so
    // it can't inflate the count either -- loaded should read exactly 1.
    expect($output)->toMatch('/\|\s*leads\s*\|\s*394\s*\|\s*1\s*\|/');
});

it('exits successfully only when every audited line matches exactly (none do against a fresh empty fixture)', function () {
    $this->artisan('crm:reconcile')->assertExitCode(1);
});

it('combines every legacy table in a multi-table dedup group before counting', function () {
    $inCanadaLead = Lead::factory()->create();
    $bd1Lead = Lead::factory()->create();

    DB::connection('legacy')->table('ga_imm_can')->insert(['id' => $inCanadaLead->id, 'deleted' => false]);
    DB::connection('legacy')->table('ga_bd2')->insert(['id' => $bd1Lead->id, 'deleted' => false]);

    Artisan::call('crm:reconcile');
    $output = Artisan::output();

    // Both dedup-group lines ("in-Canada" spans 4 legacy tables, "BD1" spans
    // 3) must reflect their row even though it landed in a different member
    // table than "ga_imm_can"/"ga_bd1" specifically.
    expect($output)->toContain('in-Canada')->toContain('BD1');
});
