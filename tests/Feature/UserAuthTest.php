<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

uses(DatabaseTruncation::class);

it('gives users a char(36) uuid primary key', function () {
    $user = User::factory()->create();

    expect($user->getKeyName())->toBe('id')
        ->and($user->getIncrementing())->toBeFalse()
        ->and($user->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
});

it('models the two user types', function () {
    expect(User::factory()->admin()->create()->isAdmin())->toBeTrue()
        ->and(User::factory()->create()->isAdmin())->toBeFalse();
});

it('defaults status, locale and timezone', function () {
    $user = User::factory()->create();

    expect($user->status)->toBe('active')
        ->and($user->locale)->toBe('en')
        ->and($user->timezone)->toBe('UTC');
});

it('links the reporting hierarchy', function () {
    $manager = User::factory()->create();
    $report = User::factory()->create(['reports_to_id' => $manager->id]);

    expect($report->manager->is($manager))->toBeTrue()
        ->and($manager->reports->pluck('id')->all())->toContain($report->id);
});

it('enforces the password policy', function () {
    $rule = Password::defaults();

    expect(Validator::make(['p' => 'weak'], ['p' => $rule])->fails())->toBeTrue()
        ->and(Validator::make(['p' => 'short1!Ab'], ['p' => $rule])->fails())->toBeTrue()   // < 12
        ->and(Validator::make(['p' => 'Str0ng-Pass!word'], ['p' => $rule])->fails())->toBeFalse();
});
