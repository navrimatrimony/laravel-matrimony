<?php

namespace Tests\Feature\Suchak;

use App\Models\Caste;
use App\Models\City;
use App\Models\District;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\SuchakPipelineEvent;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\SuchakServicePackage;
use App\Models\SuchakServicePackageDeliverable;
use App\Models\SuchakServicePackageStage;
use App\Models\Taluka;
use App\Models\User;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SuchakPublicMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_public_marketplace_lists_only_verified_public_suchaks_with_factual_cards(): void
    {
        [$account, $representation, $profile, $religion, $caste] = $this->publicSuchakFixture();
        $hiddenAccount = SuchakAccount::factory()->create([
            'suchak_name' => 'Hidden Suchak',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'verified_at' => now(),
        ]);
        $suspendedAccount = SuchakAccount::factory()->create([
            'suchak_name' => 'Suspended Suchak',
            'verification_status' => SuchakAccount::VERIFICATION_SUSPENDED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'suspended_at' => now(),
        ]);
        $this->createPublishedPackage($hiddenAccount, 'Hidden Coordination');
        $this->createPublishedPackage($suspendedAccount, 'Suspended Coordination');

        $response = $this->get(route('suchak.marketplace.index', [
            'district_id' => $account->district_id,
            'taluka_id' => $account->taluka_id,
            'religion_id' => $religion->id,
            'caste_id' => $caste->id,
            'service' => 'Coordination',
        ]));

        $response->assertOk();
        $response->assertSee('Public Suchak Marketplace', false);
        $response->assertSee('Verified Suchak', false);
        $response->assertSee($account->suchak_name, false);
        $response->assertSee('Family Coordination', false);
        $response->assertSee('INR 12,000', false);
        $response->assertSee(route('suchak.marketplace.show', $account), false);
        $response->assertDontSee($hiddenAccount->suchak_name, false);
        $response->assertDontSee($suspendedAccount->suchak_name, false);
        $response->assertDontSee($account->mobile_number, false);
        $response->assertDontSee($account->email, false);
        $response->assertDontSee('upi', false);
        $response->assertDontSee('top rated', false);
        $response->assertDontSee('success rate', false);
        $response->assertDontSee('guaranteed match', false);
        $this->assertSame($profile->id, $representation->matrimony_profile_id);
    }

    public function test_public_suchak_profile_shows_masked_profiles_and_uses_platform_request_route(): void
    {
        [$account, $representation, $targetProfile] = $this->publicSuchakFixture();
        $viewer = User::factory()->create();
        $viewerProfile = $this->activeProfile([
            'user_id' => $viewer->id,
            'full_name' => 'Requesting Member',
        ]);

        $response = $this->actingAs($viewer)->get(route('suchak.marketplace.show', $account));

        $response->assertOk();
        $response->assertSee($account->suchak_name, false);
        $response->assertSee('Verified Suchak', false);
        $response->assertSee('Masked candidate profile', false);
        $response->assertSee('Request through platform', false);
        $response->assertSee(route('matrimony.profile.suchak-requests.store', [$targetProfile, $representation]), false);
        $response->assertDontSee($targetProfile->full_name, false);
        $response->assertDontSee($account->mobile_number, false);
        $response->assertDontSee($account->email, false);

        $post = $this->actingAs($viewer)->post(
            route('matrimony.profile.suchak-requests.store', [$targetProfile, $representation]),
            ['message' => 'Please handle this request inside platform records.'],
        );

        $post->assertRedirect();

        $request = SuchakProfileRequest::query()->firstOrFail();
        $this->assertSame($viewer->id, $request->requesting_user_id);
        $this->assertSame($viewerProfile->id, $request->requesting_matrimony_profile_id);
        $this->assertSame($targetProfile->id, $request->target_matrimony_profile_id);
        $this->assertSame($account->id, $request->selected_suchak_account_id);
        $this->assertDatabaseHas('suchak_pipeline_events', [
            'event_type' => SuchakPipelineEvent::EVENT_REQUEST_CREATED,
            'actor_type' => SuchakPipelineEvent::ACTOR_USER,
            'actor_id' => $viewer->id,
        ]);
    }

    public function test_public_marketplace_hides_claim_risky_service_cards_and_non_public_profiles(): void
    {
        [$account, $representation] = $this->publicSuchakFixture();
        $this->createPublishedPackage($account, 'Best 100 percent guaranteed match service');
        $representation->forceFill([
            'representation_status' => SuchakProfileRepresentation::STATUS_REVOKED,
            'consent_status' => SuchakProfileRepresentation::CONSENT_REVOKED,
            'revoked_at' => now(),
        ])->save();

        $response = $this->get(route('suchak.marketplace.show', $account));

        $response->assertOk();
        $response->assertDontSee('Best 100 percent guaranteed match service', false);
        $response->assertDontSee('top rated', false);
        $response->assertDontSee('success rate', false);
        $response->assertDontSee('guaranteed match', false);
        $response->assertDontSee(route('matrimony.profile.suchak-requests.store', [$representation->matrimonyProfile, $representation]), false);
    }

    /**
     * @return array{0: SuchakAccount, 1: SuchakProfileRepresentation, 2: MatrimonyProfile, 3: Religion, 4: Caste}
     */
    private function publicSuchakFixture(): array
    {
        $district = District::query()->where('name', 'Pune')->firstOrFail();
        $taluka = Taluka::query()->where('name', 'Haveli')->firstOrFail();
        $city = City::query()->where('name', 'Pune City')->firstOrFail();
        $religion = Religion::query()->create([
            'key' => 'day47_religion',
            'label' => 'Day47 Religion',
            'label_en' => 'Day47 Religion',
            'is_active' => true,
        ]);
        $caste = Caste::query()->create([
            'religion_id' => $religion->id,
            'key' => 'day47_caste',
            'label' => 'Day47 Caste',
            'label_en' => 'Day47 Caste',
            'is_active' => true,
        ]);
        $suchakUser = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'suchak_name' => 'Day47 Verified Suchak',
            'office_name' => 'Day47 Family Desk',
            'mobile_number' => '9876543210',
            'email' => 'day47-suchak@example.test',
            'city_id' => $city->id,
            'taluka_id' => $taluka->id,
            'district_id' => $district->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);
        $targetProfile = $this->activeProfile([
            'full_name' => 'Private Candidate Day47',
            'religion_id' => $religion->id,
            'caste_id' => $caste->id,
        ]);
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $targetProfile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);
        SuchakConsent::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $targetProfile->id,
            'representation_id' => $representation->id,
            'consent_status' => SuchakConsent::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'used_at' => now(),
            'otp_verified_at' => now(),
            'valid_from' => now(),
            'valid_until' => $representation->consent_valid_until,
        ]);

        $this->createPublishedPackage($account, 'Family Coordination');

        return [$account, $representation, $targetProfile, $religion, $caste];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeProfile(array $attributes): MatrimonyProfile
    {
        $city = City::query()->where('name', 'Pune City')->firstOrFail();
        $profile = MatrimonyProfile::factory()->create(array_merge([
            'date_of_birth' => now()->subYears(29)->toDateString(),
            'highest_education' => 'Graduate',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], $attributes));

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $city->id]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, (int) $city->id, null, true, false);
        }

        $profile->forceFill([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ])->save();

        return $profile->fresh();
    }

    private function createPublishedPackage(SuchakAccount $account, string $name): SuchakServicePackage
    {
        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'package_name' => $name,
            'package_description' => 'Structured family meeting and document coordination.',
            'price_amount' => '12000',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $account->user_id,
            'published_at' => now(),
        ]);

        $stage = SuchakServicePackageStage::query()->create([
            'service_package_id' => $package->id,
            'stage_key' => 'family_coordination',
            'stage_name' => 'Family coordination',
            'stage_description' => 'Coordinate family discussion and next steps.',
            'sort_order' => 10,
            'is_required' => true,
            'expected_days' => 7,
        ]);

        SuchakServicePackageDeliverable::query()->create([
            'service_package_id' => $package->id,
            'service_package_stage_id' => $stage->id,
            'deliverable_key' => 'meeting_note',
            'deliverable_name' => 'Meeting note',
            'deliverable_description' => 'Structured meeting summary.',
            'sort_order' => 10,
            'is_required' => true,
        ]);

        return $package->fresh(['stages', 'deliverables']);
    }
}
