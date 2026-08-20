<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;

// Z-1.1 scaffold smoke tests — prove the app boots on the pinned stack.
// The multi-tenancy absence check (was: "does not ship stancl/tenancy in v1")
// is superseded by Z-8.1, which installs it — see the inverse assertion below.
//
// Z-8.3 -- '/' and '/admin/login' are now gated behind tenant resolution, so
// this needs a tenant to resolve to. DatabaseTruncation, not RefreshDatabase:
// promotePrimaryTenant() switches the request to a "tenant" DB connection, a
// distinct PDO handle to the same physical database; an open RefreshDatabase
// transaction on the original connection would hide it from that connection.
uses(DatabaseTruncation::class);

beforeEach(function () {
    promotePrimaryTenant();
});

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

it('ships stancl/tenancy from Phase 8 onward', function () {
    expect(class_exists('Stancl\Tenancy\Tenancy'))->toBeTrue();
});
