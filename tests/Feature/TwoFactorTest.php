<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

it('enables and confirms two-factor with a valid code', function () {
    $user = User::factory()->create();

    $codes = $user->enableTwoFactor();

    expect($codes)->toHaveCount(8)
        ->and($user->two_factor_secret)->not->toBeNull()
        ->and($user->hasTwoFactorEnabled())->toBeFalse();          // not confirmed yet

    $validCode = app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);

    expect($user->confirmTwoFactor($validCode))->toBeTrue()
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('rejects a wrong confirmation code', function () {
    $user = User::factory()->create();
    $user->enableTwoFactor();

    expect($user->confirmTwoFactor('000000'))->toBeFalse()
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('consumes a recovery code once', function () {
    $user = User::factory()->create();
    $codes = $user->enableTwoFactor();
    $code = $codes[0];

    expect($user->useRecoveryCode($code))->toBeTrue()
        ->and($user->fresh()->useRecoveryCode($code))->toBeFalse();  // already used
});

it('stores the two-factor secret encrypted at rest', function () {
    $user = User::factory()->create();
    $user->enableTwoFactor();

    $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

    expect($raw)->not->toBe($user->two_factor_secret);   // ciphertext in the column
});

it('disables two-factor', function () {
    $user = User::factory()->create();
    $user->enableTwoFactor();
    $user->disableTwoFactor();

    expect($user->two_factor_secret)->toBeNull()
        ->and($user->hasTwoFactorEnabled())->toBeFalse();
});
