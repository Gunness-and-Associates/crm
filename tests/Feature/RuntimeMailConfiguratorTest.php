<?php

use App\Support\RuntimeMailConfigurator;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => app(Settings::class)->flush());

it('is a no-op until mail.host is configured', function () {
    $before = config('mail.mailers.smtp');

    app(RuntimeMailConfigurator::class)->apply();

    expect(config('mail.mailers.smtp'))->toBe($before);
});

it('builds the smtp mailer from the settings store, never .env', function () {
    $settings = app(Settings::class);
    $settings->set('mail.host', 'smtp.gunness.test');
    $settings->set('mail.port', 2525);
    $settings->set('mail.encryption', 'ssl');
    $settings->set('mail.username', 'notifications@gunness.test');
    $settings->set('mail.password', 'super-secret', secret: true);
    $settings->set('mail.from_address', 'noreply@gunness.test');
    $settings->set('mail.from_name', 'Gunness & Associates');

    app(RuntimeMailConfigurator::class)->apply();

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.gunness.test')
        ->and(config('mail.mailers.smtp.port'))->toBe(2525)
        ->and(config('mail.mailers.smtp.encryption'))->toBe('ssl')
        ->and(config('mail.mailers.smtp.username'))->toBe('notifications@gunness.test')
        ->and(config('mail.mailers.smtp.password'))->toBe('super-secret')
        ->and(config('mail.from.address'))->toBe('noreply@gunness.test')
        ->and(config('mail.from.name'))->toBe('Gunness & Associates');
});
