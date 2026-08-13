<?php

use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\ModuleResourceController;
use App\Http\Middleware\Api\EnforceIdempotencyKey;
use App\Http\Middleware\Api\SetETag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// docs/contracts/api-contract.md — base `/api/v1`. Authentication/scopes are
// Z-5.3's job; ACL itself already applies through the model classes' own HasAcl
// global scope (rule 11 — the API and the interface must never disagree).
Route::prefix('v1')
    ->middleware([SetETag::class, EnforceIdempotencyKey::class])
    ->group(function () {
        Route::get('meta/modules', [MetaController::class, 'modules']);
        Route::get('meta/modules/{module}/fields', [MetaController::class, 'fields']);
        Route::get('meta/option-lists/{key}', [MetaController::class, 'optionList']);

        Route::get('{module}', [ModuleResourceController::class, 'index']);
        Route::post('{module}', [ModuleResourceController::class, 'store']);
        Route::get('{module}/{id}', [ModuleResourceController::class, 'show']);
        Route::patch('{module}/{id}', [ModuleResourceController::class, 'update']);
        Route::delete('{module}/{id}', [ModuleResourceController::class, 'destroy']);
    });
