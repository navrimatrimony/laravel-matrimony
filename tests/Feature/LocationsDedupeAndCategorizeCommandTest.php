<?php

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $geo = \App\Models\Location::geoTable();
    if (! Schema::hasTable($geo) || ! Schema::hasColumn($geo, 'tag')) {
        $this->markTestSkipped('addresses.tag (geo SSOT) not available');
    }
});

test('locations categorization command sets category from rules', function () {
    $india = Location::query()->create([
        'name' => 'India',
        'slug' => 'india-cat-cmd-test',
        'hierarchy' => 'country',
        'parent_id' => null,
        'level' => 0,
        'is_active' => true,
    ]);
    $mh = Location::query()->create([
        'name' => 'Maharashtra',
        'slug' => 'mh-cat-cmd-test',
        'hierarchy' => 'state',
        'parent_id' => $india->id,
        'level' => 1,
        'is_active' => true,
    ]);
    $puneDist = Location::query()->create([
        'name' => 'Pune',
        'slug' => 'pune-dist-cat-cmd',
        'hierarchy' => 'district',
        'parent_id' => $mh->id,
        'level' => 2,
        'is_active' => true,
    ]);
    $otherDist = Location::query()->create([
        'name' => 'Satara',
        'slug' => 'satara-dist-cat-cmd',
        'hierarchy' => 'district',
        'parent_id' => $mh->id,
        'level' => 2,
        'is_active' => true,
    ]);
    $taluka = Location::query()->create([
        'name' => 'Tasgaon',
        'slug' => 'tasgaon-tal-cat-cmd',
        'hierarchy' => 'taluka',
        'parent_id' => $otherDist->id,
        'level' => 3,
        'is_active' => true,
    ]);
    $village = Location::query()->create([
        'name' => 'Pune',
        'slug' => 'pune-city-legacy-cat-cmd',
        'hierarchy' => 'village',
        'parent_id' => $taluka->id,
        'level' => 4,
        'is_active' => true,
    ]);

    Artisan::call('locations:dedupe-and-categorize', [
        '--without-dedupe' => true,
    ]);

    expect($india->fresh()->category)->toBeNull();
    expect($mh->fresh()->category)->toBeNull();
    expect($puneDist->fresh()->category)->toBe('city');
    expect($otherDist->fresh()->category)->toBe('city');
    expect($taluka->fresh()->category)->toBe('city');
    expect(Location::query()->find($village->id)?->category)->toBe('city');
    expect(Location::query()->find($village->id)?->hierarchy)->toBe('village');
    expect($puneDist->fresh()->hierarchy)->toBe('district');
});
