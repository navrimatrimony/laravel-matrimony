<?php

use App\Models\Location;
use App\Services\Location\LocationService;
use App\Services\ProfilePreferenceMatchService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(MinimalLocationSeeder::class);
});

/*
 * Known, pre-existing: running any location-seeding test file before
 * tests/Feature/MatchingEngineCorrectnessTest.php in the same process makes four of its
 * caste/religion/ladder cases fail, because process-level memos keyed by database id survive
 * RefreshDatabase. This is not caused by this file — tests/Feature/Location/LocationFormatterServiceTest.php
 * reproduces it identically, and so does this file with every change in its commit series reverted.
 * ProfilePreferenceMatchService::flushRuntimeCaches() does NOT clear whatever holds it. Verify this
 * file and the matching suite separately until that leak is fixed on its own terms.
 */

/**
 * Guards the invariant a query-budget test cannot see.
 *
 * A discovery page resolved fewer queries but ran four times slower in production, because
 * LocationService had been given a pool of every geo node it had already loaded, and the parent-chain
 * rewiring loop then walked that whole pool on every single call. Cost per call grew linearly with
 * how much geography the request had already touched — measured at ~6.7 us per pooled node, so a
 * pool of a few thousand turned a 57 us call into a 20 ms one. Query count went DOWN the whole time,
 * which is exactly why nothing caught it: the regression was CPU, not round trips.
 *
 * The assertion is therefore about work, not queries, and is written against the public surface
 * only — it makes no reference to any cache or pool, so it holds for any implementation and would
 * fail again for a different one that reintroduced the same shape.
 */
test('resolving one location does not get slower as the service resolves more locations', function () {
    $taluka = Location::query()->where('hierarchy', 'taluka')->firstOrFail();

    $probe = Location::query()->create([
        'name' => 'Probe Village',
        'slug' => 'scale-probe-'.uniqid(),
        'hierarchy' => 'village',
        'parent_id' => $taluka->id,
        'is_active' => true,
    ]);

    // Enough unrelated villages to make an O(pool) implementation obvious, few enough to stay quick.
    // Bulk insert skips the model's saving hook, so `level` is set explicitly from the same source
    // that hook uses rather than hardcoded here.
    $villageLevel = Location::defaultLevelForHierarchy('village');
    $others = [];
    for ($i = 0; $i < 400; $i++) {
        $others[] = [
            'name' => 'Filler '.$i,
            'slug' => 'scale-filler-'.$i.'-'.uniqid(),
            'hierarchy' => 'village',
            'level' => $villageLevel,
            'parent_id' => $taluka->id,
            'is_active' => true,
        ];
    }
    Location::query()->insert($others);
    $otherIds = Location::query()
        ->where('hierarchy', 'village')
        ->where('name', 'like', 'Filler %')
        ->pluck('id')
        ->all();

    // One service for the whole test: this is what the container hands out per request, and sharing
    // it is precisely the condition under which the regression appeared.
    $service = app(LocationService::class);

    $measure = static function () use ($service, $probe): float {
        // Best of three. The minimum is far more stable than a mean when a GC pause or a noisy CI
        // box lands inside one round, and this test must not be flaky.
        $best = INF;
        for ($round = 0; $round < 3; $round++) {
            $start = microtime(true);
            for ($i = 0; $i < 200; $i++) {
                $service->getAncestors($probe);
            }
            $best = min($best, (microtime(true) - $start) * 1000);
        }

        return $best;
    };

    $service->getAncestors($probe);   // warm: load the chain once, so neither phase pays for it
    $before = $measure();

    foreach ($otherIds as $id) {
        $location = Location::query()->find($id);
        if ($location !== null) {
            $service->getAncestors($location);
        }
    }

    $after = $measure();

    // The regressed implementation came in around 45x here. A healthy one sits at roughly 1x, so 5x
    // is a wide margin against timing noise while still failing that shape by an order of magnitude.
    // The absolute floor keeps a sub-millisecond baseline from turning noise into a false failure.
    $ceiling = max($before * 5, 50.0);

    expect($after)->toBeLessThan(
        $ceiling,
        sprintf(
            'Resolving one location cost %.1f ms before the service had seen 400 others and %.1f ms '
            .'after (%.1fx). Ancestor resolution must not scale with how much geography the request '
            .'has already touched.',
            $before,
            $after,
            $before > 0 ? $after / $before : 0
        )
    );
});
