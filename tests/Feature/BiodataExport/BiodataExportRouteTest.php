<?php

namespace Tests\Feature\BiodataExport;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\ProfileHoroscopeData;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserFeatureUsage;
use App\Services\Profile\ProfileCanonicalResidenceService;
use App\Services\PlanQuotaCheckoutSnapshot;
use App\Support\UserFeatureUsageKeys;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\MasterMotherTongueDietLifestyleSeeder;
use Database\Seeders\MinimalLocationSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BiodataExportRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_open_own_biodata_export_templates(): void
    {
        $user = $this->userWithProfileAndSubscription('basic_male');

        $this->actingAs($user)
            ->get(route('matrimony.profile.biodata.index'))
            ->assertOk()
            ->assertSee('Download Biodata')
            ->assertSee('Classic Portrait')
            ->assertSee('Parichay Patra')
            ->assertSee('Full Photo Side Biodata')
            ->assertSee('PDF')
            ->assertSee('JPG');

        $this->actingAs($user)
            ->get(route('matrimony.profile.biodata.preview', 'classic_portrait_no_photo'))
            ->assertOk()
            ->assertSee('ReportDevanagari', false)
            ->assertSee('data:image/png;base64', false);

        $this->actingAs($user)
            ->get(route('matrimony.profile.biodata.preview', 'parichay_patra_photo'))
            ->assertOk()
            ->assertSee('Parichay Patra')
            ->assertSee('parichay-frame', false)
            ->assertSee('parichay-top-medallion', false)
            ->assertSee('parichay-brand-footer', false);

        $this->actingAs($user)
            ->get(route('matrimony.profile.biodata.preview', 'photo_side_biodata'))
            ->assertOk()
            ->assertSee('Full Photo Side Biodata')
            ->assertSee('photo-side-frame', false)
            ->assertSee('photo-side-photo-cell', false)
            ->assertSee('photo-side-info-cell', false);
    }

    public function test_pdf_download_consumes_monthly_biodata_quota_without_mutating_profile(): void
    {
        $user = $this->userWithProfileAndSubscription('basic_male');
        $profile = $user->matrimonyProfile;
        $profile->update(['updated_at' => now()->subDay()]);
        $updatedAtBefore = (string) $profile->fresh()->updated_at;

        $this->actingAs($user)
            ->get(route('matrimony.profile.biodata.pdf', 'classic_portrait_no_photo'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertDatabaseHas('user_feature_usages', [
            'user_id' => $user->id,
            'feature_key' => UserFeatureUsageKeys::BIODATA_EXPORT_LIMIT,
            'period' => UserFeatureUsage::PERIOD_MONTHLY,
            'used_count' => 1,
        ]);
        $this->assertSame($updatedAtBefore, (string) $profile->fresh()->updated_at);
    }

    public function test_parichay_patra_is_free_and_exports_as_pdf(): void
    {
        $user = $this->userWithProfileAndSubscription('basic_male');
        $profile = $user->matrimonyProfile;
        $profile->update(['updated_at' => now()->subDay()]);
        $updatedAtBefore = (string) $profile->fresh()->updated_at;

        $this->actingAs($user)
            ->get(route('matrimony.profile.biodata.pdf', 'parichay_patra_photo'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertDatabaseHas('user_feature_usages', [
            'user_id' => $user->id,
            'feature_key' => UserFeatureUsageKeys::BIODATA_EXPORT_LIMIT,
            'period' => UserFeatureUsage::PERIOD_MONTHLY,
            'used_count' => 1,
        ]);
        $this->assertSame($updatedAtBefore, (string) $profile->fresh()->updated_at);
    }

    public function test_photo_side_biodata_is_free_and_exports_as_pdf(): void
    {
        $user = $this->userWithProfileAndSubscription('basic_male');
        $profile = $user->matrimonyProfile;
        $profile->update(['updated_at' => now()->subDay()]);
        $updatedAtBefore = (string) $profile->fresh()->updated_at;

        $this->actingAs($user)
            ->get(route('matrimony.profile.biodata.pdf', 'photo_side_biodata'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertDatabaseHas('user_feature_usages', [
            'user_id' => $user->id,
            'feature_key' => UserFeatureUsageKeys::BIODATA_EXPORT_LIMIT,
            'period' => UserFeatureUsage::PERIOD_MONTHLY,
            'used_count' => 1,
        ]);
        $this->assertSame($updatedAtBefore, (string) $profile->fresh()->updated_at);
    }

    public function test_premium_template_requires_premium_plan_feature(): void
    {
        $basic = $this->userWithProfileAndSubscription('basic_male');

        $this->actingAs($basic)
            ->get(route('matrimony.profile.biodata.preview', 'double_portrait_photo'))
            ->assertRedirect(route('matrimony.profile.biodata.index'))
            ->assertSessionHas('error');

        $silver = $this->userWithProfileAndSubscription('silver_male');

        $this->actingAs($silver)
            ->get(route('matrimony.profile.biodata.preview', 'double_portrait_photo'))
            ->assertOk()
            ->assertSee('Double Border Portrait');
    }

    public function test_marathi_biodata_preview_localizes_options_and_hides_non_print_fields(): void
    {
        $user = $this->userWithProfileAndSubscription('basic_male');
        $this->seed(MasterLookupSeeder::class);
        $this->seed(MasterMotherTongueDietLifestyleSeeder::class);

        $profile = $user->matrimonyProfile;
        $profile->forceFill([
            'marital_status_id' => DB::table('master_marital_statuses')->where('key', 'divorced')->value('id'),
            'complexion_id' => DB::table('master_complexions')->where('key', 'very_fair')->value('id'),
            'physical_build_id' => DB::table('master_physical_builds')->where('key', 'slim')->value('id'),
            'blood_group_id' => DB::table('master_blood_groups')->where('key', 'B-')->value('id'),
            'family_type_id' => DB::table('master_family_types')->where('key', 'nuclear')->value('id'),
            'mother_tongue_id' => DB::table('master_mother_tongues')->where('key', 'marathi')->value('id'),
        ])->save();

        DB::table('profile_children')->insert([
            'profile_id' => $profile->id,
            'child_name' => '',
            'gender' => 'male',
            'age' => 10,
            'child_living_with_id' => DB::table('master_child_living_with')->where('key', 'with_parent')->value('id'),
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ProfileHoroscopeData::query()->create([
            'profile_id' => $profile->id,
            'rashi_id' => DB::table('master_rashis')->where('key', 'mithuna')->value('id'),
            'nakshatra_id' => DB::table('master_nakshatras')->where('key', 'punarvasu')->value('id'),
            'gan_id' => DB::table('master_gans')->where('key', 'deva')->value('id'),
            'nadi_id' => DB::table('master_nadis')->where('key', 'adi')->value('id'),
            'yoni_id' => DB::table('master_yonis')->where('key', 'cat')->value('id'),
            'birth_weekday' => 'Saturday',
        ]);

        $this->actingAs($user)
            ->get(route('matrimony.profile.biodata.preview', ['parichay_patra_photo', 'locale' => 'mr']))
            ->assertOk()
            ->assertSee('parichay-top-medallion', false)
            ->assertSee('parichay-brand-footer', false)
            ->assertSee('|| परिचय पत्र ||')
            ->assertSee('श्री गणेशाय नमः')
            ->assertSee('| शुभं भवतु |')
            ->assertSee('हा बायोडाटा https://navrimilenavryala.com मधून तयार केला आहे.')
            ->assertSee('बायोडाटा')
            ->assertSee('वैवाहिक स्थिती')
            ->assertSee('घटस्फोटित')
            ->assertSee('वर्ण')
            ->assertSee('अतिशय गोरी')
            ->assertSee('रक्तगट')
            ->assertSee('बी निगेटिव्ह')
            ->assertSee('मूल')
            ->assertSee('10 वर्ष')
            ->assertSee('मुलगा')
            ->assertSee('माझ्याबरोबर')
            ->assertSee('राशी')
            ->assertSee('मिथुन')
            ->assertSee('नक्षत्र')
            ->assertSee('पुनर्वसू')
            ->assertSee('गण')
            ->assertSee('देव')
            ->assertSee('नाडी')
            ->assertSee('आदि')
            ->assertSee('योनी')
            ->assertSee('मांजर')
            ->assertSee('जन्मवार')
            ->assertSee('शनिवार')
            ->assertDontSee('Gender')
            ->assertDontSee('Mother tongue')
            ->assertDontSee('Physical Build')
            ->assertDontSee('Family Type')
            ->assertDontSee('Child 1')
            ->assertDontSee('Male');
    }

    public function test_exhausted_biodata_quota_blocks_download_without_usage_increment(): void
    {
        $user = $this->userWithProfileAndSubscription('basic_male');

        UserFeatureUsage::query()->create([
            'user_id' => $user->id,
            'feature_key' => UserFeatureUsageKeys::BIODATA_EXPORT_LIMIT,
            'period' => UserFeatureUsage::PERIOD_MONTHLY,
            'used_count' => 5,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
        ]);

        $this->actingAs($user)
            ->get(route('matrimony.profile.biodata.pdf', 'classic_portrait_no_photo'))
            ->assertRedirect(route('matrimony.profile.biodata.index'))
            ->assertSessionHas('error');

        $this->assertSame(5, (int) UserFeatureUsage::query()
            ->where('user_id', $user->id)
            ->where('feature_key', UserFeatureUsageKeys::BIODATA_EXPORT_LIMIT)
            ->where('period', UserFeatureUsage::PERIOD_MONTHLY)
            ->value('used_count'));
    }

    public function test_export_routes_are_own_profile_only_and_have_no_profile_id_parameter(): void
    {
        $user = $this->userWithProfileAndSubscription('basic_male');
        $path = (string) parse_url(route('matrimony.profile.biodata.index'), PHP_URL_PATH);

        $this->assertSame('/matrimony/profile/biodata', $path);
        $this->assertStringNotContainsString('/'.$user->matrimonyProfile->id, $path);
    }

    public function test_mobile_biodata_options_uses_current_self_address_as_residence(): void
    {
        $user = $this->userWithProfileAndSubscription('basic_male');
        $profile = $user->matrimonyProfile;

        if (Schema::hasColumn('matrimony_profiles', 'location_id')) {
            DB::table('matrimony_profiles')->where('id', $profile->id)->update(['location_id' => null]);
        }

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/biodata/export-options');

        $response->assertOk();
        $this->assertNotContains('Current location is missing.', $response->json('warnings') ?? []);
    }

    private function userWithProfileAndSubscription(string $planSlug): User
    {
        $this->seed(MinimalLocationSeeder::class);
        $this->seed(SubscriptionPlansSeeder::class);

        MasterGender::query()->firstOrCreate(['key' => 'male'], ['label' => 'Male', 'is_active' => true]);
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->for($user)->create([
            'full_name' => 'Biodata Export Test',
            'gender_id' => MasterGender::query()->where('key', 'male')->value('id'),
            'lifecycle_state' => 'draft',
            'date_of_birth' => '1996-05-31',
            'highest_education' => 'BA',
            'occupation_title' => 'Engineer',
            'father_name' => 'Test Father',
            'mother_name' => 'Test Mother',
            'property_details' => "Farm land\nHouse",
        ]);
        $locationId = DB::table('addresses')->where('hierarchy', 'village')->where('tag', 'city')->value('id');
        $this->assertNotNull($locationId);
        app(ProfileCanonicalResidenceService::class)->upsertSelfCurrent((int) $profile->id, (int) $locationId, null, true, false);
        $profile->update(['lifecycle_state' => 'active']);

        $contactRow = [
            'profile_id' => $profile->id,
            'contact_name' => 'Biodata Export Test',
            'phone_number' => '9876543210',
            'is_primary' => true,
            'visibility_rule' => 'unlock_only',
            'verified_status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('profile_contacts', 'relation_type')) {
            $contactRow['relation_type'] = 'self';
        }
        if (Schema::hasColumn('profile_contacts', 'contact_relation_id')) {
            $contactRow['contact_relation_id'] = DB::table('master_contact_relations')->where('key', 'self')->value('id');
        }
        DB::table('profile_contacts')->insert($contactRow);

        $plan = Plan::query()->where('slug', $planSlug)->firstOrFail();
        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => Subscription::STATUS_ACTIVE,
            'meta' => [
                'checkout_snapshot' => PlanQuotaCheckoutSnapshot::forPlan($plan),
            ],
        ]);

        return $user->fresh(['matrimonyProfile']);
    }
}
