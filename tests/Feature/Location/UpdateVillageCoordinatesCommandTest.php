<?php

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('village coordinate command updates all covered village rows by lgd code', function () {
    [$taluka] = createCoordinateTestTaluka();
    createCoordinateTestVillage($taluka->id, 'Bhood', '568523', '415309', '18.9346000', '76.9046000');
    createCoordinateTestVillage($taluka->id, 'Doma', '540482', '442903', '24.0905000', '78.7804000');

    $csv = writeVillageCoordinateUpdateCsv([
        ['568523', '17.3363900', '74.6841700', 'manual_verified', 'wikipedia:Bhood'],
        ['540482', '20.5809300', '79.5058100', 'geonames_place_admin3', 'geonames:10576166'],
    ]);

    $this->artisan('addresses:update-village-coordinates', [
        'path' => $csv,
    ])->assertExitCode(0);

    $bhood = Location::query()->where('lgd_code', '568523')->firstOrFail();
    $doma = Location::query()->where('lgd_code', '540482')->firstOrFail();

    expect(round((float) $bhood->lat, 5))->toBe(17.33639);
    expect(round((float) $bhood->lng, 5))->toBe(74.68417);
    expect(round((float) $doma->lat, 5))->toBe(20.58093);
    expect(round((float) $doma->lng, 5))->toBe(79.50581);
});

test('village coordinate command validates full coverage before writing', function () {
    [$taluka] = createCoordinateTestTaluka();
    createCoordinateTestVillage($taluka->id, 'Bhood', '568523', '415309', '18.9346000', '76.9046000');
    createCoordinateTestVillage($taluka->id, 'Missing', '999999', '415309', '18.0000000', '76.0000000');

    $csv = writeVillageCoordinateUpdateCsv([
        ['568523', '17.3363900', '74.6841700', 'manual_verified', 'wikipedia:Bhood'],
    ]);

    $this->artisan('addresses:update-village-coordinates', [
        'path' => $csv,
    ])->assertExitCode(1);

    $bhood = Location::query()->where('lgd_code', '568523')->firstOrFail();
    $missing = Location::query()->where('lgd_code', '999999')->firstOrFail();

    expect(round((float) $bhood->lat, 4))->toBe(18.9346);
    expect(round((float) $bhood->lng, 4))->toBe(76.9046);
    expect(round((float) $missing->lat, 1))->toBe(18.0);
    expect(round((float) $missing->lng, 1))->toBe(76.0);
});

test('village coordinate command rejects duplicate or out of bounds coordinates', function () {
    [$taluka] = createCoordinateTestTaluka();
    createCoordinateTestVillage($taluka->id, 'Bhood', '568523', '415309', '18.9346000', '76.9046000');

    $csv = writeVillageCoordinateUpdateCsv([
        ['568523', '17.3363900', '74.6841700', 'manual_verified', 'wikipedia:Bhood'],
        ['568523', '1.0000000', '10.0000000', 'bad', 'duplicate'],
    ]);

    $this->artisan('addresses:update-village-coordinates', [
        'path' => $csv,
    ])->assertExitCode(1);

    expect(round((float) DB::table('addresses')->where('lgd_code', '568523')->value('lat'), 4))->toBe(18.9346);
});

function createCoordinateTestTaluka(): array
{
    $country = createCoordinateTestLocation([
        'name' => 'India',
        'slug' => 'india',
        'hierarchy' => 'country',
        'parent_id' => null,
    ]);

    $state = createCoordinateTestLocation([
        'name' => 'Maharashtra',
        'slug' => 'maharashtra',
        'hierarchy' => 'state',
        'parent_id' => $country->id,
    ]);

    $district = createCoordinateTestLocation([
        'name' => 'Sangli',
        'slug' => 'sangli',
        'hierarchy' => 'district',
        'parent_id' => $state->id,
    ]);

    $taluka = createCoordinateTestLocation([
        'name' => 'Khanapur',
        'slug' => 'khanapur',
        'hierarchy' => 'taluka',
        'parent_id' => $district->id,
    ]);

    return [$taluka, $district, $state, $country];
}

function createCoordinateTestVillage(int $parentId, string $name, string $lgdCode, string $pincode, string $lat, string $lng): Location
{
    return createCoordinateTestLocation([
        'name' => $name,
        'slug' => strtolower($name),
        'hierarchy' => 'village',
        'parent_id' => $parentId,
        'tag' => 'rural',
        'pincode' => $pincode,
        'lat' => $lat,
        'lng' => $lng,
        'lgd_code' => $lgdCode,
    ]);
}

function createCoordinateTestLocation(array $attributes): Location
{
    return Location::query()->create(array_merge([
        'name_en' => $attributes['name'],
        'name_mr' => null,
        'tag' => null,
        'pincode' => null,
        'lat' => null,
        'lng' => null,
        'lgd_code' => null,
        'is_active' => true,
    ], $attributes));
}

function writeVillageCoordinateUpdateCsv(array $rows): string
{
    $dir = storage_path('framework/testing');
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $path = $dir.'/village-coordinate-update-'.str_replace('.', '-', uniqid('', true)).'.csv';
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Unable to write coordinate update CSV.');
    }

    fputcsv($handle, ['lgd_code', 'lat', 'lng', 'source', 'source_id']);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }

    fclose($handle);

    return $path;
}
