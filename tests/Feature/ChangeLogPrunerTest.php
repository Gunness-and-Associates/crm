<?php

use App\Models\Metadata\Change;
use App\Support\ChangeLogPruner;
use App\Support\SchemaManager\Snapshotter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('deletes snapshot files older than the retention window but keeps the change log row', function () {
    $disk = app(Snapshotter::class)->disk();
    Storage::disk($disk)->put('schema-snapshots/old.sql', 'old dump');
    Storage::disk($disk)->put('schema-snapshots/fresh.sql', 'fresh dump');

    $old = Change::factory()->create([
        'snapshot_path' => 'schema-snapshots/old.sql',
        'created_at' => now()->subDays(45),
    ]);
    $fresh = Change::factory()->create([
        'snapshot_path' => 'schema-snapshots/fresh.sql',
        'created_at' => now()->subDays(5),
    ]);

    $pruned = app(ChangeLogPruner::class)->pruneSnapshots(30);

    expect($pruned)->toBe(1)
        ->and(Storage::disk($disk)->exists('schema-snapshots/old.sql'))->toBeFalse()
        ->and(Storage::disk($disk)->exists('schema-snapshots/fresh.sql'))->toBeTrue()
        ->and(Change::query()->find($old->id)->snapshot_path)->toBeNull()
        ->and(Change::query()->find($fresh->id)->snapshot_path)->toBe('schema-snapshots/fresh.sql')
        ->and(Change::query()->count())->toBe(2);
});
