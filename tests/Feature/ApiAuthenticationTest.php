<?php

use App\Models\Lead;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Passport\ClientRepository;

// Z-8.3 -- DatabaseTruncation, not RefreshDatabase: promotePrimaryTenant() below
// makes these requests switch to a "tenant" DB connection, a distinct PDO handle
// to the same physical database; an open RefreshDatabase transaction on the
// original connection would hide this test's own fixtures from it.
uses(DatabaseTruncation::class);

beforeEach(function () {
    promotePrimaryTenant();
});

it('rejects a request with no bearer token at all', function () {
    $this->getJson('/api/v1/leads')
        ->assertStatus(401)
        ->assertJson(['code' => 'unauthenticated']);
});

it('rejects a request whose token lacks the required scope with 403 insufficient_scope', function () {
    actingAsApiUser(User::factory()->create(), ['leads:read']);

    $this->postJson('/api/v1/leads', ['full_name' => 'Amina Khan'])
        ->assertStatus(403)
        ->assertJson(['code' => 'insufficient_scope']);
});

it('allows a request whose token has the exact dynamic scope for the route', function () {
    $this->seed(MetadataFixtureSeeder::class);
    actingAsApiUser(User::factory()->create(['is_admin' => true]), ['leads:read']);

    $this->getJson('/api/v1/leads')->assertOk();
});

it('enforces the static scope on meta/* independently of module scopes', function () {
    actingAsApiUser(User::factory()->create(), ['leads:read']);
    $this->getJson('/api/v1/meta/modules')->assertStatus(403)->assertJson(['code' => 'insufficient_scope']);

    actingAsApiUser(User::factory()->create(), ['metadata:read']);
    $this->getJson('/api/v1/meta/modules')->assertOk();
});

it('authenticates a real client-credentials token end-to-end and applies the owning user\'s ACL', function () {
    $this->seed(MetadataFixtureSeeder::class);
    $owner = User::factory()->create();
    grantAccess($owner, 'leads', AccessLevel::All);
    Lead::factory()->create();

    $client = app(ClientRepository::class)->createClientCredentialsGrantClient('n8n integration');
    $client->owner()->associate($owner);
    $client->save();
    $secret = $client->plainSecret;

    $token = $this->postJson('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->id,
        'client_secret' => $secret,
        'scope' => 'leads:read',
    ])->assertOk()->json('access_token');

    expect($token)->toBeString();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/leads')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('carries X-RateLimit-Limit, X-RateLimit-Remaining and X-RateLimit-Reset on every response', function () {
    actingAsApiUser(User::factory()->create(), ['metadata:read']);

    $response = $this->getJson('/api/v1/meta/modules')->assertOk();

    expect($response->headers->get('X-RateLimit-Limit'))->toBe('600')
        ->and($response->headers->get('X-RateLimit-Remaining'))->not->toBeNull()
        ->and($response->headers->get('X-RateLimit-Reset'))->not->toBeNull();
});

it('returns 429 with Retry-After once a client exceeds its configured per-minute limit', function () {
    $client = app(ClientRepository::class)->createClientCredentialsGrantClient('low-limit client');
    $client->forceFill(['rate_limit_per_minute' => 1])->save();
    actingAsApiClient($client, ['metadata:read']);

    $this->getJson('/api/v1/meta/modules')->assertOk();

    $response = $this->getJson('/api/v1/meta/modules');

    $response->assertStatus(429)
        ->assertJson(['code' => 'rate_limited']);
    expect($response->headers->get('Retry-After'))->not->toBeNull();
});
