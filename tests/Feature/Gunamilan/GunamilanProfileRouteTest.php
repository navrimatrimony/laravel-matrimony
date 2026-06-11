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
use Illuminate\Support\Facades\Route;
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
        ->assertSee(__('profile.gunamilan_download_pdf'))
        ->assertSee(__('profile.gunamilan_format_summary'))
        ->assertSee(__('profile.gunamilan_format_traditional'))
        ->assertSee('data-gunamilan-format-tabs', false)
        ->assertSee('data-gunamilan-actions', false)
        ->assertSee('value="traditional"', false)
        ->assertSee('checked', false)
        ->assertSee('data-gunamilan-report-preview', false)
        ->assertSee('report_format=traditional&amp;preview=1', false)
        ->assertDontSee(__('profile.gunamilan_format_summary_desc'))
        ->assertDontSee(__('profile.gunamilan_format_traditional_desc'))
        ->assertDontSee(__('profile.gunamilan_focus_varna'))
        ->assertDontSee(__('profile.gunamilan_report_observations'))
        ->assertSee('/ 36', false);

    $this->actingAs($viewerUser)
        ->get(route('matrimony.profile.gunamilan', [$target, 'report_format' => 'summary']))
        ->assertOk()
        ->assertSee('data-gunamilan-report-preview', false)
        ->assertSee('report_format=summary&amp;preview=1', false);
});

test('member can print and download gunamilan a4 pdf report without storing result', function (): void {
    $viewerUser = User::factory()->create();
    $targetUser = User::factory()->create();
    $viewer = gunamilanRouteCreateActiveProfile($viewerUser, 'male');
    $target = gunamilanRouteCreateActiveProfile($targetUser, 'female');
    gunamilanRouteAttachHoroscope($viewer, 'mesha', 'ashwini');
    gunamilanRouteAttachHoroscope($target, 'mesha', 'ashwini');

    $beforeHoroscopeRows = DB::table('profile_horoscope_data')->count();
    $viewerUpdatedAt = $viewer->fresh()->updated_at?->toDateTimeString();
    $targetUpdatedAt = $target->fresh()->updated_at?->toDateTimeString();

    $this->actingAs($viewerUser)
        ->get(route('matrimony.profile.gunamilan.print', $target))
        ->assertOk()
        ->assertSee(__('profile.gunamilan_report_title'))
        ->assertSee(__('profile.gunamilan_report_kicker'))
        ->assertSee(__('profile.gunamilan_score_card_title'))
        ->assertSee(__('profile.gunamilan_dosha_analysis'))
        ->assertSee('ReportDevanagari', false)
        ->assertSee('data:image/png;base64', false)
        ->assertSee('details-panel', false)
        ->assertSee('dosha-panel', false)
        ->assertSee(__('profile.gunamilan_focus_nadi'))
        ->assertSee('brand-footer-outside', false)
        ->assertDontSee('१. ', false)
        ->assertDontSee('२. ', false)
        ->assertDontSee('३. ', false)
        ->assertDontSee('४. ', false)
        ->assertSee(__('profile.gunamilan_report_brand_footer'));

    $this->actingAs($viewerUser)
        ->get(route('matrimony.profile.gunamilan.print', [$target, 'report_format' => 'summary']))
        ->assertOk()
        ->assertSee(__('profile.gunamilan_report_title'))
        ->assertSee(__('profile.gunamilan_report_about_score'))
        ->assertSee('ReportDevanagari', false)
        ->assertSee('brand-footer-outside', false)
        ->assertSee(__('profile.gunamilan_minimum_tip'))
        ->assertSee(__('profile.gunamilan_report_brand_footer'));

    $this->actingAs($viewerUser)
        ->get(route('matrimony.profile.gunamilan.print', [$target, 'preview' => 1]))
        ->assertOk()
        ->assertDontSee('window.print', false);

    $response = $this->actingAs($viewerUser)
        ->get(route('matrimony.profile.gunamilan.pdf', $target))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($response->getContent())->toStartWith('%PDF');

    $summaryResponse = $this->actingAs($viewerUser)
        ->get(route('matrimony.profile.gunamilan.pdf', [$target, 'report_format' => 'summary']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($summaryResponse->getContent())->toStartWith('%PDF');
    expect(DB::table('profile_horoscope_data')->count())->toBe($beforeHoroscopeRows);
    expect($viewer->fresh()->updated_at?->toDateTimeString())->toBe($viewerUpdatedAt);
    expect($target->fresh()->updated_at?->toDateTimeString())->toBe($targetUpdatedAt);
});

test('gunamilan export routes stay under the existing profile access guard', function (): void {
    $user = User::factory()->create();
    $profile = gunamilanRouteCreateActiveProfile($user, 'male');
    gunamilanRouteAttachHoroscope($profile, 'mesha', 'ashwini');

    $this->actingAs($user)
        ->get(route('matrimony.profile.gunamilan.pdf', $profile))
        ->assertRedirect(route('matrimony.profile.show', $profile));

    expect(Route::has('matrimony.profile.gunamilan.jpg'))->toBeTrue();
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
