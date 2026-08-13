<?php

use App\Support\Api\ApiResponse;

it('builds one resource object with the id/type/attributes shape', function () {
    $object = ApiResponse::object('9c8f1e4a-3b2d', 'leads', ['first_name' => 'Amina']);

    expect($object)->toBe([
        'id' => '9c8f1e4a-3b2d',
        'type' => 'leads',
        'attributes' => ['first_name' => 'Amina'],
    ]);
});

it('includes relationships and links only when given', function () {
    $withExtras = ApiResponse::object(
        '1',
        'leads',
        ['first_name' => 'Amina'],
        ['assignee' => ['data' => ['id' => '2', 'type' => 'users']]],
        ['self' => '/api/v1/leads/1'],
    );

    expect($withExtras)->toHaveKeys(['relationships', 'links']);

    $withoutExtras = ApiResponse::object('1', 'leads', ['first_name' => 'Amina']);
    expect($withoutExtras)->not->toHaveKey('relationships')
        ->and($withoutExtras)->not->toHaveKey('links');
});

it('wraps a collection in data/meta/links', function () {
    $response = ApiResponse::collection(
        [ApiResponse::object('1', 'leads', ['first_name' => 'Amina'])],
        ['total' => 394, 'count' => 1, 'page' => 1, 'pages' => 16, 'per_page' => 25],
        ['self' => '/api/v1/leads?page[number]=1', 'next' => '/api/v1/leads?page[number]=2', 'prev' => null],
    );

    $payload = $response->getData(true);

    expect($payload['data'][0]['attributes']['first_name'])->toBe('Amina')
        ->and($payload['meta']['total'])->toBe(394)
        ->and($payload['links']['next'])->toBe('/api/v1/leads?page[number]=2');
});

it('created() returns 201 with a Location header', function () {
    $response = ApiResponse::created(ApiResponse::object('1', 'leads', ['first_name' => 'Amina']), '/api/v1/leads/1');

    expect($response->getStatusCode())->toBe(201)
        ->and($response->headers->get('Location'))->toBe('/api/v1/leads/1');
});

it('noContent() returns an empty 204', function () {
    $response = ApiResponse::noContent();

    expect($response->getStatusCode())->toBe(204)
        ->and($response->getContent())->toBe('');
});
