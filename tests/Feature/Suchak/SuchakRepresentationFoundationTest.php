<?php

namespace Tests\Feature\Suchak;

use App\Models\BiodataIntake;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCandidateMaskingService;
use App\Modules\Suchak\Services\SuchakRepresentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakRepresentationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_profile_representations_table_exists_with_day_6_columns(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_profile_representations'));

        foreach ([
            'suchak_account_id',
            'matrimony_profile_id',
            'biodata_intake_id',
            'representation_status',
            'representation_mode',
            'consent_status',
            'first_uploaded_at',
            'first_identified_at',
            'first_verified_consent_at',
            'consent_verified_at',
            'consent_valid_until',
            'revoked_at',
            'candidate_deactivated_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_profile_representations', $column), $column);
        }

        $this->assertFalse(Schema::hasColumn('suchak_profile_representations', 'profile_id'));
        $this->assertFalse(Schema::hasColumn('suchak_profile_representations', 'deleted_at'));
    }

    public function test_verified_suchak_can_create_pending_representation_from_linked_source(): void
    {
        [$user, $account, $profile, $sourceLink] = $this->linkedSourceFixture();

        $representation = app(SuchakRepresentationService::class)->createPendingFromSourceLink(
            $account,
            $user,
            $sourceLink,
            $profile,
            SuchakProfileRepresentation::MODE_MATCHED_EXISTING_PROFILE,
            '127.0.0.1',
            'Day-6 feature test',
        );

        $this->assertDatabaseHas('suchak_profile_representations', [
            'id' => $representation->id,
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'biodata_intake_id' => $sourceLink->biodata_intake_id,
            'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
            'representation_mode' => SuchakProfileRepresentation::MODE_MATCHED_EXISTING_PROFILE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
        ]);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $user->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_REPRESENTATION_CREATED,
            'target_type' => 'suchak_profile_representation',
            'target_id' => $representation->id,
            'matrimony_profile_id' => $profile->id,
        ]);

        $this->assertFalse($representation->isPubliclyVisible());
    }

    public function test_same_profile_can_have_many_suchak_representations_but_not_duplicate_for_same_suchak(): void
    {
        [$firstUser, $firstAccount, $profile, $firstSourceLink] = $this->linkedSourceFixture();
        [$secondUser, $secondAccount, $secondSourceLink] = $this->linkedSourceForProfile($profile);

        $service = app(SuchakRepresentationService::class);
        $service->createPendingFromSourceLink($firstAccount, $firstUser, $firstSourceLink, $profile);
        $service->createPendingFromSourceLink($secondAccount, $secondUser, $secondSourceLink, $profile);

        $this->assertSame(2, SuchakProfileRepresentation::query()
            ->where('matrimony_profile_id', $profile->id)
            ->count());

        $this->expectException(InvalidArgumentException::class);

        $service->createPendingFromSourceLink($firstAccount, $firstUser, $firstSourceLink, $profile);
    }

    public function test_non_verified_suchak_statuses_cannot_create_representation(): void
    {
        foreach ([
            SuchakAccount::VERIFICATION_PENDING,
            SuchakAccount::VERIFICATION_REJECTED,
            SuchakAccount::VERIFICATION_SUSPENDED,
            SuchakAccount::VERIFICATION_ARCHIVED,
        ] as $status) {
            [$user, $account, $profile, $sourceLink] = $this->linkedSourceFixture([
                'verification_status' => $status,
                'verified_at' => null,
            ]);

            try {
                app(SuchakRepresentationService::class)->createPendingFromSourceLink($account, $user, $sourceLink, $profile);

                $this->fail("Suchak status [{$status}] should not create a representation.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Only verified Suchak accounts can create profile representations.', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('suchak_profile_representations', 0);
    }

    public function test_source_link_must_already_reference_requested_canonical_profile(): void
    {
        [$user, $account, $profile, $sourceLink] = $this->linkedSourceFixture();
        $otherProfile = MatrimonyProfile::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(SuchakRepresentationService::class)->createPendingFromSourceLink($account, $user, $sourceLink, $otherProfile);
    }

    public function test_source_link_without_canonical_profile_cannot_create_representation(): void
    {
        [$user, $account, $profile, $sourceLink] = $this->linkedSourceFixture();

        SuchakBiodataIntakeLink::query()
            ->whereKey($sourceLink->id)
            ->update(['matrimony_profile_id' => null]);

        $this->expectException(InvalidArgumentException::class);

        app(SuchakRepresentationService::class)->createPendingFromSourceLink($account, $user, $sourceLink->fresh(), $profile);
    }

    public function test_masked_summary_does_not_leak_private_candidate_data(): void
    {
        [$user, $account, $profile, $sourceLink] = $this->linkedSourceFixture();

        $contactRow = [
            'profile_id' => $profile->id,
            'contact_name' => 'Sensitive Candidate',
            'phone_number' => '9876543210',
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

        $representation = app(SuchakRepresentationService::class)
            ->createPendingFromSourceLink($account, $user, $sourceLink, $profile);

        $payload = app(SuchakCandidateMaskingService::class)->maskedSummary($profile->fresh(), $representation);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Sensitive Candidate', $encoded);
        $this->assertStringNotContainsString('9876543210', $encoded);
        $this->assertStringNotContainsString((string) $user->email, $encoded);
        $this->assertStringNotContainsString('1997-05-15', $encoded);
        $this->assertStringNotContainsString((string) $profile->id, $payload['candidate_reference']);
        $this->assertFalse($payload['visibility']['is_public_user_visible']);
        $this->assertFalse($payload['visibility']['contact_reveal_allowed']);
        $this->assertTrue($payload['contact']['is_masked']);
    }

    public function test_only_active_representation_with_valid_consent_and_public_suchak_is_publicly_visible(): void
    {
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        $visible = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'consent_valid_until' => now()->addYear(),
        ]);

        $pending = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
            'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
        ]);

        $this->assertTrue($visible->isPubliclyVisible());
        $this->assertFalse($pending->isPubliclyVisible());
    }

    public function test_suchak_profile_representations_cannot_be_deleted(): void
    {
        $representation = SuchakProfileRepresentation::factory()->create();

        $this->expectException(RuntimeException::class);

        $representation->delete();
    }

    /**
     * @param  array<string, mixed>  $accountOverrides
     * @return array{0: User, 1: SuchakAccount, 2: MatrimonyProfile, 3: SuchakBiodataIntakeLink}
     */
    private function linkedSourceFixture(array $accountOverrides = []): array
    {
        $user = User::factory()->create();

        $account = SuchakAccount::factory()->create(array_merge([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'verified_at' => now(),
        ], $accountOverrides));

        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => 'Sensitive Candidate',
            'date_of_birth' => '1997-05-15',
            'father_contact_1' => '8888888888',
            'mother_contact_1' => '7777777777',
        ]);

        $sourceLink = $this->createLinkedSource($user, $account, $profile);

        return [$user, $account, $profile, $sourceLink];
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: SuchakBiodataIntakeLink}
     */
    private function linkedSourceForProfile(MatrimonyProfile $profile): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'verified_at' => now(),
        ]);

        return [$user, $account, $this->createLinkedSource($user, $account, $profile)];
    }

    private function createLinkedSource(
        User $user,
        SuchakAccount $account,
        MatrimonyProfile $profile,
    ): SuchakBiodataIntakeLink {
        $intake = BiodataIntake::query()->create([
            'uploaded_by' => $user->id,
            'raw_ocr_text' => 'Day-6 Suchak representation fixture',
            'intake_status' => 'uploaded',
            'parse_status' => 'pending',
            'approved_by_user' => false,
            'intake_locked' => false,
            'snapshot_schema_version' => 1,
        ]);

        return SuchakBiodataIntakeLink::query()->create([
            'suchak_account_id' => $account->id,
            'biodata_intake_id' => $intake->id,
            'matrimony_profile_id' => $profile->id,
            'source_status' => SuchakBiodataIntakeLink::STATUS_LINKED_TO_EXISTING_PROFILE,
            'created_by_user_id' => $user->id,
        ]);
    }
}
