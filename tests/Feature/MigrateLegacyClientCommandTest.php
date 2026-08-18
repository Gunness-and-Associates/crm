<?php

use App\Models\Client;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Client sources: ga_clients (+cstm, the main table), ga_clientdevelopment2
 * (status only), ga_clientdevelopment3 + ga_imm_client (bare, via
 * BareContactableTransformer) — Z-6.2 part 3.
 */
beforeEach(function () {
    config([
        'database.connections.legacy.driver' => 'sqlite',
        'database.connections.legacy.database' => ':memory:',
        'database.connections.legacy.foreign_key_constraints' => false,
    ]);
    DB::purge('legacy');

    Schema::connection('legacy')->create('ga_clients', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('first_name')->nullable();
        $table->string('client_status')->nullable();
        $table->string('dob')->nullable();
        $table->string('country')->nullable();
    });
    Schema::connection('legacy')->create('ga_clients_cstm', function (Blueprint $table) {
        $table->string('id_c', 36)->primary();
        $table->string('status_c')->nullable();
    });

    Schema::connection('legacy')->create('ga_clientdevelopment2', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('first_name')->nullable();
        $table->string('status')->nullable();
    });

    Schema::connection('legacy')->create('ga_clientdevelopment3', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('first_name')->nullable();
    });

    Schema::connection('legacy')->create('ga_imm_client', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->boolean('deleted')->default(false);
        $table->string('date_modified')->nullable();
        $table->string('first_name')->nullable();
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

it('prefers client_status over the cstm status_c fallback', function () {
    DB::connection('legacy')->table('ga_clients')->insert(['id' => 'client-1', 'client_status' => 'Active Client']);
    DB::connection('legacy')->table('ga_clients_cstm')->insert(['id_c' => 'client-1', 'status_c' => 'Prospect']);

    $this->artisan('crm:migrate-legacy', ['--only' => 'clients'])->assertExitCode(0);

    expect(Client::withoutGlobalScopes()->find('client-1')->client_status)->toBe('Active Client');
});

it('parses dob in the alternate m/d/Y format found in the real data', function () {
    DB::connection('legacy')->table('ga_clients')->insert(['id' => 'client-2', 'dob' => '06/03/1977']);

    $this->artisan('crm:migrate-legacy', ['--only' => 'clients'])->assertExitCode(0);

    expect(Client::withoutGlobalScopes()->find('client-2')->dob->format('Y-m-d'))->toBe('1977-06-03');
});

it('migrates ga_clientdevelopment2 with its own status column into client_status', function () {
    DB::connection('legacy')->table('ga_clientdevelopment2')->insert(['id' => 'client-3', 'status' => 'Converted']);

    $this->artisan('crm:migrate-legacy', ['--only' => 'clients_development2'])->assertExitCode(0);

    expect(Client::withoutGlobalScopes()->find('client-3')->client_status)->toBe('Converted');
});

it('migrates the bare ga_clientdevelopment3 and ga_imm_client tables via BareContactableTransformer', function () {
    DB::connection('legacy')->table('ga_clientdevelopment3')->insert(['id' => 'client-4', 'first_name' => 'Sam']);
    DB::connection('legacy')->table('ga_imm_client')->insert(['id' => 'client-5', 'first_name' => 'Lee']);

    $this->artisan('crm:migrate-legacy', ['--only' => 'clients_development3'])->assertExitCode(0);
    $this->artisan('crm:migrate-legacy', ['--only' => 'clients_imm_client'])->assertExitCode(0);

    expect(Client::withoutGlobalScopes()->find('client-4')->first_name)->toBe('Sam')
        ->and(Client::withoutGlobalScopes()->find('client-5')->first_name)->toBe('Lee');
});

it('re-runs idempotently without duplicating the row', function () {
    DB::connection('legacy')->table('ga_clients')->insert(['id' => 'client-6']);

    $this->artisan('crm:migrate-legacy', ['--only' => 'clients'])->assertExitCode(0);
    $this->artisan('crm:migrate-legacy', ['--only' => 'clients'])->assertExitCode(0);

    expect(Client::withoutGlobalScopes()->count())->toBe(1);
});
