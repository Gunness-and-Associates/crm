<?php

use App\Enums\LeadStage;
use App\Enums\LeadVertical;
use App\Models\Lead;
use App\Models\Student;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(MetadataFixtureSeeder::class);
});

it('issues a real token at the legacy /public/Api/access_token alias', function () {
    $client = app(ClientRepository::class)->createClientCredentialsGrantClient('n8n');

    $response = $this->post('/public/Api/access_token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->id,
        'client_secret' => $client->plainSecret,
    ]);

    $response->assertOk()->assertJsonStructure(['access_token', 'token_type', 'expires_in']);
});

it('returns 410 gone for dt_sms with the exact contract message', function () {
    actingAsApiUser(User::factory()->create(['is_admin' => true]));

    $this->getJson('/public/Api/V8/module/dt_sms')
        ->assertStatus(410)
        ->assertJson(['code' => 'gone', 'detail' => 'The SMS module is not part of this system.']);
});

it('returns 404 for an unrecognised legacy module', function () {
    actingAsApiUser(User::factory()->create(['is_admin' => true]));

    $this->getJson('/public/Api/V8/module/NotARealLegacyModule')->assertStatus(404);
});

it('lists GA_GALead leads with legacy field names, string booleans, and Y-m-d H:i:s dates', function () {
    actingAsApiUser(User::factory()->create(['is_admin' => true]));
    Lead::factory()->create([
        'do_not_call' => true,
        'primary_email' => 'amina@example.com',
        'last_contacted_at' => '2026-06-12 14:03:11',
    ]);

    $response = $this->getJson('/public/Api/V8/module/GA_GALead?fields[GA_GALead]=email1,do_not_call,date_entered,last_contacted_at_c');

    $response->assertOk();
    $attributes = $response->json('data.0.attributes');

    expect($attributes)->toHaveKey('email1', 'amina@example.com')
        ->and($attributes['do_not_call'])->toBe('1')
        ->and($attributes)->toHaveKey('last_contacted_at_c', '2026-06-12 14:03:11')
        ->and($attributes['date_entered'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/')
        ->and($response->json('data.0.type'))->toBe('GA_GALead')
        ->and($response->json('meta.total-pages'))->toBe(1);
});

it('translates a filter on a legacy field alias', function () {
    actingAsApiUser(User::factory()->create(['is_admin' => true]));
    Lead::factory()->create(['vertical' => LeadVertical::Refugee]);
    Lead::factory()->create(['vertical' => LeadVertical::StudyPermit]);

    $this->getJson('/public/Api/V8/module/GA_GALead?filter[category_c]=Refugee')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('forces and filters the fixed vertical for GA_Imm_Biz', function () {
    actingAsApiUser(User::factory()->create(['is_admin' => true]), ['leads:read', 'leads:write']);
    Lead::factory()->create(['vertical' => LeadVertical::BusinessImmigration]);
    Lead::factory()->create(['vertical' => LeadVertical::Refugee]);

    $this->getJson('/public/Api/V8/module/GA_Imm_Biz')->assertOk()->assertJsonCount(1, 'data');

    $created = $this->postJson('/public/Api/V8/module', [
        'data' => ['type' => 'GA_Imm_Biz', 'attributes' => ['full_name' => 'Amina Khan']],
    ])->assertStatus(201);

    expect($created->json('data.attributes.category_c'))->toBe(LeadVertical::BusinessImmigration->value);
});

it('sets vertical from category_c when creating through GA_GALead', function () {
    actingAsApiUser(User::factory()->create(['is_admin' => true]), ['leads:write']);

    $response = $this->postJson('/public/Api/V8/module', [
        'data' => ['type' => 'GA_GALead', 'attributes' => ['full_name' => 'Amina Khan', 'category_c' => 'Refugee']],
    ])->assertStatus(201);

    expect($response->json('data.attributes.category_c'))->toBe('Refugee');
});

it('updates a record via PATCH /public/Api/V8/module with type+id in the body', function () {
    actingAsApiUser(User::factory()->create(['is_admin' => true]), ['leads:write']);
    $lead = Lead::factory()->create(['stage' => LeadStage::New]);

    $this->patchJson('/public/Api/V8/module', [
        'data' => ['type' => 'GA_GALead', 'id' => $lead->id, 'attributes' => ['lead_status_c' => LeadStage::Qualified->value]],
    ])->assertOk()->assertJsonPath('data.attributes.lead_status_c', LeadStage::Qualified->value);

    expect($lead->fresh()->stage)->toBe(LeadStage::Qualified);
});

it('rejects a non-Y-m-d-H:i:s datetime on write with a clear error rather than discarding it', function () {
    actingAsApiUser(User::factory()->create(['is_admin' => true]), ['leads:write']);

    $this->postJson('/public/Api/V8/module', [
        'data' => ['type' => 'GA_GALead', 'attributes' => ['full_name' => 'Amina Khan', 'last_contacted_at_c' => '2026-06-12T14:03:11Z']],
    ])->assertStatus(422)->assertJson(['code' => 'validation_failed']);
});

it('accepts a Y-m-d H:i:s datetime on write', function () {
    actingAsApiUser(User::factory()->create(['is_admin' => true]), ['leads:write']);

    $this->postJson('/public/Api/V8/module', [
        'data' => ['type' => 'GA_GALead', 'attributes' => ['full_name' => 'Amina Khan', 'last_contacted_at_c' => '2026-06-12 14:03:11']],
    ])->assertStatus(201)->assertJsonPath('data.attributes.last_contacted_at_c', '2026-06-12 14:03:11');
});

it('tolerates unknown filter fields and stray query params instead of rejecting them', function () {
    actingAsApiUser(User::factory()->create(['is_admin' => true]));
    Lead::factory()->create();

    $this->getJson('/public/Api/V8/module/GA_GALead?filter[not_a_real_field]=x&some_unexpected_param=1')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('returns {"data": []} with 200 for an empty result, never a 404', function () {
    actingAsApiUser(User::factory()->create(['is_admin' => true]));

    $this->getJson('/public/Api/V8/module/GA_GALead')
        ->assertOk()
        ->assertExactJson(['data' => [], 'meta' => ['total-pages' => 0]]);
});

it('allows page[size] up to 1000', function () {
    actingAsApiUser(User::factory()->create(['is_admin' => true]));
    Lead::factory()->create();

    $this->getJson('/public/Api/V8/module/GA_GALead?page[size]=1000')->assertOk();
});

it('enforces the v1 scope grammar for a legacy request too', function () {
    actingAsApiUser(User::factory()->create(['is_admin' => true]), ['metadata:read']);

    $this->getJson('/public/Api/V8/module/GA_GALead')
        ->assertStatus(403)
        ->assertJson(['code' => 'insufficient_scope']);
});

it('enforces the same create policy as v1 through the legacy adapter', function () {
    $user = User::factory()->create();
    grantAccess($user, 'leads', AccessLevel::None, 'edit');
    actingAsApiUser($user, ['leads:write']);

    $this->postJson('/public/Api/V8/module', [
        'data' => ['type' => 'GA_GALead', 'attributes' => ['full_name' => 'Amina Khan']],
    ])->assertStatus(403)->assertJson(['code' => 'forbidden']);
});

it('reads GA_HQ_Students with legacy aliasing on a non-Lead module', function () {
    actingAsApiUser(User::factory()->create(['is_admin' => true]), ['students:read']);
    Student::factory()->create(['primary_email' => 'amina@example.com', 'hot_lead' => true]);

    $response = $this->getJson('/public/Api/V8/module/GA_HQ_Students?fields[GA_HQ_Students]=email1,hot_lead_c');

    $response->assertOk();
    expect($response->json('data.0.attributes.email1'))->toBe('amina@example.com')
        ->and($response->json('data.0.attributes.hot_lead_c'))->toBe('1')
        ->and($response->json('data.0.type'))->toBe('GA_HQ_Students');
});
