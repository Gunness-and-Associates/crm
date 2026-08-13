<?php

use App\Http\Middleware\Api\EnforceIdempotencyKey;
use App\Http\Middleware\Api\SetETag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// docs/contracts/api-contract.md — base `/api/v1`. Z-5.2+ registers the
// metadata-driven module resources and meta/* endpoints inside this group;
// Z-5.1 provides the group itself plus the ETag/Idempotency-Key middleware
// every one of those endpoints will run through.
Route::prefix('v1')
    ->middleware([SetETag::class, EnforceIdempotencyKey::class])
    ->group(function () {
        //
    });
