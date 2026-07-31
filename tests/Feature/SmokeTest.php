<?php

// Z-1.1 scaffold smoke tests — prove the app boots on the pinned stack and
// that the deferred pieces (multi-tenancy) are genuinely absent in v1.

it('boots on Laravel 11', function () {
    expect(app()->version())->toStartWith('11.');
});

it('runs on PHP 8.2 or newer', function () {
    expect(version_compare(PHP_VERSION, '8.2.0', '>='))->toBeTrue();
});

it('serves the application root', function () {
    $this->get('/')->assertOk();
});

it('exposes the Filament admin login screen', function () {
    $this->get('/admin/login')->assertOk();
});

it('does not ship stancl/tenancy in v1 (tenancy is Phase 8)', function () {
    expect(class_exists('Stancl\Tenancy\Tenancy'))->toBeFalse();
});
