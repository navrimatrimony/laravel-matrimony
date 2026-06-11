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

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(MasterLookupSeeder::class);
    $this->seed(AshtakootaMasterSeeder::class);
    $this->seed(MinimalLocationSeeder::class);
    ProfileCanonicalResidenceService::forgetCachedMasters();
});

function gunamilanRouteCreateActiveProfile(User $user, string $genderKey): MatrimonyProfile
{
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $user->id,
        'gender_id' => DB::table('master_genders')->where('key', $genderKey)->value('id'),
        'lifecycle_state' => 'draft',
        'is_suspended' => false,
        'visibility_override' => true,
        'card_onboarding_resume_step' => null,
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

function gunamilanRouteAttachHoroscope(MatrimonyProfile $profile, string $rashiKey, string $nakshatraKey): void
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

test('member can view gunamilan result for another visible profile', function (): void {
    $viewerUser = User::factory()->create();
    $targetUser = User::factory()->create();
    $viewer = gunamilanRouteCreateActiveProfile($viewerUser, 'male');
    $target = gunamilanRouteCreateActiveProfile($targetUser, 'female');
    gunamilanRouteAttachHoroscope($viewer, 'mesha', 'ashwini');
    gunamilanRouteAttachHoroscope($target, 'mesha', 'ashwini');

    $this->actingAs($viewerUser)
        ->get(route('matrimony.profile.gunamilan', $target))
        ->assertOk()
        ->assertSee(__('profile.gunamilan_title'))
        ->assertSee(__('profile.gunamilan_section_varna'))
        ->assertSee('/ 36', false);
});

test('gunamilan page shows missing data instead of silently hiding incomplete horoscope', function (): void {
    $viewerUser = User::factory()->create();
    $targetUser = User::factory()->create();
    $viewer = gunamilanRouteCreateActiveProfile($viewerUser, 'male');
    $target = gunamilanRouteCreateActiveProfile($targetUser, 'female');
    gunamilanRouteAttachHoroscope($viewer, 'mesha', 'ashwini');

    $this->actingAs($viewerUser)
        ->get(route('matrimony.profile.gunamilan', $target))
        ->assertOk()
        ->assertSee(__('profile.gunamilan_missing_title'))
        ->assertSee(__('profile.gunamilan_missing_bride_horoscope'));
});

test('member cannot run gunamilan against own profile', function (): void {
    $user = User::factory()->create();
    $profile = gunamilanRouteCreateActiveProfile($user, 'male');
    gunamilanRouteAttachHoroscope($profile, 'mesha', 'ashwini');

    $this->actingAs($user)
        ->get(route('matrimony.profile.gunamilan', $profile))
        ->assertRedirect(route('matrimony.profile.show', $profile));
});
