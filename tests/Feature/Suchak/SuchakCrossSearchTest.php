<?php

namespace Tests\Feature\Suchak;

use App\Models\City;
use App\Models\Caste;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\SuchakPolicy;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SuchakCrossSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_verified_suchak_can_search_other_profiles_with_masked_output_only(): void
    {
        [$actorUser] = $this->verifiedSuchakActor();
        $targetAccount = $this->publicVerifiedSuchakAccount();
        $profile = $this->activeProfile([
            'full_name' => 'Secret Candidate Alpha',
            'gender_id' => $this->genderId('female'),
            'date_of_birth' => now()->subYears(26)->toDateString(),
            'height_cm' => 166,
            'highest_education' => 'B.Tech Computer',
            'father_name' => 'Father Secret Alpha',
            'mother_name' => 'Mother Secret Alpha',
            'address_line' => 'Secret Lane 42',
        ]);
        $this->insertPrivateContactFixture($profile, '9876543210');
        $this->activeRepresentation($targetAccount, $profile);

        $response = $this->actingAs($actorUser)->get(route('suchak.search.index', [
            'q' => 'B.Tech',
            'age_min' => 25,
            'age_max' => 29,
        ]));

        $response->assertOk();
        $response->assertSee('Find Matches', false);
        $response->assertSee('Female', false);
        $response->assertSee('B.Tech Computer', false);
        $response->assertSee('26 years', false);
        $response->assertSee('5 ft 5 in', false);
        $response->assertDontSee('masked-', false);
        $response->assertDontSee('25-29', false);
        $response->assertDontSee('165-169 cm', false);
        $response->assertSee('Pune City', false);
        $response->assertDontSee('Secret Candidate Alpha', false);
        $response->assertDontSee('9876543210', false);
        $response->assertDontSee('Father Secret Alpha', false);
        $response->assertDontSee('Mother Secret Alpha', false);
        $response->assertDontSee('Secret Lane 42', false);
        $response->assertDontSee('Request Collaboration', false);
        $response->assertDontSee('Download PDF', false);
    }

    public function test_selected_own_representation_drives_fit_explanation_and_modal_request_form(): void
    {
        [$actorUser, $actorAccount] = $this->verifiedSuchakActor();
        $targetAccount = $this->publicVerifiedSuchakAccount();
        [$religion, $caste] = $this->community();

        $ownProfile = $this->activeProfile([
            'full_name' => 'Requester Bride Maya',
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'highest_education' => 'Selected Own MBA',
            'religion_id' => $religion->id,
            'caste_id' => $caste->id,
        ]);
        $targetProfile = $this->activeProfile([
            'full_name' => 'Selected Target Secret',
            'date_of_birth' => now()->subYears(27)->toDateString(),
            'highest_education' => 'Target Public B.Tech',
            'religion_id' => $religion->id,
            'caste_id' => $caste->id,
            'address_line' => 'Selected Target Secret Lane',
        ]);
        $selectedOwnRepresentation = $this->activeRepresentation($actorAccount, $ownProfile);
        $targetRepresentation = $this->activeRepresentation($targetAccount, $targetProfile);

        $response = $this->actingAs($actorUser)->get(route('suchak.search.index', [
            'requesting_representation_id' => $selectedOwnRepresentation->id,
        ]));

        $response->assertOk();
        $response->assertSee('Search your represented profile', false);
        $response->assertSee('Search by name, age, location, education', false);
        $response->assertSee('Requester Bride Maya', false);
        $response->assertSee((string) $selectedOwnRepresentation->id, false);
        $response->assertSee('Strong preliminary fit', false);
        $response->assertSee('Same caste.', false);
        $response->assertSee('Same religion.', false);
        $response->assertSee('Details and request', false);
        $response->assertSee('name="target_representation_id"', false);
        $response->assertSee('name="requesting_representation_id"', false);
        $response->assertSee(route('suchak.collaborations.store'), false);
        $response->assertSee((string) $targetRepresentation->id, false);
        $response->assertDontSee('id="modal_requesting_representation_id"', false);
        $response->assertDontSee('Selected Target Secret', false);
        $response->assertDontSee('Selected Target Secret Lane', false);
    }

    public function test_cross_search_uses_searchable_picker_for_large_own_profile_lists(): void
    {
        [$actorUser, $actorAccount] = $this->verifiedSuchakActor();
        $targetAccount = $this->publicVerifiedSuchakAccount();

        for ($i = 1; $i <= 15; $i++) {
            $profile = $this->activeProfile([
                'full_name' => 'Large Own Candidate '.$i,
                'date_of_birth' => now()->subYears(24 + $i)->toDateString(),
                'highest_education' => 'Large Own Education '.$i,
            ]);
            $this->activeRepresentation($actorAccount, $profile);
        }

        $targetProfile = $this->activeProfile([
            'highest_education' => 'Large List Target Education',
        ]);
        $this->activeRepresentation($targetAccount, $targetProfile);

        $response = $this->actingAs($actorUser)->get(route('suchak.search.index'));

        $response->assertOk();
        $response->assertSee('Search your represented profile', false);
        $response->assertSee('Search by name, age, location, education', false);
        $response->assertSee('Large Own Candidate 15', false);
        $response->assertSee('Large Own Education 15', false);
        $response->assertSee('Showing first 12. Type name, age, location, education, or gender to narrow.', false);
        $response->assertSee('Showing first 12. Type to narrow large profile lists.', false);
        $response->assertDontSee('<select id="requesting_representation_id"', false);
        $response->assertDontSee('<select id="modal_requesting_representation_id"', false);
    }

    public function test_cross_search_shows_photo_by_default_and_honours_photo_visibility_setting(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('matrimony_photos/suchak-search/visible.jpg', 'visible-photo');
        Storage::disk('public')->put('matrimony_photos/suchak-search/hidden.jpg', 'hidden-photo');

        [$actorUser] = $this->verifiedSuchakActor();
        $targetAccount = $this->publicVerifiedSuchakAccount();
        $femaleGenderId = $this->genderId('female');

        $visiblePhotoProfile = $this->activeProfile([
            'full_name' => 'Visible Photo Candidate',
            'gender_id' => $femaleGenderId,
            'highest_education' => 'Visible Photo Education',
            'profile_photo' => 'suchak-search/visible.jpg',
            'photo_approved' => true,
        ]);
        $hiddenPhotoProfile = $this->activeProfile([
            'full_name' => 'Hidden Photo Candidate',
            'gender_id' => $femaleGenderId,
            'highest_education' => 'Hidden Photo Education',
            'profile_photo' => 'suchak-search/hidden.jpg',
            'photo_approved' => true,
        ]);

        $this->activeRepresentation($targetAccount, $visiblePhotoProfile);
        $this->activeRepresentation($targetAccount, $hiddenPhotoProfile);

        DB::table('profile_visibility_settings')
            ->where('profile_id', $hiddenPhotoProfile->id)
            ->update([
                'show_photo_to' => 'accepted_interest',
                'updated_at' => now(),
            ]);

        $response = $this->actingAs($actorUser)->get(route('suchak.search.index'));

        $response->assertOk();
        $response->assertSee('storage/matrimony_photos/suchak-search/visible.jpg', false);
        $response->assertSee('Photo hidden by setting', false);
        $response->assertDontSee('suchak-search/hidden.jpg', false);
        $response->assertDontSee('Visible Photo Candidate', false);
        $response->assertDontSee('Hidden Photo Candidate', false);
    }

    public function test_selected_representation_from_another_suchak_is_not_used_for_fit_explanation(): void
    {
        [$actorUser] = $this->verifiedSuchakActor();
        $otherAccount = $this->publicVerifiedSuchakAccount();
        $targetAccount = $this->publicVerifiedSuchakAccount();
        [$religion, $caste] = $this->community();

        $otherProfile = $this->activeProfile([
            'religion_id' => $religion->id,
            'caste_id' => $caste->id,
        ]);
        $targetProfile = $this->activeProfile([
            'highest_education' => 'Other Suchak Fit Trap',
            'religion_id' => $religion->id,
            'caste_id' => $caste->id,
        ]);
        $otherRepresentation = $this->activeRepresentation($otherAccount, $otherProfile);
        $this->activeRepresentation($targetAccount, $targetProfile);

        $response = $this->actingAs($actorUser)->get(route('suchak.search.index', [
            'requesting_representation_id' => $otherRepresentation->id,
        ]));

        $response->assertOk();
        $response->assertSee('Profile to search for', false);
        $response->assertSee('Type to find your side profile. Search results and fit signals use this selection.', false);
        $response->assertSee('Select your side profile', false);
        $response->assertSee('Select your represented profile above to compare fit signals.', false);
        $response->assertDontSee('Strong preliminary fit', false);
        $response->assertDontSee('Same caste.', false);
        $response->assertDontSee('Same religion.', false);
    }

    public function test_cross_search_excludes_profiles_represented_by_the_same_suchak(): void
    {
        [$actorUser, $actorAccount] = $this->verifiedSuchakActor();
        $otherAccount = $this->publicVerifiedSuchakAccount();

        $ownProfile = $this->activeProfile([
            'highest_education' => 'Own Hidden MCA',
        ]);
        $otherProfile = $this->activeProfile([
            'highest_education' => 'Visible B.Pharm',
        ]);

        $this->activeRepresentation($actorAccount, $ownProfile);
        $this->activeRepresentation($otherAccount, $otherProfile);

        $response = $this->actingAs($actorUser)->get(route('suchak.search.index'));

        $response->assertOk();
        $response->assertSee('Showing 1-1 of 1 profiles', false);
        $response->assertSee('Visible B.Pharm', false);
    }

    public function test_cross_search_hides_invalid_revoked_expired_or_non_public_representations(): void
    {
        [$actorUser] = $this->verifiedSuchakActor();
        $publicAccount = $this->publicVerifiedSuchakAccount();
        $hiddenAccount = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'verified_at' => now(),
        ]);

        $revokedProfile = $this->activeProfile(['highest_education' => 'Revoked Education']);
        $expiredProfile = $this->activeProfile(['highest_education' => 'Expired Education']);
        $hiddenProfile = $this->activeProfile(['highest_education' => 'Hidden Account Education']);

        $this->activeRepresentation($publicAccount, $revokedProfile, [
            'representation_status' => SuchakProfileRepresentation::STATUS_REVOKED,
            'consent_status' => SuchakProfileRepresentation::CONSENT_REVOKED,
            'revoked_at' => now(),
        ]);
        $this->activeRepresentation($publicAccount, $expiredProfile, [
            'consent_valid_until' => now()->subDay(),
        ]);
        $this->activeRepresentation($hiddenAccount, $hiddenProfile);

        $response = $this->actingAs($actorUser)->get(route('suchak.search.index'));

        $response->assertOk();
        $response->assertSee('No profiles matched the current filters.', false);
        $response->assertDontSee('Revoked Education', false);
        $response->assertDontSee('Expired Education', false);
        $response->assertDontSee('Hidden Account Education', false);
    }

    public function test_pending_suchak_account_can_use_cross_search_when_work_access_is_enabled(): void
    {
        $user = User::factory()->create();
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
        ]);

        $this
            ->actingAs($user)
            ->get(route('suchak.search.index'))
            ->assertOk()
            ->assertSee('Find Matches', false);

        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_ALLOW_WORK_BEFORE_ADMIN_APPROVAL],
            [
                'policy_value' => 'false',
                'value_type' => SuchakPolicy::TYPE_BOOLEAN,
                'description' => 'Block pending review Suchak work in this test.',
                'is_active' => true,
            ],
        );

        $this
            ->actingAs($user)
            ->get(route('suchak.search.index'))
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: SuchakAccount}
     */
    private function verifiedSuchakActor(): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        return [$user, $account];
    }

    private function publicVerifiedSuchakAccount(): SuchakAccount
    {
        return SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);
    }

    /**
     * @return array{0: Religion, 1: Caste}
     */
    private function community(): array
    {
        $religion = Religion::query()->create([
            'key' => 'cross_search_religion_'.Religion::query()->count(),
            'label' => 'Cross Search Religion',
            'label_en' => 'Cross Search Religion',
            'is_active' => true,
        ]);
        $caste = Caste::query()->create([
            'religion_id' => $religion->id,
            'key' => 'cross_search_caste_'.Caste::query()->count(),
            'label' => 'Cross Search Caste',
            'label_en' => 'Cross Search Caste',
            'is_active' => true,
        ]);

        return [$religion, $caste];
    }

    private function genderId(string $key): int
    {
        return (int) MasterGender::query()->firstOrCreate(
            ['key' => $key],
            [
                'label' => ucfirst($key),
                'is_active' => true,
            ],
        )->id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeProfile(array $attributes = []): MatrimonyProfile
    {
        $profile = MatrimonyProfile::factory()->create(array_merge([
            'full_name' => 'Private Candidate',
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'height_cm' => 164,
            'highest_education' => 'Generic Education',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], $attributes, [
            'lifecycle_state' => 'draft',
        ]));

        $leafId = (int) City::query()->where('name', 'Pune City')->firstOrFail()->id;

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $leafId]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $leafId, null, true, false);
        }

        $profile->update([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        return $profile->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function activeRepresentation(
        SuchakAccount $account,
        MatrimonyProfile $profile,
        array $overrides = [],
    ): SuchakProfileRepresentation {
        $validUntil = $overrides['consent_valid_until'] ?? now()->addYear();

        $representation = SuchakProfileRepresentation::factory()->create(array_merge([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => $validUntil,
        ], $overrides));

        SuchakConsent::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'consent_status' => $representation->consent_status === SuchakProfileRepresentation::CONSENT_ACCEPTED
                ? SuchakConsent::STATUS_ACCEPTED
                : SuchakConsent::STATUS_REVOKED,
            'accepted_at' => $representation->consent_status === SuchakProfileRepresentation::CONSENT_ACCEPTED ? now() : null,
            'revoked_at' => $representation->consent_status === SuchakProfileRepresentation::CONSENT_REVOKED ? now() : null,
            'used_at' => now(),
            'otp_verified_at' => now(),
            'valid_from' => now(),
            'valid_until' => $representation->consent_valid_until,
        ]);

        return $representation;
    }

    private function insertPrivateContactFixture(MatrimonyProfile $profile, string $phone): void
    {
        $contactRow = [
            'profile_id' => $profile->id,
            'contact_name' => 'Private Candidate Contact',
            'phone_number' => $phone,
            'is_primary' => true,
            'visibility_rule' => 'unlock_only',
            'verified_status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('profile_contacts', 'contact_relation_id')) {
            $contactRow['contact_relation_id'] = null;
        }

        if (Schema::hasColumn('profile_contacts', 'relation_type')) {
            $contactRow['relation_type'] = 'self';
        }

        DB::table('profile_contacts')->insert($contactRow);
    }
}
