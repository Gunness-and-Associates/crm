<?php

use App\Http\Controllers\Api\IngestController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\ModuleResourceController;
use App\Http\Middleware\Api\ApiThrottle;
use App\Http\Middleware\Api\AuthenticateApiToken;
use App\Http\Middleware\Api\EnforceIdempotencyKey;
use App\Http\Middleware\Api\LogApiRequest;
use App\Http\Middleware\Api\SetETag;
use App\Http\Middleware\Api\VerifyApiKey;
use App\Http\Middleware\Api\VerifyHmacSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// docs/contracts/api-contract.md Part 3 — inbound integration endpoints (Z-5.6).
// A separate auth scheme per source (X-Api-Key, HMAC signature, Meta's own
// verify token), never the OAuth2 bearer tokens the rest of /api/v1/* uses — so
// this is registered outside, and *before*, the v1 group below: v1's own
// `{module}/{id}` GET route (2 segments) would otherwise shadow `v1/ingest/meta`
// (also 2 segments, same GET method) since Laravel matches routes in
// registration order.
Route::prefix('v1/ingest')->middleware([ApiThrottle::class.':api', LogApiRequest::class])->group(function () {
    Route::post('wordpress', [IngestController::class, 'wordpress'])
        ->middleware(VerifyApiKey::class.':ingest.wordpress.api_key');

    Route::get('meta', [IngestController::class, 'verifyMeta']);
    Route::post('meta', [IngestController::class, 'meta']);

    Route::post('{source}', [IngestController::class, 'generic'])
        ->middleware(VerifyHmacSignature::class);
});

// docs/contracts/api-contract.md — base `/api/v1`. OAuth2 client-credentials +
// PAT auth (Z-5.3), plus scope check per docs/contracts/api-contract.md §1.1.
// ACL itself already applies through the model classes' own HasAcl global
// scope (rule 11 — the API and the interface must never disagree).
Route::prefix('v1')
    ->middleware([
        AuthenticateApiToken::class,
        ApiThrottle::class.':api',
        LogApiRequest::class,
        SetETag::class,
        EnforceIdempotencyKey::class,
    ])
    ->group(function () {
        Route::get('meta/modules', [MetaController::class, 'modules'])
            ->middleware('scope:metadata:read');
        Route::get('meta/modules/{module}/fields', [MetaController::class, 'fields'])
            ->middleware('scope:metadata:read');
        Route::get('meta/option-lists/{key}', [MetaController::class, 'optionList'])
            ->middleware('scope:metadata:read');

        Route::get('{module}', [ModuleResourceController::class, 'index'])->middleware('scope');
        Route::post('{module}', [ModuleResourceController::class, 'store'])->middleware('scope');
        Route::get('{module}/{id}', [ModuleResourceController::class, 'show'])->middleware('scope');
        Route::patch('{module}/{id}', [ModuleResourceController::class, 'update'])->middleware('scope');
        Route::delete('{module}/{id}', [ModuleResourceController::class, 'destroy'])->middleware('scope');
    });
