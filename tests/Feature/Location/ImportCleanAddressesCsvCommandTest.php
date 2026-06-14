<?php

use App\Models\Location;
use App\Support\Location\AddressSchemaEnumOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('address schema exposes hierarchy only and nullable classification tags', function () {
    expect(Schema::hasColumn('addresses', 'type'))->toBeFalse();
    expect(Schema::hasColumn('addresses', 'hierarchy'))->toBeTrue();
    expect(Schema::hasColumn('addresses', 'iso_alpha2'))->toBeFalse();

    expect(AddressSchemaEnumOptions::addressHierarchies())->toBe(['country', 'state', 'district', 'taluka', 'village']);
    expect(AddressSchemaEnumOptions::addressTags())->toBe(['city', 'suburban', 'rural']);

    if (DB::getDriverName() === 'mysql') {
        $db = DB::getDatabaseName();
        $hierarchy = DB::selectOne(
            'SELECT COLUMN_TYPE AS column_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$db, 'addresses', 'hierarchy']
        );
        $tag = DB::selectOne(
            'SELECT COLUMN_TYPE AS column_type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$db, 'addresses', 'tag']
        );

        expect((string) $hierarchy->column_type)->toBe("enum('country','state','district','taluka','village')");
        expect((string) $tag->column_type)->toBe("enum('city','suburban','rural')");
        expect((string) $tag->nullable)->toBe('YES');
        expect($tag->default_value)->toBeNull();
    }
});

test('clean csv import writes hierarchy types and separate classification tags', function () {
    $csv = writeAddressImportCsv([
        ['1', '27', 'Maharashtra', '521', 'Pune', '4221', 'Haveli', '1001', 'Wakad', 'वाकड', '', '411057', '18.597000', '73.770000', 'city'],
        ['2', '27', 'Maharashtra', '521', 'Pune', '4221', 'Haveli', '1002', 'Baner', 'बाणेर', '', '411045', '18.559000', '73.786800', 'suburban'],
        ['3', '27', 'Maharashtra', '527', 'Satara', '4260', 'Man', '1003', 'Ruralgaon', '', 'रुरलगाव', '415509', '17.674000', '74.345000', 'rural'],
    ]);

    $this->artisan('addresses:import-clean-csv', [
        'path' => $csv,
        '--fresh' => true,
    ])->assertExitCode(0);

    expect(DB::table('addresses')->whereIn('hierarchy', ['city', 'suburban'])->count())->toBe(0);
    expect(DB::table('addresses')->where('tag', 'suburban')->count())->toBe(3);

    expect(DB::table('addresses')->where('hierarchy', 'country')->count())->toBe(1);
    expect(DB::table('addresses')->where('hierarchy', 'state')->count())->toBe(1);
    expect(DB::table('addresses')->where('hierarchy', 'district')->count())->toBe(2);
    expect(DB::table('addresses')->where('hierarchy', 'taluka')->count())->toBe(2);
    expect(DB::table('addresses')->where('hierarchy', 'village')->count())->toBe(3);

    $baner = Location::query()->where('name', 'Baner')->firstOrFail();
    $haveli = Location::query()->findOrFail((int) $baner->parent_id);
    $pune = Location::query()->findOrFail((int) $haveli->parent_id);
    $maharashtra = Location::query()->findOrFail((int) $pune->parent_id);
    $india = Location::query()->findOrFail((int) $maharashtra->parent_id);

    expect($baner->hierarchy)->toBe('village');
    expect($baner->tag)->toBe('suburban');
    expect($baner->slug)->toBe('baner');
    expect($baner->level)->toBe(4);
    expect($baner->lgd_code)->toBe('1002');
    expect($haveli->hierarchy)->toBe('taluka')->and($haveli->level)->toBe(3)->and($haveli->tag)->toBe('suburban')->and($haveli->lgd_code)->toBeNull()->and($haveli->slug)->toBe('haveli');
    expect($pune->hierarchy)->toBe('district')->and($pune->level)->toBe(2)->and($pune->tag)->toBe('city')->and($pune->lgd_code)->toBeNull()->and($pune->slug)->toBe('pune');
    expect($maharashtra->hierarchy)->toBe('state')->and($maharashtra->level)->toBe(1)->and($maharashtra->tag)->toBeNull()->and($maharashtra->lgd_code)->toBeNull()->and($maharashtra->slug)->toBe('maharashtra');
    expect($india->hierarchy)->toBe('country')->and($india->level)->toBe(0)->and($india->tag)->toBeNull()->and($india->lgd_code)->toBeNull()->and($india->slug)->toBe('india');

    expect(DB::table('addresses')
        ->whereIn('hierarchy', ['country', 'state', 'district', 'taluka'])
        ->whereNotNull('lgd_code')
        ->count())->toBe(0);

    expect(DB::table('addresses')
        ->where('hierarchy', 'village')
        ->whereNotNull('lgd_code')
        ->count())->toBe(3);
});

test('clean csv import suffixes slug only for real duplicate under same parent and hierarchy', function () {
    $csv = writeAddressImportCsv([
        ['1', '27', 'Maharashtra', '521', 'Pune', '4221', 'Haveli', '1001', 'Baner', 'बाणेर', '', '411045', '18.559000', '73.786800', 'city'],
        ['2', '27', 'Maharashtra', '522', 'Pune!', '4222', 'Mulshi', '1002', 'Paud', 'पौड', '', '412108', '18.520000', '73.610000', 'rural'],
    ]);

    $this->artisan('addresses:import-clean-csv', [
        'path' => $csv,
        '--fresh' => true,
    ])->assertExitCode(0);

    $districtSlugs = Location::query()
        ->where('hierarchy', 'district')
        ->whereIn('name', ['Pune', 'Pune!'])
        ->orderBy('id')
        ->pluck('slug')
        ->all();

    expect($districtSlugs)->toBe(['pune', 'pune-2']);
});

test('clean csv import can use coordinate override instead of source csv lat lng', function () {
    $csv = writeAddressImportCsv([
        ['1', '27', 'Maharashtra', '531', 'Sangli', '4299', 'Khanapur', '568523', 'Bhood', 'भूड', '', '415309', '18.934600', '76.904600', 'rural'],
    ]);
    $override = writeAddressImportCoordinateOverrideCsv([
        ['568523', '17.3363900', '74.6841700', 'manual_verified', 'wikipedia:Bhood'],
    ]);

    $this->artisan('addresses:import-clean-csv', [
        'path' => $csv,
        '--fresh' => true,
        '--coordinate-override' => $override,
    ])->assertExitCode(0);

    $bhood = Location::query()->where('lgd_code', '568523')->firstOrFail();

    expect(round((float) $bhood->lat, 5))->toBe(17.33639);
    expect(round((float) $bhood->lng, 5))->toBe(74.68417);
});

test('fresh import validates before deleting existing addresses', function () {
    Location::query()->create([
        'id' => 90,
        'name' => 'Existing India',
        'slug' => 'existing-india',
        'hierarchy' => 'country',
        'tag' => null,
        'parent_id' => null,
        'level' => 0,
        'is_active' => true,
    ]);

    $csv = writeAddressImportCsv([
        ['1', '27', 'Maharashtra', '521', 'Pune', '4221', 'Haveli', '1001', 'Bad Tag Village', '', '', '411057', '18.597000', '73.770000', 'metro'],
    ]);

    $this->artisan('addresses:import-clean-csv', [
        'path' => $csv,
        '--fresh' => true,
    ])->assertExitCode(1);

    expect(DB::table('addresses')->where('slug', 'existing-india')->exists())->toBeTrue();
    expect(DB::table('addresses')->where('name', 'Bad Tag Village')->exists())->toBeFalse();
});

test('address hierarchy search no longer queries city or suburban as hierarchy types', function () {
    $source = file_get_contents(app_path('Services/Location/AddressHierarchySearch.php'));

    expect($source)->not->toContain("whereIn('hierarchy', ['city', 'suburban'])");
    expect($source)->not->toContain('in_array($parent->hierarchy, [\'city\', \'suburban\'], true)');
    expect($source)->not->toContain("leaf->hierarchy === 'city'");
    expect($source)->not->toContain("leaf->hierarchy === 'suburb'");
});

function writeAddressImportCsv(array $rows): string
{
    $dir = storage_path('framework/testing');
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $path = $dir.'/addresses-import-'.str_replace('.', '-', uniqid('', true)).'.csv';
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Unable to write test CSV.');
    }

    fputcsv($handle, [
        'S.No.',
        'State Code',
        'State Name (In English)',
        'District Code',
        'District Name (In English)',
        'Sub-District Code',
        'Sub-District Name (In English)',
        'lgd_code',
        'Village Name (In English)',
        'Village Name (In Local)',
        'name_mr',
        'Pincode',
        'Latitude',
        'Longitude',
        'Tag',
    ]);

    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }

    fclose($handle);

    return $path;
}

function writeAddressImportCoordinateOverrideCsv(array $rows): string
{
    $dir = storage_path('framework/testing');
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $path = $dir.'/addresses-import-coordinate-override-'.str_replace('.', '-', uniqid('', true)).'.csv';
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Unable to write coordinate override CSV.');
    }

    fputcsv($handle, ['lgd_code', 'lat', 'lng', 'source', 'source_id']);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }

    fclose($handle);

    return $path;
}
