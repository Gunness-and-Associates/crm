<?php

use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => app(Settings::class)->flush());

it('stores and retrieves values of different types', function () {
    $settings = app(Settings::class);
    $settings->set('company.name', 'Gunness & Associates');
    $settings->set('business_hours', ['mon' => '9-17']);
    $settings->set('feature.beta', true);

    expect($settings->get('company.name'))->toBe('Gunness & Associates')
        ->and($settings->get('business_hours'))->toBe(['mon' => '9-17'])
        ->and($settings->get('feature.beta'))->toBeTrue()
        ->and($settings->get('missing', 'fallback'))->toBe('fallback');
});

it('overwrites and forgets values', function () {
    $settings = app(Settings::class);
    $settings->set('temp', 'first');
    $settings->set('temp', 'second');
    expect($settings->get('temp'))->toBe('second');

    $settings->forget('temp');
    expect($settings->get('temp'))->toBeNull();
});

it('persists to the settings table', function () {
    app(Settings::class)->set('x', 1);
    expect(Setting::query()->where('key', 'x')->exists())->toBeTrue();
});

it('encrypts a secret value at rest and decrypts it back through get()', function () {
    $settings = app(Settings::class);
    $settings->set('mail.password', 'super-secret', secret: true);

    $raw = Setting::query()->where('key', 'mail.password')->first();

    expect($raw->is_secret)->toBeTrue()
        ->and($raw->value)->not->toContain('super-secret')
        ->and($settings->get('mail.password'))->toBe('super-secret');
});

it('decrypts a secret value again after the cache is flushed', function () {
    $settings = app(Settings::class);
    $settings->set('mail.password', 'super-secret', secret: true);
    $settings->flush();

    expect($settings->get('mail.password'))->toBe('super-secret');
});

it('stores a non-secret value in plain json, unaffected by the secret flag', function () {
    $settings = app(Settings::class);
    $settings->set('company.name', 'Gunness & Associates');

    $raw = Setting::query()->where('key', 'company.name')->first();

    expect($raw->is_secret)->toBeFalse()
        ->and($raw->value)->toBe('"Gunness & Associates"');
});
