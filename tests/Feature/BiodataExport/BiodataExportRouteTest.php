<?php

namespace Tests\Feature\BiodataExport;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserFeatureUsage;
use App\Services\Profile\ProfileCanonicalResidenceService;
use App\Services\PlanQuotaCheckoutSnapshot;
use App\Support\UserFeatureUsageKeys;
use Database\Seeders\MinimalLocationSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            ->assertSee('PDF')
            ->assertSee('JPG');
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
        $locationId = DB::table('addresses')->where('type', 'city')->value('id');
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
