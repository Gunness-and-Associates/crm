<?php

use App\Exceptions\Api\ApiException;
use App\Http\Middleware\Api\EnforceIdempotencyKey;
use App\Http\Middleware\Api\SetETag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('renders an unknown /api/v1 route as an RFC 7807 404, not a default error page', function () {
    $response = $this->getJson('/api/v1/does-not-exist');

    $response->assertStatus(404)
        ->assertJson([
            'status' => 404,
            'code' => 'not_found',
        ])
        ->assertJsonStructure(['type', 'title', 'status', 'code', 'detail', 'trace_id']);
});

it('renders a validation failure as RFC 7807 with a field-keyed errors object', function () {
    Route::post('/api/v1/_test/validate', function (Request $request) {
        $request->validate(['primary_email' => 'required|email']);

        return response()->json(['ok' => true]);
    });

    $response = $this->postJson('/api/v1/_test/validate', ['primary_email' => 'not-an-email']);

    $response->assertStatus(422)
        ->assertJson(['code' => 'validation_failed'])
        ->assertJsonStructure(['errors' => ['primary_email']]);
});

it('renders a thrown ApiException with its own status and code', function () {
    // Three segments — Z-5.2's generic {module} (1 segment) and {module}/{id} (2
    // segments) routes are maximally greedy, so a 2-segment fixture path here would
    // be shadowed by ModuleResourceController::show() instead of hitting this route.
    Route::get('/api/v1/_test/conflict/case', function () {
        throw ApiException::conflict('The record has been modified since it was last read.');
    });

    $response = $this->getJson('/api/v1/_test/conflict/case');

    $response->assertStatus(409)->assertJson(['code' => 'conflict']);
});

it('renders a gone endpoint (410) for a removed module', function () {
    Route::get('/api/v1/_test/gone/case', function () {
        throw ApiException::gone('The SMS module is not part of this system.');
    });

    $response = $this->getJson('/api/v1/_test/gone/case');

    $response->assertStatus(410)->assertJson(['code' => 'gone']);
});

it('sets an ETag on a GET response and returns 304 for a matching If-None-Match', function () {
    Route::get('/api/v1/_test/etag/case', fn () => response()->json(['data' => ['id' => '1']]))
        ->middleware(SetETag::class);

    $first = $this->getJson('/api/v1/_test/etag/case');
    $first->assertOk();
    $etag = $first->headers->get('ETag');

    expect($etag)->not->toBeNull();

    $second = $this->getJson('/api/v1/_test/etag/case', ['If-None-Match' => $etag]);
    $second->assertStatus(304)
        ->assertNoContent(304);
});

it('replays the original response for a repeated Idempotency-Key instead of re-running the handler', function () {
    $calls = 0;
    Route::post('/api/v1/_test/idempotent', function () use (&$calls) {
        $calls++;

        return response()->json(['data' => ['id' => (string) $calls]], 201);
    })->middleware(EnforceIdempotencyKey::class);

    $first = $this->postJson('/api/v1/_test/idempotent', [], ['Idempotency-Key' => 'abc-123']);
    $second = $this->postJson('/api/v1/_test/idempotent', [], ['Idempotency-Key' => 'abc-123']);

    $first->assertStatus(201)->assertJson(['data' => ['id' => '1']]);
    $second->assertStatus(201)->assertJson(['data' => ['id' => '1']]); // not '2' — the handler ran once
    expect($calls)->toBe(1);
});

it('does not replay across two different Idempotency-Key values', function () {
    $calls = 0;
    Route::post('/api/v1/_test/idempotent-distinct', function () use (&$calls) {
        $calls++;

        return response()->json(['data' => ['id' => (string) $calls]], 201);
    })->middleware(EnforceIdempotencyKey::class);

    $this->postJson('/api/v1/_test/idempotent-distinct', [], ['Idempotency-Key' => 'key-one']);
    $this->postJson('/api/v1/_test/idempotent-distinct', [], ['Idempotency-Key' => 'key-two']);

    expect($calls)->toBe(2);
});
