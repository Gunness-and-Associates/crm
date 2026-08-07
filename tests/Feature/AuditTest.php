<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OwenIt\Auditing\Models\Audit;

uses(RefreshDatabase::class);

it('audits a change to a user', function () {
    $user = User::factory()->create(['name' => 'Amina']);

    $user->update(['name' => 'Amina Khan']);

    $audit = Audit::query()->where('auditable_type', User::class)->where('auditable_id', $user->id)->latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->event)->toBe('updated')
        ->and($audit->new_values['name'])->toBe('Amina Khan');
});

it('never captures the password in an audit', function () {
    $user = User::factory()->create();
    $user->update(['password' => bcrypt('Str0ng-Pass!word')]);

    $audit = Audit::query()->where('auditable_type', User::class)->where('auditable_id', $user->id)->latest('id')->first();

    expect($audit->new_values)->not->toHaveKey('password');
});
