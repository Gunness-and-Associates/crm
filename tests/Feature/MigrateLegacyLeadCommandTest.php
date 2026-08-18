<?php

use App\Models\Lead;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * LeadModuleTransformer is configured per real module in MigrateLegacyCommand
 * (18 specs, Z-6.2 part 1) — these tests exercise the actual production specs
 * via --only=<key>, against an in-memory sqlite `legacy` connection carrying
 * just the columns each spec reads, same self-contained approach as the
 * Users/Companies ETL tests. `lead_vertical`/`lead_stage` must be seeded
 * before any Lead transform() call — LeadModuleTransformer loads them eagerly.
 */
beforeEach(function () {
    $this->seed(MetadataFixtureSeeder::class);

    config([
        'database.connections.legacy.driver' => 'sqlite',
        'database.connections.legacy.database' => ':memory:',
        'database.connections.legacy.foreign_key_constraints' => false,
    ]);
    DB::purge('legacy');

    Schema::connection('legacy')->create('ga_galead', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('assigned_user_id', 36)->nullable();
        $table->string('created_by', 36)->nullable();
        $table->string('modified_user_id', 36)->nullable();
        $table->string('current_status_in_canada')->nullable();
    });
    Schema::connection('legacy')->create('ga_galead_cstm', function (Blueprint $table) {
        $table->string('id_c', 36)->primary();
        $table->string('category_c')->nullable();
        $table->string('lead_status_c')->nullable();
        $table->boolean('hot_lead_c')->default(false);
        $table->boolean('warm_lead_c')->default(false);
        $table->string('best_time_to_call_c')->nullable();
    });

    Schema::connection('legacy')->create('ga_imm_biz', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('lead_status')->nullable();
        $table->string('status')->nullable();
        $table->string('immigration_timeline')->nullable();
    });
    Schema::connection('legacy')->create('ga_imm_biz_cstm', function (Blueprint $table) {
        $table->string('id_c', 36)->primary();
        $table->boolean('hot_lead_c')->default(false);
        $table->boolean('warm_lead_c')->default(false);
        $table->string('decline_reason_c')->nullable();
        $table->string('last_contacted_at_c')->nullable();
        $table->string('call_status_c')->nullable();
        $table->string('call_attempts_c')->nullable();
    });

    Schema::connection('legacy')->create('ga_studypermitrequests', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
    });

    Schema::connection('legacy')->create('ga_immcan1', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('first_name')->nullable();
        $table->string('status')->nullable();
        $table->string('source')->nullable();
    });

    Schema::connection('legacy')->create('email_addresses', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->string('email_address')->nullable();
        $table->boolean('deleted')->default(false);
    });
    Schema::connection('legacy')->create('email_addr_bean_rel', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->string('email_address_id', 36);
        $table->string('bean_id', 36);
        $table->string('bean_module')->nullable();
        $table->boolean('primary_address')->default(false);
        $table->boolean('deleted')->default(false);
    });
});

it('derives vertical from category_c for GA_GALead and keeps unmodelled fields in vertical_attributes', function () {
    DB::connection('legacy')->table('ga_galead')->insert([
        'id' => 'lead-1', 'first_name' => 'Amina', 'current_status_in_canada' => 'visitor',
    ]);
    DB::connection('legacy')->table('ga_galead_cstm')->insert([
        'id_c' => 'lead-1', 'category_c' => 'Refugee', 'lead_status_c' => 'New', 'hot_lead_c' => true, 'best_time_to_call_c' => 'evening',
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'leads_galead'])->assertExitCode(0);

    $lead = Lead::withoutGlobalScopes()->find('lead-1');
    expect($lead)->not->toBeNull()
        ->and($lead->vertical->value)->toBe('Refugee')
        ->and($lead->stage->value)->toBe('new')
        ->and($lead->hot_lead)->toBeTrue()
        ->and($lead->warm_lead)->toBeFalse()
        ->and($lead->source)->toBe('ga_galead')
        ->and($lead->vertical_attributes)->toBe([
            'current_status_in_canada' => 'visitor',
            'best_time_to_call' => 'evening',
        ]);
});

it('leaves vertical null when category_c does not match any known vertical', function () {
    DB::connection('legacy')->table('ga_galead')->insert(['id' => 'lead-2']);
    DB::connection('legacy')->table('ga_galead_cstm')->insert(['id_c' => 'lead-2', 'category_c' => 'NotARealVertical']);

    $this->artisan('crm:migrate-legacy', ['--only' => 'leads_galead'])->assertExitCode(0);

    expect(Lead::withoutGlobalScopes()->find('lead-2')->vertical)->toBeNull();
});

it('maps GA_Imm_Biz onto the fixed BusinessImmigration vertical and real decline_reason/last_contacted_at columns', function () {
    DB::connection('legacy')->table('ga_imm_biz')->insert([
        'id' => 'lead-3', 'lead_status' => 'Converted', 'immigration_timeline' => '6 months',
    ]);
    DB::connection('legacy')->table('ga_imm_biz_cstm')->insert([
        'id_c' => 'lead-3', 'decline_reason_c' => 'Not qualified', 'last_contacted_at_c' => '2026-01-15 10:00:00',
        'call_status_c' => 'Left voicemail', 'call_attempts_c' => '3',
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'leads_imm_biz'])->assertExitCode(0);

    $lead = Lead::withoutGlobalScopes()->find('lead-3');
    expect($lead->vertical->value)->toBe('BusinessImmigration')
        ->and($lead->stage->value)->toBe('converted')
        ->and($lead->decline_reason)->toBe('Not qualified')
        ->and($lead->last_contacted_at->format('Y-m-d H:i:s'))->toBe('2026-01-15 10:00:00')
        ->and($lead->vertical_attributes)->toBe([
            'immigration_timeline' => '6 months',
            'call_status' => 'Left voicemail',
            'call_attempts' => '3',
        ]);
});

it('defaults stage to new when a module has no status column at all', function () {
    DB::connection('legacy')->table('ga_studypermitrequests')->insert(['id' => 'lead-4', 'first_name' => 'Sam']);

    $this->artisan('crm:migrate-legacy', ['--only' => 'leads_studypermitrequests'])->assertExitCode(0);

    $lead = Lead::withoutGlobalScopes()->find('lead-4');
    expect($lead->vertical->value)->toBe('StudyPermit')
        ->and($lead->stage->value)->toBe('new')
        ->and($lead->vertical_attributes)->toBe([]);
});

it('migrates a dedup-group sibling table (ga_immcan1) onto the shared InCanada vertical', function () {
    DB::connection('legacy')->table('ga_immcan1')->insert(['id' => 'lead-5', 'status' => 'New', 'source' => 'Web']);

    $this->artisan('crm:migrate-legacy', ['--only' => 'leads_immcan1'])->assertExitCode(0);

    $lead = Lead::withoutGlobalScopes()->find('lead-5');
    expect($lead->vertical->value)->toBe('InCanada')
        ->and($lead->source)->toBe('ga_immcan1')
        ->and($lead->vertical_attributes)->toBe(['source' => 'Web']);
});

it('re-runs idempotently without duplicating the row', function () {
    DB::connection('legacy')->table('ga_studypermitrequests')->insert(['id' => 'lead-6']);

    $this->artisan('crm:migrate-legacy', ['--only' => 'leads_studypermitrequests'])->assertExitCode(0);
    $this->artisan('crm:migrate-legacy', ['--only' => 'leads_studypermitrequests'])->assertExitCode(0);

    expect(Lead::withoutGlobalScopes()->count())->toBe(1);
});
