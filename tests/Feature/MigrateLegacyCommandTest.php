<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * The `legacy` connection points at a real (read-only) copy of the source
 * database in actual use — never something CI has access to, and never
 * something a test should depend on. Point it at an in-memory sqlite database
 * instead and build just the handful of columns UserTransformer reads, so this
 * suite is fully self-contained and portable.
 */
beforeEach(function () {
    config([
        'database.connections.legacy.driver' => 'sqlite',
        'database.connections.legacy.database' => ':memory:',
        'database.connections.legacy.foreign_key_constraints' => false,
    ]);
    DB::purge('legacy');

    Schema::connection('legacy')->create('users', function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->string('user_name')->nullable();
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->boolean('is_admin')->default(false);
        $table->string('status')->nullable();
        $table->boolean('deleted')->default(false);
        $table->string('reports_to_id', 36)->nullable();
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

it('migrates a legacy user, preserving id, recovering email, and re-runs idempotently', function () {
    DB::connection('legacy')->table('users')->insert([
        'id' => 'legacy-user-1',
        'user_name' => 'amina',
        'first_name' => 'Amina',
        'last_name' => 'Khan',
        'is_admin' => true,
        'status' => 'Active',
        'deleted' => false,
    ]);
    DB::connection('legacy')->table('email_addresses')->insert([
        'id' => 'email-1', 'email_address' => 'amina@example.com', 'deleted' => false,
    ]);
    DB::connection('legacy')->table('email_addr_bean_rel')->insert([
        'id' => 'rel-1', 'email_address_id' => 'email-1', 'bean_id' => 'legacy-user-1',
        'bean_module' => 'Users', 'primary_address' => true, 'deleted' => false,
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'users'])->assertExitCode(0);

    $user = User::find('legacy-user-1');
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Amina Khan')
        ->and($user->username)->toBe('amina')
        ->and($user->email)->toBe('amina@example.com')
        ->and($user->is_admin)->toBeTrue()
        ->and($user->status)->toBe('active');

    $originalPassword = $user->password;

    $this->artisan('crm:migrate-legacy', ['--only' => 'users'])->assertExitCode(0);

    expect(User::count())->toBe(1)
        ->and($user->fresh()->password)->toBe($originalPassword);
});

it('falls back to a placeholder .invalid email when none can be recovered', function () {
    DB::connection('legacy')->table('users')->insert([
        'id' => 'legacy-user-2', 'user_name' => 'noemail', 'is_admin' => false,
        'status' => 'Active', 'deleted' => false,
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'users'])->assertExitCode(0);

    expect(User::find('legacy-user-2')->email)->toBe('noemail@migrated.invalid');
});

it('marks a deleted or non-Active legacy user inactive', function () {
    DB::connection('legacy')->table('users')->insert([
        'id' => 'legacy-user-3', 'user_name' => 'gone', 'is_admin' => false,
        'status' => 'Active', 'deleted' => true,
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'users'])->assertExitCode(0);

    expect(User::find('legacy-user-3')->status)->toBe('inactive');
});

it('dry-run reports counts and writes nothing', function () {
    DB::connection('legacy')->table('users')->insert([
        'id' => 'legacy-user-4', 'user_name' => 'x', 'is_admin' => false,
        'status' => 'Active', 'deleted' => false,
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'users', '--dry-run' => true])->assertExitCode(0);

    expect(User::find('legacy-user-4'))->toBeNull();
});

it('disambiguates a duplicate username so both rows migrate, keeping the plain name on the non-deleted/most-recent one', function () {
    DB::connection('legacy')->table('users')->insert([
        [
            'id' => 'api-user-stale', 'user_name' => 'api_user', 'status' => 'Inactive',
            'deleted' => true, 'is_admin' => false,
        ],
        [
            'id' => 'api-user-active', 'user_name' => 'api_user', 'status' => 'Active',
            'deleted' => false, 'is_admin' => true,
        ],
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'users'])->assertExitCode(0);

    expect(User::find('api-user-active')->username)->toBe('api_user')
        ->and(User::find('api-user-stale')->username)->toBe('api_user-api-user');
});

it('resumes from --from-id', function () {
    DB::connection('legacy')->table('users')->insert([
        ['id' => 'a-legacy-user', 'user_name' => 'a', 'is_admin' => false, 'status' => 'Active', 'deleted' => false],
        ['id' => 'b-legacy-user', 'user_name' => 'b', 'is_admin' => false, 'status' => 'Active', 'deleted' => false],
    ]);

    $this->artisan('crm:migrate-legacy', ['--only' => 'users', '--from-id' => 'b-legacy-user'])->assertExitCode(0);

    expect(User::find('a-legacy-user'))->toBeNull()
        ->and(User::find('b-legacy-user'))->not->toBeNull();
});
