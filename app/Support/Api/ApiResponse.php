<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The one response envelope every `/api/v1/*` endpoint uses (docs/contracts/api-
 * contract.md §1.2–1.3). Not JSON:API — create/update bodies stay flat — but list
 * and single-record responses share this `{id, type, attributes, ...}` object shape.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $relationships
     * @param  array<string, mixed>  $links
     * @return array<string, mixed>
     */
    public static function object(string $id, string $type, array $attributes, array $relationships = [], array $links = []): array
    {
        $object = ['id' => $id, 'type' => $type, 'attributes' => $attributes];

        if ($relationships !== []) {
            $object['relationships'] = $relationships;
        }

        if ($links !== []) {
            $object['links'] = $links;
        }

        return $object;
    }

    /**
     * @param  list<array<string, mixed>>  $data
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $links
     */
    public static function collection(array $data, array $meta = [], array $links = []): JsonResponse
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        if ($links !== []) {
            $payload['links'] = $links;
        }

        return response()->json($payload);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function resource(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function created(array $data, string $location): JsonResponse
    {
        return response()->json(['data' => $data], 201, ['Location' => $location]);
    }

    public static function noContent(): Response
    {
        return response()->noContent();
    }
}
