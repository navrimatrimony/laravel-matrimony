<?php

use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\ProfileHoroscopeData;
use App\Models\User;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\AshtakootaMasterSeeder;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(MasterLookupSeeder::class);
    $this->seed(AshtakootaMasterSeeder::class);
    $this->seed(MinimalLocationSeeder::class);
    ProfileCanonicalResidenceService::forgetCachedMasters();
});

function mobileGunamilanApiCreateActiveProfile(User $user, string $genderKey): MatrimonyProfile
{
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $user->id,
        'gender_id' => DB::table('master_genders')->where('key', $genderKey)->value('id'),
        'lifecycle_state' => 'draft',
        'is_suspended' => false,
        'visibility_override' => true,
        'card_onboarding_resume_step' => null,
        'birth_time' => '10:45',
    ]);

    $leafId = (int) City::query()->where('name', 'Pune City')->firstOrFail()->id;
    if (Schema::hasColumn('matrimony_profiles', 'location_id')) {
        DB::table('matrimony_profiles')->where('id', $profile->id)->update(['location_id' => $leafId]);
        $profile->refresh();
    } else {
        ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $leafId, null, true, false);
    }

    $profile->forceFill(['lifecycle_state' => 'active'])->save();

    return $profile->fresh();
}

function mobileGunamilanApiAttachHoroscope(MatrimonyProfile $profile, string $rashiKey = 'mesha', string $nakshatraKey = 'ashwini'): void
{
    ProfileHoroscopeData::create([
        'profile_id' => $profile->id,
        'rashi_id' => DB::table('master_rashis')->where('key', $rashiKey)->value('id'),
        'nakshatra_id' => DB::table('master_nakshatras')->where('key', $nakshatraKey)->value('id'),
        'gan_id' => DB::table('master_gans')->where('key', 'deva')->value('id'),
        'nadi_id' => DB::table('master_nadis')->where('key', 'adya')->value('id'),
        'yoni_id' => DB::table('master_yonis')->where('key', 'horse')->value('id'),
    ]);
}

test('mobile profile detail gunamilan is protected by sanctum auth', function (): void {
    $target = mobileGunamilanApiCreateActiveProfile(User::factory()->create(), 'female');

    $this->getJson('/api/v1/matrimony-profiles/'.$target->id)
        ->assertUnauthorized();
});

test('mobile profile detail gunamilan uses profile visibility guard', function (): void {
    $viewerUser = User::factory()->create();
    mobileGunamilanApiCreateActiveProfile($viewerUser, 'male');
    $target = mobileGunamilanApiCreateActiveProfile(User::factory()->create(), 'female');
    $target->forceFill(['lifecycle_state' => 'draft'])->save();

    Sanctum::actingAs($viewerUser);

    $this->getJson('/api/v1/matrimony-profiles/'.$target->id)
        ->assertNotFound();
});

test('mobile profile detail gunamilan returns missing data state without birth time leak', function (): void {
    $viewerUser = User::factory()->create();
    mobileGunamilanApiCreateActiveProfile($viewerUser, 'male');
    $target = mobileGunamilanApiCreateActiveProfile(User::factory()->create(), 'female');

    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$target->id)
        ->assertOk()
        ->assertJsonPath('display.gunamilan.available', false)
        ->assertJsonPath('display.gunamilan.status', 'missing_viewer_horoscope');

    expect($response->json('display.gunamilan.birth_time'))->toBeNull();
});

test('mobile profile detail gunamilan returns score and rows for complete horoscope data', function (): void {
    $viewerUser = User::factory()->create();
    $viewer = mobileGunamilanApiCreateActiveProfile($viewerUser, 'male');
    $target = mobileGunamilanApiCreateActiveProfile(User::factory()->create(), 'female');
    mobileGunamilanApiAttachHoroscope($viewer);
    mobileGunamilanApiAttachHoroscope($target);

    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$target->id)
        ->assertOk()
        ->assertJsonPath('display.gunamilan.available', true)
        ->assertJsonPath('display.gunamilan.status', 'available');

    expect($response->json('display.gunamilan.score'))->not->toBeNull();
    expect($response->json('display.gunamilan.rows'))->toHaveCount(8);
    expect($response->json('display.gunamilan.birth_time'))->toBeNull();
});
