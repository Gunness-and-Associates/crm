<?php

use App\Jobs\ProcessMetaLeadJob;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Support\Ingest\Canon;
use App\Support\Ingest\IngestPipeline;
use App\Support\Ingest\PhoneCleaner;
use App\Support\Ingest\Sources\MetaLeadFetcher;
use App\Support\Settings;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Z-8.3 -- DatabaseTruncation, not RefreshDatabase: promotePrimaryTenant() below
// makes these requests switch to a "tenant" DB connection, a distinct PDO handle
// to the same physical database; an open RefreshDatabase transaction on the
// original connection would hide this test's own fixtures from it.
uses(DatabaseTruncation::class);

beforeEach(function () {
    promotePrimaryTenant();
    $this->seed(MetadataFixtureSeeder::class);
    app(Settings::class)->set('ingest.wordpress.api_key', 'test-wp-key', secret: true);
    app(Settings::class)->set('ingest.generic-source.secret', 'test-hmac-secret', secret: true);
    app(Settings::class)->set('ingest.meta.verify_token', 'test-verify-token');
    app(Settings::class)->set('ingest.meta.app_secret', 'test-app-secret', secret: true);
    // Ingest endpoints have no bearer token — this is purely so *this test's own*
    // read-back queries aren't hidden by the ACL scope's "no authenticated user"
    // -> zero-rows default. It has no bearing on the (unauthenticated) endpoints.
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

function salesRep(): User
{
    $role = Role::factory()->create(['name' => 'Sales Representatives']);
    $user = User::factory()->create(['status' => 'active']);
    $user->roles()->attach($role);

    return $user;
}

it('cleans invisible unicode and non-digit characters from a phone number', function () {
    expect(PhoneCleaner::clean("\u{200E}+1 (416) 555-0134"))->toBe('+14165550134')
        ->and(PhoneCleaner::clean(null))->toBeNull()
        ->and(PhoneCleaner::clean('abc'))->toBeNull();
});

it('canonicalises case, spacing and punctuation before matching', function () {
    expect(Canon::value('Business Immigration!'))->toBe('businessimmigration')
        ->and(Canon::value('business_immigration'))->toBe('businessimmigration');
});

it('rejects a WordPress submission with a missing or wrong X-Api-Key', function () {
    $this->postJson('/api/v1/ingest/wordpress', ['your-email' => 'amina@example.com'])
        ->assertStatus(401);

    $this->postJson('/api/v1/ingest/wordpress', ['your-email' => 'amina@example.com'], ['X-Api-Key' => 'wrong'])
        ->assertStatus(401);
});

it('accepts a Contact Form 7 style flat payload and creates a lead, assigning a sales rep', function () {
    $rep = salesRep();

    $response = $this->postJson('/api/v1/ingest/wordpress', [
        'your-name' => 'Amina Khan',
        'your-email' => ' AMINA@Example.com ',
        'your-phone' => "\u{200E}+1 416 555 0134",
    ], ['X-Api-Key' => 'test-wp-key']);

    $response->assertStatus(202)->assertJsonStructure(['id', 'status']);

    $lead = Lead::find($response->json('id'));
    expect($lead)->not->toBeNull()
        ->and($lead->fullName())->toBe('Amina Khan')
        ->and($lead->primary_email)->toBe('amina@example.com')
        ->and($lead->phone_mobile)->toBe('+14165550134')
        ->and($lead->assigned_user_id)->toBe($rep->id)
        ->and($lead->source)->toBe('wordpress');
});

it('flattens a WPForms/Elementor-style fields array before mapping', function () {
    salesRep();

    $response = $this->postJson('/api/v1/ingest/wordpress', [
        'fields' => [
            '1' => ['id' => 'name', 'value' => 'Amina Khan'],
            '2' => ['id' => 'email', 'value' => 'amina@example.com'],
        ],
    ], ['X-Api-Key' => 'test-wp-key']);

    $response->assertStatus(202);
    expect(Lead::find($response->json('id'))->primary_email)->toBe('amina@example.com');
});

it('canonicalises an enum field against option value or label, and logs an unmatched value as null', function () {
    salesRep();
    app(Settings::class)->set('ingest.wordpress.field_map', [
        ['source_field' => 'email', 'target_field' => 'primary_email'],
        ['source_field' => 'vertical_choice', 'target_field' => 'vertical'],
    ]);

    $matched = $this->postJson('/api/v1/ingest/wordpress', [
        'email' => 'a@example.com',
        'vertical_choice' => 'business immigration',
    ], ['X-Api-Key' => 'test-wp-key'])->assertStatus(202);
    expect(Lead::find($matched->json('id'))->vertical->value)->toBe('BusinessImmigration');

    $unmatched = $this->postJson('/api/v1/ingest/wordpress', [
        'email' => 'b@example.com',
        'vertical_choice' => 'not a real vertical at all',
    ], ['X-Api-Key' => 'test-wp-key'])->assertStatus(202);
    expect(Lead::find($unmatched->json('id'))->vertical)->toBeNull();
});

it('dedupes by email in warn mode: creates a second record but flags it as a duplicate', function () {
    salesRep();
    Lead::factory()->create(['primary_email' => 'amina@example.com']);

    $response = $this->postJson('/api/v1/ingest/wordpress', [
        'your-email' => 'amina@example.com',
    ], ['X-Api-Key' => 'test-wp-key']);

    $response->assertStatus(202)->assertJsonPath('status', 'duplicate');
    expect(Lead::where('primary_email', 'amina@example.com')->count())->toBe(2);
});

it('dedupes by email in merge mode: updates the existing record instead of creating a new one', function () {
    app(Settings::class)->set('ingest.dedupe.leads.mode', 'merge');
    $existing = Lead::factory()->create(['primary_email' => 'amina@example.com', 'first_name' => 'Old', 'last_name' => 'Name']);

    $response = $this->postJson('/api/v1/ingest/wordpress', [
        'your-name' => 'Amina Khan',
        'your-email' => 'amina@example.com',
    ], ['X-Api-Key' => 'test-wp-key']);

    $response->assertStatus(202)->assertJsonPath('status', 'processed')->assertJsonPath('id', $existing->id);
    expect(Lead::count())->toBe(1)
        ->and($existing->fresh()->fullName())->toBe('Amina Khan');
});

it('rejects a generic ingest request with a missing or wrong X-Signature', function () {
    $this->postJson('/api/v1/ingest/generic-source', ['email' => 'a@example.com'])
        ->assertStatus(401);
});

it('accepts a generic ingest request with a valid HMAC-SHA256 signature', function () {
    salesRep();
    app(Settings::class)->set('ingest.generic-source.field_map', [
        ['source_field' => 'email', 'target_field' => 'primary_email'],
    ]);

    $body = json_encode(['email' => 'amina@example.com']);
    $signature = 'sha256='.hash_hmac('sha256', $body, 'test-hmac-secret');

    $response = $this->call('POST', '/api/v1/ingest/generic-source', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X-Signature' => $signature,
        'HTTP_ACCEPT' => 'application/json',
    ], $body);

    $response->assertStatus(202);
    expect(Lead::find($response->json('id'))->primary_email)->toBe('amina@example.com');
});

it('rejects Meta webhook verification with the wrong verify token', function () {
    $this->get('/api/v1/ingest/meta?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=xyz')
        ->assertStatus(401);
});

it('echoes hub.challenge back when Meta webhook verification succeeds', function () {
    $this->get('/api/v1/ingest/meta?hub.mode=subscribe&hub.verify_token=test-verify-token&hub.challenge=xyz')
        ->assertOk()
        ->assertSee('xyz');
});

it('rejects a Meta webhook POST with a missing or wrong signature', function () {
    $this->postJson('/api/v1/ingest/meta', ['entry' => []])->assertStatus(401);
});

it('queues a job per leadgen change on a signed Meta webhook POST', function () {
    Queue::fake();

    $body = json_encode([
        'entry' => [
            ['changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => 'lead-123']]]],
        ],
    ]);
    $signature = 'sha256='.hash_hmac('sha256', $body, 'test-app-secret');

    $this->call('POST', '/api/v1/ingest/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X-Hub-Signature-256' => $signature,
        'HTTP_ACCEPT' => 'application/json',
    ], $body)->assertOk();

    Queue::assertPushedOn('integrations', ProcessMetaLeadJob::class, fn ($job) => $job->leadgenId === 'lead-123');
});

it('processes a queued Meta lead by fetching field_data from the Graph API', function () {
    salesRep();
    app(Settings::class)->set('ingest.meta.access_token', 'fake-token', secret: true);

    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'field_data' => [
                ['name' => 'first_name', 'values' => ['Amina']],
                ['name' => 'last_name', 'values' => ['Khan']],
                ['name' => 'email', 'values' => ['amina@example.com']],
            ],
        ]),
    ]);

    (new ProcessMetaLeadJob('lead-123'))->handle(app(MetaLeadFetcher::class), app(IngestPipeline::class));

    $lead = Lead::where('primary_email', 'amina@example.com')->first();
    expect($lead)->not->toBeNull()->and($lead->fullName())->toBe('Amina Khan')->and($lead->source)->toBe('meta');
});
