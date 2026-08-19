<?php

use App\Http\Controllers\Api\Legacy\V8ModuleController;
use App\Http\Middleware\Api\ApiThrottle;
use App\Http\Middleware\Api\AuthenticateApiToken;
use App\Http\Middleware\Api\LogApiRequest;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;

/*
|--------------------------------------------------------------------------
| Legacy /Api/V8/* adapter (docs/contracts/api-contract.md Part 2, Z-5.5)
|--------------------------------------------------------------------------
|
| Bare `/public/Api/...` URLs, no `/api` prefix — required verbatim by the 133
| existing n8n workflows (§2.1's "these are taken from the actual HTTP nodes in
| the running n8n instance, so they must be matched exactly"). Registered via
| bootstrap/app.php's `then` callback, which is the only routing hook that skips
| the automatic `/api` prefix + `api` middleware group `withRouting(api: ...)`
| applies to routes/api.php.
|
| Token issuance is a URL alias only — same Passport OAuth2 server, same tokens,
| same scopes as /oauth/token. Every other route shares the exact same
| authentication/scope/rate-limit/logging middleware as /api/v1/* (Z-5.3/Z-5.4):
| a legacy token is a v1 token, just requested from a different path.
|
*/

// Not AuthenticateApiToken -- issuing a token is the credential check itself,
// same as Passport's own /oauth/token. Still throttled per Z-7.1: without
// this it was a second, unthrottled entry point for brute-forcing
// client_id/client_secret alongside the throttled primary token endpoint.
Route::post('/public/Api/access_token', [AccessTokenController::class, 'issueToken'])
    ->middleware([ApiThrottle::class.':api', LogApiRequest::class]);

$middleware = [AuthenticateApiToken::class, ApiThrottle::class.':api', LogApiRequest::class];

Route::prefix('public/Api/V8/module')->middleware($middleware)->group(function () {
    Route::get('{legacyModule}', [V8ModuleController::class, 'index'])->middleware('scope');
    Route::get('{legacyModule}/{id}', [V8ModuleController::class, 'show'])->middleware('scope');
    Route::delete('{legacyModule}/{id}', [V8ModuleController::class, 'destroy'])->middleware('scope');
});

// Create/update carry `data.type` (and, for update, `data.id`) in the body instead
// of the URL — matching the one verified write shape in §2.1 (`PATCH /Api/V8/module`).
Route::post('/public/Api/V8/module', [V8ModuleController::class, 'store'])
    ->middleware([...$middleware, 'scope']);

Route::patch('/public/Api/V8/module', [V8ModuleController::class, 'update'])
    ->middleware([...$middleware, 'scope']);
