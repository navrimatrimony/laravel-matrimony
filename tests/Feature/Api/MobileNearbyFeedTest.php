<?php

use App\Models\Location;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use App\Models\ProfileView;
use App\Models\User;
use App\Services\MutationService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * "जवळची स्थळे" feed — own taluka first, widening outward with no district/state ceiling.
 *
 * @see \App\Services\Matching\NearbyFeedService
 */

/** @return array{country: Location, state: Location, far_state: Location} */
function nearbyFeedGeography(): array
{
    $suffix = strtolower(str_replace('.', '-', uniqid('nearby-', true)));

    $country = Location::create([
        'name' => 'India '.$suffix, 'slug' => 'india-'.$suffix,
        'hierarchy' => 'country', 'is_active' => true,
    ]);
    $state = Location::create([
        'name' => 'Maharashtra '.$suffix, 'slug' => 'mh-'.$suffix,
        'hierarchy' => 'state', 'parent_id' => $country->id, 'is_active' => true,
    ]);
    $farState = Location::create([
        'name' => 'Gujarat '.$suffix, 'slug' => 'gj-'.$suffix,
        'hierarchy' => 'state', 'parent_id' => $country->id, 'is_active' => true,
    ]);

    return ['country' => $country, 'state' => $state, 'far_state' => $farState];
}

function nearbyFeedTaluka(Location $state, string $name, float $lat, float $lng): Location
{
    $suffix = strtolower(str_replace('.', '-', uniqid('nb-', true)));

    $district = Location::create([
        'name' => $name.' District '.$suffix, 'slug' => 'dist-'.$suffix,
        'hierarchy' => 'district', 'parent_id' => $state->id, 'is_active' => true,
    ]);
    $taluka = Location::create([
        'name' => $name.' '.$suffix, 'slug' => 'tal-'.$suffix,
        'hierarchy' => 'taluka', 'parent_id' => $district->id, 'is_active' => true,
    ]);
    // lat/lng are guarded on create in some environments; set them explicitly.
    Location::query()->whereKey($taluka->id)->update(['lat' => $lat, 'lng' => $lng]);

    return $taluka->refresh();
}

function nearbyFeedVillage(Location $taluka): Location
{
    $suffix = strtolower(str_replace('.', '-', uniqid('vil-', true)));

    return Location::create([
        'name' => 'Village '.$suffix, 'slug' => 'vil-'.$suffix,
        'hierarchy' => 'village', 'parent_id' => $taluka->id, 'is_active' => true,
    ]);
}

function nearbyFeedSeedAddressType(): void
{
    $values = ['label' => 'Current', 'created_at' => now(), 'updated_at' => now()];
    if (Schema::hasColumn('master_address_types', 'label_mr')) {
        $values['label_mr'] = 'Current';
    }
    DB::table('master_address_types')->updateOrInsert(['key' => 'current'], $values);
    ProfileCanonicalResidenceService::forgetCachedMasters();
    ProfileCanonicalResidenceService::flushRuntimeCaches();
}

function nearbyFeedProfile(string $name, string $genderKey, ?Location $residenceLeaf): MatrimonyProfile
{
    nearbyFeedSeedAddressType();

    $user = User::factory()->create(['name' => $name]);
    $gender = MasterGender::query()->firstOrCreate(
        ['key' => $genderKey],
        ['label' => ucfirst($genderKey), 'is_active' => true]
    );

    $profile = app(MutationService::class)->createDraftProfileForUser($user);
    $core = [
        'full_name' => $name,
        'gender_id' => $gender->id,
        'date_of_birth' => '1995-01-05',
    ];
    if ($residenceLeaf !== null) {
        $core['location_id'] = $residenceLeaf->id;
    }
    app(MutationService::class)->applyManualSnapshot($profile, ['core' => $core], (int) $user->id, 'manual');

    $profile->refresh();
    $profile->lifecycle_state = 'active';
    $profile->is_suspended = false;
    $profile->save();

    return $profile->refresh();
}

function nearbyFeedApprovePhoto(MatrimonyProfile $profile): void
{
    Storage::disk('public')->put('matrimony_photos/nearby-'.$profile->id.'.webp', 'bytes');
    ProfilePhoto::query()->create([
        'profile_id' => $profile->id,
        'file_path' => 'nearby-'.$profile->id.'.webp',
        'is_primary' => true,
        'sort_order' => 0,
        'uploaded_via' => 'test',
        'approved_status' => 'approved',
        'watermark_detected' => false,
    ]);
}

/** @return list<int> */
function nearbyFeedIds(array $json): array
{
    return array_map(static fn (array $row): int => (int) $row['id'], $json['profiles'] ?? []);
}

test('nearby feed no longer 500s on the location_id parameter', function () {
    $geo = nearbyFeedGeography();
    $home = nearbyFeedTaluka($geo['state'], 'Haveli', 18.51, 73.85);

    $viewer = nearbyFeedProfile('Nearby Viewer', 'male', nearbyFeedVillage($home));
    $target = nearbyFeedProfile('Nearby Target', 'female', nearbyFeedVillage($home));

    Sanctum::actingAs($viewer->user);

    $response = $this->getJson('/api/v1/matrimony-profiles?feed=nearby&location_id='.$home->id.'&per_page=20');

    $response->assertOk();
    expect(nearbyFeedIds($response->json()))->toContain($target->id);
});

test('nearby feed ranks own taluka first, then outward, with no state ceiling', function () {
    $geo = nearbyFeedGeography();
    $home = nearbyFeedTaluka($geo['state'], 'Haveli', 18.51, 73.85);      // origin
    $next = nearbyFeedTaluka($geo['state'], 'Mulshi', 18.53, 73.51);      // ~36 km
    $far = nearbyFeedTaluka($geo['far_state'], 'Daskroi', 23.02, 72.57);  // ~520 km, another state

    $viewer = nearbyFeedProfile('Ordering Viewer', 'male', nearbyFeedVillage($home));
    $farProfile = nearbyFeedProfile('Far Woman', 'female', nearbyFeedVillage($far));
    $nextProfile = nearbyFeedProfile('Next Taluka Woman', 'female', nearbyFeedVillage($next));
    $homeProfile = nearbyFeedProfile('Own Taluka Woman', 'female', nearbyFeedVillage($home));

    Sanctum::actingAs($viewer->user);

    $ids = nearbyFeedIds($this->getJson('/api/v1/matrimony-profiles?feed=nearby')->assertOk()->json());

    expect(array_slice($ids, 0, 3))->toBe([$homeProfile->id, $nextProfile->id, $farProfile->id]);
});

test('nearby feed includes profiles the viewer has already viewed', function () {
    $geo = nearbyFeedGeography();
    $home = nearbyFeedTaluka($geo['state'], 'Haveli', 18.51, 73.85);

    $viewer = nearbyFeedProfile('Seen Viewer', 'male', nearbyFeedVillage($home));
    $seen = nearbyFeedProfile('Already Seen Woman', 'female', nearbyFeedVillage($home));

    ProfileView::query()->create([
        'viewer_profile_id' => $viewer->id,
        'viewed_profile_id' => $seen->id,
    ]);

    Sanctum::actingAs($viewer->user);

    $ids = nearbyFeedIds($this->getJson('/api/v1/matrimony-profiles?feed=nearby')->assertOk()->json());

    expect($ids)->toContain($seen->id);
});

test('nearby feed prefers profiles with an approved photo inside the same taluka', function () {
    $geo = nearbyFeedGeography();
    $home = nearbyFeedTaluka($geo['state'], 'Haveli', 18.51, 73.85);

    $viewer = nearbyFeedProfile('Photo Viewer', 'male', nearbyFeedVillage($home));
    $withoutPhoto = nearbyFeedProfile('No Photo Woman', 'female', nearbyFeedVillage($home));
    $withPhoto = nearbyFeedProfile('Photo Woman', 'female', nearbyFeedVillage($home));
    nearbyFeedApprovePhoto($withPhoto);

    // Make the photo-less profile the newest, so only the photo rule can put the other one first.
    $withoutPhoto->forceFill(['updated_at' => now()->addMinute()])->saveQuietly();

    Sanctum::actingAs($viewer->user);

    $ids = nearbyFeedIds($this->getJson('/api/v1/matrimony-profiles?feed=nearby')->assertOk()->json());

    expect(array_search($withPhoto->id, $ids, true))
        ->toBeLessThan(array_search($withoutPhoto->id, $ids, true));
});

test('nearby feed paginates without repeating rows and reports has_more', function () {
    $geo = nearbyFeedGeography();
    $home = nearbyFeedTaluka($geo['state'], 'Haveli', 18.51, 73.85);

    $viewer = nearbyFeedProfile('Paging Viewer', 'male', nearbyFeedVillage($home));
    for ($i = 0; $i < 5; $i++) {
        nearbyFeedProfile('Paged Woman '.$i, 'female', nearbyFeedVillage($home));
    }

    Sanctum::actingAs($viewer->user);

    $first = $this->getJson('/api/v1/matrimony-profiles?feed=nearby&per_page=2&page=1')->assertOk()->json();
    $second = $this->getJson('/api/v1/matrimony-profiles?feed=nearby&per_page=2&page=2')->assertOk()->json();
    $third = $this->getJson('/api/v1/matrimony-profiles?feed=nearby&per_page=2&page=3')->assertOk()->json();

    expect($first['pagination'])->toMatchArray(['page' => 1, 'per_page' => 2, 'count' => 2, 'has_more' => true]);
    expect($second['pagination'])->toMatchArray(['page' => 2, 'per_page' => 2, 'count' => 2, 'has_more' => true]);
    expect($third['pagination'])->toMatchArray(['page' => 3, 'per_page' => 2, 'count' => 1, 'has_more' => false]);

    $all = array_merge(nearbyFeedIds($first), nearbyFeedIds($second), nearbyFeedIds($third));
    expect($all)->toHaveCount(5)
        ->and(array_unique($all))->toHaveCount(5);
});

test('nearby feed treats a chosen location as the origin, not as a hard filter', function () {
    $geo = nearbyFeedGeography();
    $home = nearbyFeedTaluka($geo['state'], 'Haveli', 18.51, 73.85);
    $far = nearbyFeedTaluka($geo['far_state'], 'Daskroi', 23.02, 72.57);

    $viewer = nearbyFeedProfile('Origin Viewer', 'male', nearbyFeedVillage($home));
    $farProfile = nearbyFeedProfile('Origin Far Woman', 'female', nearbyFeedVillage($far));

    Sanctum::actingAs($viewer->user);

    $ids = nearbyFeedIds(
        $this->getJson('/api/v1/matrimony-profiles?feed=nearby&location_id='.$home->id)->assertOk()->json()
    );

    // Pinning the origin to the home taluka must NOT hide the other state — the tab widens all India.
    expect($ids)->toContain($farProfile->id);
});

test('nearby feed still applies non-geographic filters', function () {
    $geo = nearbyFeedGeography();
    $home = nearbyFeedTaluka($geo['state'], 'Haveli', 18.51, 73.85);

    $viewer = nearbyFeedProfile('Filter Viewer', 'male', nearbyFeedVillage($home));
    $young = nearbyFeedProfile('Young Woman', 'female', nearbyFeedVillage($home));
    $older = nearbyFeedProfile('Older Woman', 'female', nearbyFeedVillage($home));

    $young->forceFill(['date_of_birth' => now()->subYears(24)->format('Y-m-d')])->saveQuietly();
    $older->forceFill(['date_of_birth' => now()->subYears(48)->format('Y-m-d')])->saveQuietly();

    Sanctum::actingAs($viewer->user);

    $ids = nearbyFeedIds(
        $this->getJson('/api/v1/matrimony-profiles?feed=nearby&age_from=20&age_to=30')->assertOk()->json()
    );

    expect($ids)->toContain($young->id)->not->toContain($older->id);
});
