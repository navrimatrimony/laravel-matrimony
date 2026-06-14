<?php

namespace Tests\Feature\Suchak;

use App\Models\City;
use App\Models\MatrimonyProfile;
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
        $response->assertSee('Suchak masked search', false);
        $response->assertSee('Masked candidate', false);
        $response->assertSee('B.Tech Computer', false);
        $response->assertSee('25-29', false);
        $response->assertSee('165-169 cm', false);
        $response->assertSee('Pune City', false);
        $response->assertDontSee('Secret Candidate Alpha', false);
        $response->assertDontSee('9876543210', false);
        $response->assertDontSee('Father Secret Alpha', false);
        $response->assertDontSee('Mother Secret Alpha', false);
        $response->assertDontSee('Secret Lane 42', false);
        $response->assertDontSee('Request Collaboration', false);
        $response->assertDontSee('Download PDF', false);
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
        $response->assertSee('Visible B.Pharm', false);
        $response->assertDontSee('Own Hidden MCA', false);
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
        $response->assertSee('No masked profiles matched the current filters.', false);
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
            ->assertSee('Suchak masked search', false);

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
