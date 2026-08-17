<?php

use App\Enums\LeadStage;
use App\Enums\LeadVertical;
use App\Models\Lead;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(MetadataFixtureSeeder::class);
    actingAsApiUser(User::factory()->create(['is_admin' => true]));
});

it('returns 404 for an unregistered module', function () {
    $this->getJson('/api/v1/not-a-real-module')->assertStatus(404)->assertJson(['code' => 'not_found']);
});

it('lists records with the default offset pagination envelope', function () {
    Lead::factory()->count(3)->create();

    $response = $this->getJson('/api/v1/leads');

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.page', 1)
        ->assertJsonPath('data.0.type', 'leads')
        ->assertJsonStructure(['data' => [['id', 'type', 'attributes', 'links']], 'meta', 'links']);
});

it('paginates with page[size] and page[number]', function () {
    Lead::factory()->count(5)->create();

    $response = $this->getJson('/api/v1/leads?page[size]=2&page[number]=2');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.pages', 3)
        ->assertJsonPath('meta.page', 2)
        ->assertJsonPath('links.prev', '/api/v1/leads?page[number]=1')
        ->assertJsonPath('links.next', '/api/v1/leads?page[number]=3');
});

it('paginates with a keyset cursor', function () {
    Lead::factory()->count(3)->create();

    $first = $this->getJson('/api/v1/leads?page[size]=2&page[cursor]=');
    $first->assertOk()->assertJsonCount(2, 'data');
    $cursor = $first->json('links.next');

    expect($cursor)->not->toBeNull();
    $cursorValue = (string) parse_url((string) $cursor, PHP_URL_QUERY);
    parse_str($cursorValue, $parsed);

    $second = $this->getJson('/api/v1/leads?page[size]=2&page[cursor]='.($parsed['page']['cursor'] ?? ''));
    $second->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('links.next', null);
});

it('filters with the eq/gte/in/like/null grammar', function () {
    Lead::factory()->create(['stage' => LeadStage::New, 'source' => 'facebook']);
    Lead::factory()->create(['stage' => LeadStage::Qualified, 'source' => 'website']);
    Lead::factory()->create(['stage' => LeadStage::Lost, 'source' => null]);

    $this->getJson('/api/v1/leads?filter[stage]=new')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/leads?filter[stage][in]=new,qualified')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/leads?filter[source][like]=%25face%25')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/leads?filter[source][null]=true')->assertJsonCount(1, 'data');
});

it('rejects an unknown filter field with 422 naming the field', function () {
    $this->getJson('/api/v1/leads?filter[not_a_field]=x')
        ->assertStatus(422)
        ->assertJson(['code' => 'validation_failed'])
        ->assertJsonStructure(['errors' => ['not_a_field']]);
});

it('rejects an unknown sort field with 422 naming the field', function () {
    $this->getJson('/api/v1/leads?sort=not_a_field')
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['not_a_field']]);
});

it('sorts ascending and descending', function () {
    Lead::factory()->create(['first_name' => 'A', 'vertical' => LeadVertical::Refugee]);
    Lead::factory()->create(['first_name' => 'B', 'vertical' => LeadVertical::StudyPermit]);

    $asc = $this->getJson('/api/v1/leads?sort=vertical')->json('data.0.attributes.vertical');
    $desc = $this->getJson('/api/v1/leads?sort=-vertical')->json('data.0.attributes.vertical');

    expect($asc)->toBe(LeadVertical::Refugee->value)
        ->and($desc)->toBe(LeadVertical::StudyPermit->value);
});

it('restricts attributes to sparse fields when fields[module] is given', function () {
    Lead::factory()->create(['first_name' => 'Amina', 'last_name' => 'Khan']);

    $response = $this->getJson('/api/v1/leads?fields[leads]=full_name,primary_email');

    $response->assertJsonMissingPath('data.0.attributes.stage')
        ->assertJsonStructure(['data' => [['attributes' => ['full_name', 'primary_email']]]]);
});

it('includes the assignee relationship when requested', function () {
    $assignee = User::factory()->create();
    Lead::factory()->create(['assigned_user_id' => $assignee->id]);

    $response = $this->getJson('/api/v1/leads?include=assignee');

    $response->assertJsonPath('data.0.relationships.assignee.data.id', $assignee->id);
});

it('shows a single record with an ETag header', function () {
    $lead = Lead::factory()->create();

    $response = $this->getJson("/api/v1/leads/{$lead->id}");

    $response->assertOk()->assertJsonPath('data.id', $lead->id);
    expect($response->headers->get('ETag'))->not->toBeNull();
});

it('returns 404 (not 403) for a record the caller cannot see', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    grantAccess($other, 'leads', AccessLevel::Owner);
    $lead = Lead::factory()->create(['assigned_user_id' => $owner->id]);

    actingAsApiUser($other);

    $this->getJson("/api/v1/leads/{$lead->id}")->assertStatus(404);
});

it('creates a record and returns 201 with a Location header', function () {
    $response = $this->postJson('/api/v1/leads', [
        'full_name' => 'Amina Khan',
        'vertical' => LeadVertical::Refugee->value,
        'primary_email' => 'amina@example.com',
    ]);

    $response->assertStatus(201)->assertJsonPath('data.attributes.vertical', LeadVertical::Refugee->value);
    expect($response->headers->get('Location'))->toStartWith('/api/v1/leads/');
});

it('splits an incoming full_name into first_name/last_name (no real column backs full_name itself)', function () {
    $response = $this->postJson('/api/v1/leads', ['full_name' => 'Amina Khan', 'primary_email' => 'a@example.com']);

    $response->assertStatus(201)->assertJsonPath('data.attributes.full_name', 'Amina Khan');

    $lead = Lead::find($response->json('data.id'));
    expect($lead->first_name)->toBe('Amina')->and($lead->last_name)->toBe('Khan');
});

it('rejects an invalid create payload with RFC 7807 validation errors', function () {
    $this->postJson('/api/v1/leads', ['primary_email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJson(['code' => 'validation_failed'])
        ->assertJsonStructure(['errors' => ['primary_email']]);
});

it('updates a record, allowing partial attributes', function () {
    $lead = Lead::factory()->create(['stage' => LeadStage::New]);

    $response = $this->patchJson("/api/v1/leads/{$lead->id}", ['stage' => LeadStage::Qualified->value]);

    $response->assertOk()->assertJsonPath('data.attributes.stage', LeadStage::Qualified->value);
    expect($lead->fresh()->stage)->toBe(LeadStage::Qualified);
});

it('returns 409 when If-Match does not match the current ETag', function () {
    $lead = Lead::factory()->create();

    $this->patchJson("/api/v1/leads/{$lead->id}", ['stage' => LeadStage::Qualified->value], ['If-Match' => '"stale-etag"'])
        ->assertStatus(409)
        ->assertJson(['code' => 'conflict']);
});

it('updates successfully when If-Match matches the current ETag', function () {
    $lead = Lead::factory()->create();
    $etag = $this->getJson("/api/v1/leads/{$lead->id}")->headers->get('ETag');

    $this->patchJson("/api/v1/leads/{$lead->id}", ['stage' => LeadStage::Qualified->value], ['If-Match' => $etag])
        ->assertOk();
});

it('soft-deletes a record and returns 204', function () {
    $lead = Lead::factory()->create();

    $response = $this->deleteJson("/api/v1/leads/{$lead->id}");

    $response->assertStatus(204)->assertNoContent();
    expect(Lead::find($lead->id))->toBeNull()
        ->and(Lead::withTrashed()->find($lead->id))->not->toBeNull();
});
