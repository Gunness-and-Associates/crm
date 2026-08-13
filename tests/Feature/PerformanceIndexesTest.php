<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Z-4.4 index review: these two columns are filtered by range on every dashboard
 * load (DashboardService::callsToMake/attentionNeeded) and had no index at all.
 */
it('indexes leads.next_follow_up_at', function () {
    $indexed = collect(Schema::getIndexes('leads'))
        ->contains(fn (array $index): bool => in_array('next_follow_up_at', $index['columns'], true));

    expect($indexed)->toBeTrue();
});

it('indexes clients.next_action_at', function () {
    $indexed = collect(Schema::getIndexes('clients'))
        ->contains(fn (array $index): bool => in_array('next_action_at', $index['columns'], true));

    expect($indexed)->toBeTrue();
});
