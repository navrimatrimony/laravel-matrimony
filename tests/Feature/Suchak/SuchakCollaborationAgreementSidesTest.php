<?php

namespace Tests\Feature\Suchak;

use App\Models\City;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakConsent;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The groom/bride side labels on a commission agreement decide which column an acceptance
 * timestamp lands in. The create path derives them from the candidates' genders; the accept path
 * (createMissingAgreement) used to hard-code requesting = groom. These tests pin both paths to the
 * same rule in both gender directions.
 */
class SuchakCollaborationAgreementSidesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_accept_path_labels_sides_like_create_path_when_requesting_candidate_is_female(): void
    {
        [$requestingUser, $requestingAccount, $requestingRepresentation, $targetUser, $targetAccount, $targetRepresentation]
            = $this->genderedCollaborationFixture(requestingGender: 'female', targetGender: 'male');

        $service = app(SuchakCollaborationService::class);

        $collaboration = $service->createRequest(
            $requestingAccount,
            $requestingUser,
            $requestingRepresentation,
            $targetRepresentation,
        )['request'];

        /** @var SuchakCommissionAgreement $createPathAgreement */
        $createPathAgreement = $collaboration->commissionAgreement()->firstOrFail();

        // Create path: the female candidate's Suchak is the bride side, so the requester's own
        // acknowledgement is recorded there.
        $this->assertSame((int) $targetAccount->id, (int) $createPathAgreement->groom_side_suchak_account_id);
        $this->assertSame((int) $requestingAccount->id, (int) $createPathAgreement->bride_side_suchak_account_id);
        $this->assertNotNull($createPathAgreement->accepted_by_bride_suchak_at);
        $this->assertNull($createPathAgreement->accepted_by_groom_suchak_at);

        // Drop the agreement row so acceptRequest() has to rebuild it through createMissingAgreement().
        // Raw delete on purpose: the model blocks deletes, and this reproduces the only state in
        // which the accept path ever creates an agreement.
        $this->forceDeleteAgreement($createPathAgreement);

        $accepted = $service->acceptRequest($collaboration->fresh(), $targetAccount, $targetUser);
        $this->assertSame(SuchakCollaborationRequest::STATUS_ACCEPTED, $accepted->status);

        /** @var SuchakCommissionAgreement $acceptPathAgreement */
        $acceptPathAgreement = SuchakCommissionAgreement::query()
            ->where('collaboration_request_id', $collaboration->id)
            ->firstOrFail();

        $this->assertNotSame((int) $createPathAgreement->id, (int) $acceptPathAgreement->id);

        // Same pair, same genders, so the accept path must label the sides exactly as the create
        // path did.
        $this->assertSame(
            (int) $createPathAgreement->groom_side_suchak_account_id,
            (int) $acceptPathAgreement->groom_side_suchak_account_id,
        );
        $this->assertSame(
            (int) $createPathAgreement->bride_side_suchak_account_id,
            (int) $acceptPathAgreement->bride_side_suchak_account_id,
        );
        $this->assertSame((int) $targetAccount->id, (int) $acceptPathAgreement->groom_side_suchak_account_id);
        $this->assertSame((int) $requestingAccount->id, (int) $acceptPathAgreement->bride_side_suchak_account_id);

        // The accepting Suchak here represents the male candidate, so the acceptance timestamp
        // belongs to the groom side and nowhere else.
        $this->assertNotNull($acceptPathAgreement->accepted_by_groom_suchak_at);
        $this->assertNull($acceptPathAgreement->accepted_by_bride_suchak_at);
    }

    public function test_accept_path_labels_sides_like_create_path_when_requesting_candidate_is_male(): void
    {
        [$requestingUser, $requestingAccount, $requestingRepresentation, $targetUser, $targetAccount, $targetRepresentation]
            = $this->genderedCollaborationFixture(requestingGender: 'male', targetGender: 'female');

        $service = app(SuchakCollaborationService::class);

        $collaboration = $service->createRequest(
            $requestingAccount,
            $requestingUser,
            $requestingRepresentation,
            $targetRepresentation,
        )['request'];

        /** @var SuchakCommissionAgreement $createPathAgreement */
        $createPathAgreement = $collaboration->commissionAgreement()->firstOrFail();

        $this->assertSame((int) $requestingAccount->id, (int) $createPathAgreement->groom_side_suchak_account_id);
        $this->assertSame((int) $targetAccount->id, (int) $createPathAgreement->bride_side_suchak_account_id);
        $this->assertNotNull($createPathAgreement->accepted_by_groom_suchak_at);
        $this->assertNull($createPathAgreement->accepted_by_bride_suchak_at);

        $this->forceDeleteAgreement($createPathAgreement);

        $accepted = $service->acceptRequest($collaboration->fresh(), $targetAccount, $targetUser);
        $this->assertSame(SuchakCollaborationRequest::STATUS_ACCEPTED, $accepted->status);

        /** @var SuchakCommissionAgreement $acceptPathAgreement */
        $acceptPathAgreement = SuchakCommissionAgreement::query()
            ->where('collaboration_request_id', $collaboration->id)
            ->firstOrFail();

        $this->assertSame(
            (int) $createPathAgreement->groom_side_suchak_account_id,
            (int) $acceptPathAgreement->groom_side_suchak_account_id,
        );
        $this->assertSame(
            (int) $createPathAgreement->bride_side_suchak_account_id,
            (int) $acceptPathAgreement->bride_side_suchak_account_id,
        );
        $this->assertSame((int) $requestingAccount->id, (int) $acceptPathAgreement->groom_side_suchak_account_id);
        $this->assertSame((int) $targetAccount->id, (int) $acceptPathAgreement->bride_side_suchak_account_id);

        // The accepting Suchak represents the female candidate here, so the bride column is the
        // only one that may carry the timestamp.
        $this->assertNotNull($acceptPathAgreement->accepted_by_bride_suchak_at);
        $this->assertNull($acceptPathAgreement->accepted_by_groom_suchak_at);
    }

    public function test_repaired_agreement_never_names_an_account_outside_the_collaboration(): void
    {
        // A collaboration whose representation rows point at other Suchak accounts (a factory-built
        // row, or a representation reassigned after the request was raised) must still produce an
        // agreement between the two accounts on the request itself.
        [, $requestingAccount] = $this->verifiedSuchakActor();
        [$targetUser, $targetAccount] = $this->verifiedSuchakActor();

        /** @var SuchakCollaborationRequest $collaboration */
        $collaboration = SuchakCollaborationRequest::factory()->create([
            'requesting_suchak_account_id' => $requestingAccount->id,
            'target_suchak_account_id' => $targetAccount->id,
            'status' => SuchakCollaborationRequest::STATUS_PENDING,
            'requested_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        app(SuchakCollaborationService::class)->acceptRequest($collaboration, $targetAccount, $targetUser);

        /** @var SuchakCommissionAgreement $agreement */
        $agreement = SuchakCommissionAgreement::query()
            ->where('collaboration_request_id', $collaboration->id)
            ->firstOrFail();

        $this->assertContains(
            (int) $agreement->groom_side_suchak_account_id,
            [(int) $requestingAccount->id, (int) $targetAccount->id],
        );
        $this->assertContains(
            (int) $agreement->bride_side_suchak_account_id,
            [(int) $requestingAccount->id, (int) $targetAccount->id],
        );
        $this->assertNotSame(
            (int) $agreement->groom_side_suchak_account_id,
            (int) $agreement->bride_side_suchak_account_id,
        );
    }

    private function forceDeleteAgreement(SuchakCommissionAgreement $agreement): void
    {
        DB::table('suchak_commission_agreements')->where('id', $agreement->id)->delete();
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: SuchakProfileRepresentation, 3: User, 4: SuchakAccount, 5: SuchakProfileRepresentation}
     */
    private function genderedCollaborationFixture(string $requestingGender, string $targetGender): array
    {
        [$requestingUser, $requestingAccount] = $this->verifiedSuchakActor();
        [$targetUser, $targetAccount] = $this->verifiedSuchakActor();

        $requestingProfile = $this->activeProfile([
            'full_name' => 'Sides Requesting Candidate',
            'gender_id' => $this->genderId($requestingGender),
            'date_of_birth' => now()->subYears(30)->toDateString(),
        ]);
        $targetProfile = $this->activeProfile([
            'full_name' => 'Sides Target Candidate',
            'gender_id' => $this->genderId($targetGender),
            'date_of_birth' => now()->subYears(27)->toDateString(),
        ]);

        return [
            $requestingUser,
            $requestingAccount,
            $this->activeRepresentation($requestingAccount, $requestingProfile),
            $targetUser,
            $targetAccount,
            $this->activeRepresentation($targetAccount, $targetProfile),
        ];
    }

    private function genderId(string $key): int
    {
        return (int) MasterGender::query()->firstOrCreate(
            ['key' => $key],
            ['label' => ucfirst($key), 'is_active' => true],
        )->id;
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
            'registration_completed_at' => now(),
        ]);

        return [$user, $account];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeProfile(array $attributes = []): MatrimonyProfile
    {
        $profile = MatrimonyProfile::factory()->create(array_merge([
            'full_name' => 'Sides Candidate',
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

    private function activeRepresentation(SuchakAccount $account, MatrimonyProfile $profile): SuchakProfileRepresentation
    {
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        SuchakConsent::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'consent_status' => SuchakConsent::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'used_at' => now(),
            'otp_verified_at' => now(),
            'valid_from' => now(),
            'valid_until' => $representation->consent_valid_until,
        ]);

        return $representation->fresh(['suchakAccount', 'matrimonyProfile.gender']);
    }
}
