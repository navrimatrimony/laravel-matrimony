<?php

use App\Models\City;
use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\MasterGender;
use App\Models\MasterMaritalStatus;
use App\Models\User;
use App\Services\MatrimonyProfileSearchQueryService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(MasterLookupSeeder::class);
    $this->seed(MinimalLocationSeeder::class);
});

function profileSearchNearbyAttachResidence(MatrimonyProfile $profile, int $locationId): void
{
    if (Schema::hasColumn('matrimony_profiles', 'location_id')) {
        $profile->forceFill(['location_id' => $locationId])->save();

        return;
    }

    ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $locationId, null, true, false);
}

function profileSearchNearbyCreateProfile(string $name, int $locationId): MatrimonyProfile
{
    $profile = MatrimonyProfile::query()->create([
        'user_id' => User::factory()->create()->id,
        'full_name' => $name,
        'gender_id' => MasterGender::query()->value('id'),
        'date_of_birth' => now()->subYears(28)->toDateString(),
        'marital_status_id' => MasterMaritalStatus::query()->value('id'),
        'highest_education' => 'Graduate',
        'height_cm' => 165,
        'lifecycle_state' => 'draft',
        'is_suspended' => false,
        'visibility_override' => true,
        'location_id' => $locationId,
    ]);

    profileSearchNearbyAttachResidence($profile, $locationId);
    $profile->forceFill(['lifecycle_state' => 'active'])->save();

    return $profile;
}

test('profile search can filter and order profiles within nearby radius', function () {
    $pune = City::query()->where('name', 'Pune City')->firstOrFail();
    $ahmedabad = City::query()->where('name', 'Ahmedabad City')->firstOrFail();

    $source = Location::query()->create([
        'name' => 'Wakad Nearby Search Source',
        'slug' => 'wakad-nearby-search-source-'.uniqid(),
        'hierarchy' => 'village',
        'tag' => 'suburban',
        'parent_id' => $pune->parent_id,
        'is_active' => true,
    ]);

    Location::query()->whereKey($source->id)->update(['pincode' => '411057', 'lat' => 18.5912, 'lng' => 73.7400]);
    Location::query()->whereKey($pune->id)->update(['pincode' => '411001', 'lat' => 18.5204, 'lng' => 73.8567]);
    Location::query()->whereKey($ahmedabad->id)->update(['pincode' => '380001', 'lat' => 23.0225, 'lng' => 72.5714]);

    $samePlace = profileSearchNearbyCreateProfile('Same Place Candidate', (int) $source->id);
    $nearby = profileSearchNearbyCreateProfile('Nearby Candidate', (int) $pune->id);
    $far = profileSearchNearbyCreateProfile('Far Candidate', (int) $ahmedabad->id);

    $request = Request::create('/profiles', 'GET', [
        'nearby_location_id' => $source->id,
        'nearby_radius_km' => 25,
    ]);

    $query = MatrimonyProfileSearchQueryService::newFilteredListingQuery($request, null);
    $distances = MatrimonyProfileSearchQueryService::nearbyDistanceMapFromRequest($request);
    MatrimonyProfileSearchQueryService::applyNearbyDistanceSelect($query, $distances);
    MatrimonyProfileSearchQueryService::applyNearbyOrdering($query);

    $rows = $query->pluck('nearby_distance_km', 'id')->all();

    expect(array_keys($rows))->toContain($samePlace->id)
        ->toContain($nearby->id)
        ->not->toContain($far->id);

    expect((float) $rows[$samePlace->id])->toBe(0.0);
    expect((float) $rows[$nearby->id])->toBeGreaterThan(0.0);
});
