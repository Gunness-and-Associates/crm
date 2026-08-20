<?php

use App\Http\Middleware\EndTenancyAfterResponse;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;

// Z-8.3 (BACKEND_BRIEF_ZAIN.md §14 step 4) — gated behind tenant resolution now
// that tenant #1 exists (crm:promote-primary-tenant). See routes/tenant.php's
// header comment for why anything registered here must stay off any path that
// collides with routes registered there.
Route::middleware([InitializeTenancyByDomain::class, EndTenancyAfterResponse::class])->group(function () {
    Route::get('/', function () {
        return view('welcome');
    });
});
