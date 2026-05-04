<?php

namespace Tests\Feature\Location;

use App\Models\City;
use App\Models\LocationAlias;
use App\Models\LocationOpenPlaceSuggestion;
use App\Models\User;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoteOpenPlaceSuggestionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_promotes_approved_open_place_to_geo_json(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $user = User::factory()->create();
        $city = City::query()->where('name', 'Pune City')->firstOrFail();

        LocationAlias::query()->create([
            'location_id' => $city->id,
            'alias' => 'Pune',
            'normalized_alias' => 'pune',
            'is_active' => true,
        ]);

        LocationOpenPlaceSuggestion::query()->create([
            'raw_input' => 'Pune',
            'normalized_input' => 'pune',
            'resolved_city_id' => $city->id,
            'status' => 'approved',
            'usage_count' => 5,
            'suggested_by' => $user->id,
            'match_type' => 'manual',
            'confidence_score' => 1.0,
        ]);

        $tmp = $this->tmpJsonPath('geo-promote-1');
        file_put_contents($tmp, "[]\n");

        $this->artisan('location:promote-open-places', ['--path' => $tmp])
            ->assertSuccessful();

        $rows = json_decode((string) file_get_contents($tmp), true);
        $this->assertIsArray($rows);
        $this->assertNotEmpty($rows);
        $first = $rows[0];
        $this->assertSame('Pune City', $first['name']);
        $this->assertSame('27', $first['statecode']);
        $this->assertSame('Pune', $first['district']);
        $this->assertSame('Haveli', $first['taluka']);
        $this->assertContains('pune', array_map('strtolower', $first['aliases'] ?? []));
        $manifestPath = dirname($tmp).DIRECTORY_SEPARATOR.'geo_manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertSame(1, (int) ($manifest['files']['cities.json']['count'] ?? 0));
        $this->assertStringStartsWith('sha256:', (string) ($manifest['files']['cities.json']['checksum'] ?? ''));
    }

    public function test_command_dedupes_existing_city_entry_and_merges_aliases(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $user = User::factory()->create();
        $city = City::query()->where('name', 'Pune City')->firstOrFail();

        LocationAlias::query()->create([
            'location_id' => $city->id,
            'alias' => 'Pune',
            'normalized_alias' => 'pune',
            'is_active' => true,
        ]);
        LocationAlias::query()->create([
            'location_id' => $city->id,
            'alias' => 'Pune City',
            'normalized_alias' => 'punecity',
            'is_active' => true,
        ]);

        LocationOpenPlaceSuggestion::query()->create([
            'raw_input' => 'Pune',
            'normalized_input' => 'pune',
            'resolved_city_id' => $city->id,
            'status' => 'approved',
            'usage_count' => 7,
            'suggested_by' => $user->id,
            'match_type' => 'manual',
            'confidence_score' => 1.0,
        ]);

        $tmp = $this->tmpJsonPath('geo-promote-2');
        file_put_contents($tmp, json_encode([
            [
                'name' => 'Pune City',
                'statecode' => '27',
                'district' => 'Pune',
                'taluka' => 'Haveli',
                'aliases' => ['pune'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL);

        $this->artisan('location:promote-open-places', ['--path' => $tmp])
            ->assertSuccessful();

        $rows = json_decode((string) file_get_contents($tmp), true);
        $this->assertCount(1, $rows);
        $aliases = array_map(static fn ($v) => strtolower((string) $v), $rows[0]['aliases'] ?? []);
        $this->assertContains('pune', $aliases);
        $this->assertContains('pune city', $aliases);
        $this->assertContains('punecity', $aliases);
        $this->assertSame($aliases, $this->sortedCaseInsensitive($aliases));
    }

    public function test_dry_run_does_not_write_manifest_or_json_changes(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $user = User::factory()->create();
        $city = City::query()->where('name', 'Pune City')->firstOrFail();

        LocationOpenPlaceSuggestion::query()->create([
            'raw_input' => 'Pune',
            'normalized_input' => 'pune',
            'resolved_city_id' => $city->id,
            'status' => 'approved',
            'usage_count' => 2,
            'suggested_by' => $user->id,
            'match_type' => 'manual',
            'confidence_score' => 1.0,
        ]);

        $tmp = $this->tmpJsonPath('geo-promote-3');
        file_put_contents($tmp, "[]\n");
        $beforeJson = (string) file_get_contents($tmp);
        $manifestPath = dirname($tmp).DIRECTORY_SEPARATOR.'geo_manifest.json';
        if (is_file($manifestPath)) {
            @unlink($manifestPath);
        }

        $this->artisan('location:promote-open-places', ['--path' => $tmp, '--dry-run' => true])
            ->assertSuccessful();

        $afterJson = (string) file_get_contents($tmp);
        $this->assertSame($beforeJson, $afterJson);
        $this->assertFileDoesNotExist($manifestPath);
    }

    public function test_rerun_is_idempotent_and_checksum_stable(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $user = User::factory()->create();
        $city = City::query()->where('name', 'Pune City')->firstOrFail();

        LocationAlias::query()->create([
            'location_id' => $city->id,
            'alias' => 'Pune',
            'normalized_alias' => 'pune',
            'is_active' => true,
        ]);

        LocationOpenPlaceSuggestion::query()->create([
            'raw_input' => 'Pune',
            'normalized_input' => 'pune',
            'resolved_city_id' => $city->id,
            'status' => 'approved',
            'usage_count' => 9,
            'suggested_by' => $user->id,
            'match_type' => 'manual',
            'confidence_score' => 1.0,
        ]);

        $tmp = $this->tmpJsonPath('geo-promote-4');
        file_put_contents($tmp, "[]\n");
        $manifestPath = dirname($tmp).DIRECTORY_SEPARATOR.'geo_manifest.json';

        $this->artisan('location:promote-open-places', ['--path' => $tmp])
            ->assertSuccessful();
        $firstJson = (string) file_get_contents($tmp);
        $firstManifest = json_decode((string) file_get_contents($manifestPath), true);
        $firstChecksum = (string) ($firstManifest['files']['cities.json']['checksum'] ?? '');
        $this->assertStringStartsWith('sha256:', $firstChecksum);

        $this->artisan('location:promote-open-places', ['--path' => $tmp])
            ->assertSuccessful();
        $secondJson = (string) file_get_contents($tmp);
        $secondManifest = json_decode((string) file_get_contents($manifestPath), true);
        $secondChecksum = (string) ($secondManifest['files']['cities.json']['checksum'] ?? '');

        $this->assertSame($firstJson, $secondJson);
        $this->assertSame($firstChecksum, $secondChecksum);
    }

    private function tmpJsonPath(string $prefix): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .$prefix.'-'.uniqid('', true).'.json';
    }

    /**
     * @param  array<int, string>  $items
     * @return array<int, string>
     */
    private function sortedCaseInsensitive(array $items): array
    {
        $copy = $items;
        usort($copy, fn (string $a, string $b) => strnatcasecmp($a, $b));

        return $copy;
    }
}
