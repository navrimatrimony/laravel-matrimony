<?php

namespace Tests\Feature\Location;

use App\Services\Location\GeoCentroidBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A taluka the acceptance gate refuses keeps a NULL centre until somebody who knows the ground supplies
 * one by hand. That hand-supplied value is NOT derived from the villages, so `--force` must leave it
 * alone — otherwise the very next maintenance run replaces a known-correct point with the median the
 * gate already rejected, or (worse) clears it, and the owner's work silently disappears.
 *
 * The stamp is the existing `addresses.geo_source` column
 * ({@see GeoCentroidBackfillService::SOURCE_MANUAL}) — no parallel flag column.
 */
class GeoCentroidManualCentreTest extends TestCase
{
    use RefreshDatabase;

    private int $derivedTalukaId;

    private int $manualTalukaId;

    public function test_force_recomputes_a_derived_centre_but_never_an_owner_supplied_one(): void
    {
        $this->seedMaharashtraFixture();

        $service = new GeoCentroidBackfillService;
        $service->backfillDistricts(false);
        $service->backfillTalukas(false);

        // Both talukas start out derived from their own villages.
        $derived = $this->taluka($this->derivedTalukaId);
        $this->assertNotNull($derived->lat, 'the derived taluka should have been filled from its villages');
        $this->assertSame(GeoCentroidBackfillService::SOURCE_VILLAGE_MEDIAN, $derived->geo_source);

        // The owner now overrides the second one with a point nowhere near its villages — exactly the
        // situation the backfill must not "fix".
        DB::table('addresses')->where('id', $this->manualTalukaId)->update([
            'lat' => '19.5000000',
            'lng' => '75.5000000',
            'geo_source' => GeoCentroidBackfillService::SOURCE_MANUAL,
        ]);

        $result = $service->backfillTalukas(true);

        $manual = $this->taluka($this->manualTalukaId);
        $this->assertEqualsWithDelta(19.5, (float) $manual->lat, 0.0000001, '--force overwrote an owner-supplied latitude');
        $this->assertEqualsWithDelta(75.5, (float) $manual->lng, 0.0000001, '--force overwrote an owner-supplied longitude');
        $this->assertSame(GeoCentroidBackfillService::SOURCE_MANUAL, $manual->geo_source);
        $this->assertSame(1, $result['manual'], '--force should report the owner-set row it left alone');

        // Control: the derived row is still eligible, so --force did run and did reach the other taluka.
        $recomputed = $this->taluka($this->derivedTalukaId);
        $this->assertEqualsWithDelta((float) $derived->lat, (float) $recomputed->lat, 0.0000001);
        $this->assertSame(GeoCentroidBackfillService::SOURCE_VILLAGE_MEDIAN, $recomputed->geo_source);
    }

    public function test_a_manual_centre_is_not_cleared_when_its_villages_would_be_refused(): void
    {
        $this->seedMaharashtraFixture();

        // Scatter this taluka's villages so the median can never pass the consensus check. Before the
        // skip existed, --force on such a row CLEARED lat/lng.
        $scatter = [[16.0, 73.0], [18.5, 75.0], [21.0, 79.0], [17.2, 74.4], [20.4, 78.1]];
        $villages = DB::table('addresses')
            ->where('parent_id', $this->manualTalukaId)
            ->where('hierarchy', 'village')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        foreach ($villages as $i => $villageId) {
            DB::table('addresses')->where('id', $villageId)->update([
                'lat' => number_format($scatter[$i % count($scatter)][0], 7, '.', ''),
                'lng' => number_format($scatter[$i % count($scatter)][1], 7, '.', ''),
            ]);
        }

        DB::table('addresses')->where('id', $this->manualTalukaId)->update([
            'lat' => '18.4239000',
            'lng' => '75.7876000',
            'geo_source' => GeoCentroidBackfillService::SOURCE_MANUAL,
        ]);

        (new GeoCentroidBackfillService)->backfillTalukas(true);

        $manual = $this->taluka($this->manualTalukaId);
        $this->assertEqualsWithDelta(18.4239, (float) $manual->lat, 0.0000001, 'a refused recomputation cleared an owner-supplied centre');
        $this->assertEqualsWithDelta(75.7876, (float) $manual->lng, 0.0000001);
        $this->assertSame(GeoCentroidBackfillService::SOURCE_MANUAL, $manual->geo_source);
    }

    private function taluka(int $id): object
    {
        return DB::table('addresses')->where('id', $id)->first();
    }

    /** state → district → 2 talukas → 5 tightly clustered villages each. */
    private function seedMaharashtraFixture(): void
    {
        $stateId = $this->address(null, 'Maharashtra', 'maharashtra', 'state', 1);
        $districtId = $this->address($stateId, 'Testpur', 'testpur', 'district', 2);

        $this->derivedTalukaId = $this->address($districtId, 'Derivedwadi', 'derivedwadi', 'taluka', 3);
        $this->manualTalukaId = $this->address($districtId, 'Manualwadi', 'manualwadi', 'taluka', 3);

        foreach ([$this->derivedTalukaId => 18.50, $this->manualTalukaId => 18.60] as $talukaId => $baseLat) {
            for ($i = 0; $i < 5; $i++) {
                $this->address(
                    $talukaId,
                    "V{$talukaId}-{$i}",
                    "v-{$talukaId}-{$i}",
                    'village',
                    4,
                    $baseLat + ($i * 0.01),
                    74.50 + ($i * 0.01),
                );
            }
        }
    }

    private function address(
        ?int $parentId,
        string $name,
        string $slug,
        string $hierarchy,
        int $level,
        ?float $lat = null,
        ?float $lng = null,
    ): int {
        return (int) DB::table('addresses')->insertGetId([
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
            'hierarchy' => $hierarchy,
            'level' => $level,
            'lat' => $lat === null ? null : number_format($lat, 7, '.', ''),
            'lng' => $lng === null ? null : number_format($lng, 7, '.', ''),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
