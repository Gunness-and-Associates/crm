<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| This file is mapped by App\Providers\TenancyServiceProvider::mapRoutes() at
| ->booted() time (i.e. after web.php/api.php are already registered) — a
| route defined here on a path the real app already uses would silently win
| the match, since Laravel's route lookup keys by method+URI and a later
| registration overwrites an earlier one for the same key. The real CRM
| routes (web.php, api.php, legacy_api.php, the Filament panel) are NOT
| gated behind tenancy yet — see Z-8.2's PR description — so keep anything
| registered here off paths those files use, e.g. under a dedicated prefix.
|
| The route below is a diagnostic proving domain-based tenant resolution
| actually works (tests/Feature/DomainIdentificationTest.php exercises it);
| it is not meant to be user-facing.
|
*/

Route::prefix('_tenancy')->middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    Route::get('whoami', function () {
        $tenantId = tenant('id');
        $tenantId = is_scalar($tenantId) ? (string) $tenantId : '';

        return 'This is your multi-tenant application. The id of the current tenant is '.$tenantId;
    });
});
