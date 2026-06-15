<?php

namespace Tests\Feature\Suchak;

use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
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
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakCollaborationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_suchak_collaboration_tables_exist_with_day_12_columns(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_collaboration_requests'));
        $this->assertTrue(Schema::hasTable('suchak_commission_agreements'));

        foreach ([
            'requesting_suchak_account_id',
            'target_suchak_account_id',
            'requesting_matrimony_profile_id',
            'target_matrimony_profile_id',
            'requesting_representation_id',
            'target_representation_id',
            'status',
            'message',
            'requested_at',
            'responded_at',
            'expires_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_collaboration_requests', $column), $column);
        }

        foreach ([
            'collaboration_request_id',
            'groom_side_suchak_account_id',
            'bride_side_suchak_account_id',
            'agreement_type',
            'split_type',
            'groom_side_share',
            'bride_side_share',
            'fixed_amount',
            'currency',
            'agreement_text_snapshot',
            'accepted_by_groom_suchak_at',
            'accepted_by_bride_suchak_at',
            'agreement_status',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_commission_agreements', $column), $column);
        }

        $this->assertFalse(Schema::hasColumn('suchak_collaboration_requests', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('suchak_commission_agreements', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('suchak_collaboration_requests', 'phone_number'));
        $this->assertFalse(Schema::hasColumn('suchak_commission_agreements', 'payment_status'));
    }

    public function test_requesting_suchak_can_create_collaboration_with_commission_acknowledgement(): void
    {
        [$requestingUser, $requestingAccount, $requestingRepresentation, $targetAccount, $targetRepresentation] = $this->validCollaborationFixture();
        $this->insertPrivateContactFixture($targetRepresentation->matrimonyProfile);

        $response = $this->actingAs($requestingUser)->post(route('suchak.collaborations.store'), [
            'requesting_representation_id' => $requestingRepresentation->id,
            'target_representation_id' => $targetRepresentation->id,
            'message' => 'Please check this match.',
            'commission_ack' => '1',
        ]);

        $response
            ->assertRedirect()
            ->assertSessionHas('success', 'Collaboration request sent. Track it in Outgoing pending; the target Suchak will see it in Incoming pending.');

        /** @var SuchakCollaborationRequest $collaboration */
        $collaboration = SuchakCollaborationRequest::query()->firstOrFail();
        $this->assertSame($requestingAccount->id, $collaboration->requesting_suchak_account_id);
        $this->assertSame($targetAccount->id, $collaboration->target_suchak_account_id);
        $this->assertSame($requestingRepresentation->matrimony_profile_id, $collaboration->requesting_matrimony_profile_id);
        $this->assertSame($targetRepresentation->matrimony_profile_id, $collaboration->target_matrimony_profile_id);
        $this->assertSame(SuchakCollaborationRequest::STATUS_PENDING, $collaboration->status);
        $this->assertTrue($collaboration->expires_at->greaterThan(now()->addDays(6)));
        $this->assertTrue($collaboration->expires_at->lessThan(now()->addDays(8)));

        /** @var SuchakCommissionAgreement $agreement */
        $agreement = $collaboration->commissionAgreement()->firstOrFail();
        $this->assertSame(SuchakCommissionAgreement::TYPE_COLLABORATION_ACK, $agreement->agreement_type);
        $this->assertSame(SuchakCommissionAgreement::SPLIT_TO_BE_DISCUSSED, $agreement->split_type);
        $this->assertSame('INR', $agreement->currency);
        $this->assertSame(SuchakCommissionAgreement::MVP_ACK_TEXT, $agreement->agreement_text_snapshot);
        $this->assertSame(SuchakCommissionAgreement::STATUS_PENDING, $agreement->agreement_status);
        $this->assertTrue($agreement->accepted_by_groom_suchak_at !== null || $agreement->accepted_by_bride_suchak_at !== null);
        $this->assertFalse(app(SuchakCollaborationService::class)->canExchangeContact($collaboration->fresh()));

        /** @var SuchakActivityLog $activity */
        $activity = SuchakActivityLog::query()
            ->where('action_type', SuchakActivityLog::ACTION_COLLABORATION_REQUEST_CREATED)
            ->where('target_id', $collaboration->id)
            ->firstOrFail();

        $encodedActivity = json_encode($activity->metadata_json, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Sensitive Target Candidate', $encodedActivity);
        $this->assertStringNotContainsString('9876543210', $encodedActivity);
        $this->assertStringNotContainsString('Target Secret Lane', $encodedActivity);
    }

    public function test_target_suchak_acceptance_unlocks_contact_exchange_gate_only_after_both_acknowledge(): void
    {
        [$requestingUser, $requestingAccount, $requestingRepresentation, $targetAccount, $targetRepresentation] = $this->validCollaborationFixture();

        $created = app(SuchakCollaborationService::class)->createRequest(
            $requestingAccount,
            $requestingUser,
            $requestingRepresentation,
            $targetRepresentation,
        );
        $collaboration = $created['request'];
        $this->assertFalse(app(SuchakCollaborationService::class)->canExchangeContact($collaboration));

        $response = $this->actingAs($targetAccount->user)->post(route('suchak.collaborations.accept', $collaboration));
        $response
            ->assertRedirect()
            ->assertSessionHas('success', 'Collaboration request accepted.');

        $accepted = $collaboration->fresh(['commissionAgreement']);
        $this->assertSame(SuchakCollaborationRequest::STATUS_ACCEPTED, $accepted->status);
        $this->assertNotNull($accepted->responded_at);
        $this->assertSame(SuchakCommissionAgreement::STATUS_ACCEPTED, $accepted->commissionAgreement->agreement_status);
        $this->assertNotNull($accepted->commissionAgreement->accepted_by_groom_suchak_at);
        $this->assertNotNull($accepted->commissionAgreement->accepted_by_bride_suchak_at);
        $this->assertTrue(app(SuchakCollaborationService::class)->canExchangeContact($accepted));

        $this->assertDatabaseHas('suchak_activity_logs', [
            'action_type' => SuchakActivityLog::ACTION_COLLABORATION_REQUEST_ACCEPTED,
            'target_type' => 'suchak_collaboration_request',
            'target_id' => $collaboration->id,
        ]);
    }

    public function test_collaboration_center_direction_filters_are_participant_scoped(): void
    {
        [$requestingUser, $requestingAccount, $requestingRepresentation, $targetAccount, $targetRepresentation] = $this->validCollaborationFixture();

        app(SuchakCollaborationService::class)->createRequest(
            $requestingAccount,
            $requestingUser,
            $requestingRepresentation,
            $targetRepresentation,
            ['message' => 'Direction filter check.'],
        );

        $this->actingAs($targetAccount->user)
            ->get(route('suchak.collaborations.index', [
                'direction' => 'incoming',
                'status' => SuchakCollaborationRequest::STATUS_PENDING,
            ]))
            ->assertOk()
            ->assertSee('Incoming request from', false)
            ->assertSee('You received this request from', false)
            ->assertSee('Incoming pending list', false)
            ->assertSee('Direction filter check.', false)
            ->assertDontSee('Sensitive Requesting Candidate', false);

        $this->actingAs($requestingUser)
            ->get(route('suchak.collaborations.index', [
                'direction' => 'incoming',
                'status' => SuchakCollaborationRequest::STATUS_PENDING,
            ]))
            ->assertOk()
            ->assertSee('No collaboration requests found.', false);

        $this->actingAs($requestingUser)
            ->get(route('suchak.collaborations.index', [
                'direction' => 'outgoing',
                'status' => SuchakCollaborationRequest::STATUS_PENDING,
            ]))
            ->assertOk()
            ->assertSee('Outgoing request to', false)
            ->assertSee('You sent this request to', false)
            ->assertSee('Outgoing pending list', false)
            ->assertSee('Direction filter check.', false);
    }

    public function test_target_suchak_rejection_closes_request_without_contact_exchange(): void
    {
        [$requestingUser, $requestingAccount, $requestingRepresentation, $targetAccount, $targetRepresentation] = $this->validCollaborationFixture();

        $collaboration = app(SuchakCollaborationService::class)->createRequest(
            $requestingAccount,
            $requestingUser,
            $requestingRepresentation,
            $targetRepresentation,
        )['request'];

        $response = $this->actingAs($targetAccount->user)->post(route('suchak.collaborations.reject', $collaboration));
        $response
            ->assertRedirect()
            ->assertSessionHas('success', 'Collaboration request rejected.');

        $rejected = $collaboration->fresh(['commissionAgreement']);
        $this->assertSame(SuchakCollaborationRequest::STATUS_REJECTED, $rejected->status);
        $this->assertSame(SuchakCommissionAgreement::STATUS_REJECTED, $rejected->commissionAgreement->agreement_status);
        $this->assertFalse(app(SuchakCollaborationService::class)->canExchangeContact($rejected));

        $this->assertDatabaseHas('suchak_activity_logs', [
            'action_type' => SuchakActivityLog::ACTION_COLLABORATION_REQUEST_REJECTED,
            'target_type' => 'suchak_collaboration_request',
            'target_id' => $collaboration->id,
        ]);
    }

    public function test_expired_pending_collaboration_is_closed_and_commission_ack_cancelled(): void
    {
        [$requestingUser, $requestingAccount, $requestingRepresentation, , $targetRepresentation] = $this->validCollaborationFixture();

        $collaboration = app(SuchakCollaborationService::class)->createRequest(
            $requestingAccount,
            $requestingUser,
            $requestingRepresentation,
            $targetRepresentation,
        )['request'];

        DB::table('suchak_collaboration_requests')
            ->where('id', $collaboration->id)
            ->update(['expires_at' => now()->subMinute()]);

        $expired = app(SuchakCollaborationService::class)->expireIfPastDue($collaboration->fresh());

        $this->assertSame(SuchakCollaborationRequest::STATUS_EXPIRED, $expired->status);
        $this->assertSame(SuchakCommissionAgreement::STATUS_CANCELLED, $expired->commissionAgreement->agreement_status);
        $this->assertFalse(app(SuchakCollaborationService::class)->canExchangeContact($expired));

        $this->assertDatabaseHas('suchak_activity_logs', [
            'action_type' => SuchakActivityLog::ACTION_COLLABORATION_REQUEST_EXPIRED,
            'target_type' => 'suchak_collaboration_request',
            'target_id' => $collaboration->id,
            'actor_type' => SuchakActivityLog::ACTOR_SYSTEM,
        ]);
    }

    public function test_duplicate_open_collaboration_and_wrong_actor_are_blocked(): void
    {
        [$requestingUser, $requestingAccount, $requestingRepresentation, , $targetRepresentation] = $this->validCollaborationFixture();
        $service = app(SuchakCollaborationService::class);

        $service->createRequest($requestingAccount, $requestingUser, $requestingRepresentation, $targetRepresentation);

        try {
            $service->createRequest($requestingAccount, $requestingUser, $requestingRepresentation, $targetRepresentation);

            $this->fail('Duplicate open collaboration request should be blocked.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('An open collaboration request already exists for this Suchak/profile pair.', $exception->getMessage());
        }

        $otherAccount = SuchakAccount::factory()->create([
            'user_id' => User::factory(),
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        try {
            $service->createRequest($otherAccount, $otherAccount->user, $requestingRepresentation, $targetRepresentation);

            $this->fail('Wrong Suchak owner should not be able to use another account representation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Requesting representation must belong to the requesting Suchak account.', $exception->getMessage());
        }
    }

    public function test_day_12_records_cannot_be_deleted(): void
    {
        [$requestingUser, $requestingAccount, $requestingRepresentation, , $targetRepresentation] = $this->validCollaborationFixture();

        $collaboration = app(SuchakCollaborationService::class)->createRequest(
            $requestingAccount,
            $requestingUser,
            $requestingRepresentation,
            $targetRepresentation,
        )['request'];
        $agreement = $collaboration->commissionAgreement()->firstOrFail();

        try {
            $collaboration->delete();

            $this->fail('Suchak collaboration request delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak collaboration request records cannot be deleted.', $exception->getMessage());
        }

        try {
            $agreement->delete();

            $this->fail('Suchak commission agreement delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak commission agreement records cannot be deleted.', $exception->getMessage());
        }
    }

    public function test_search_view_shows_collaboration_form_without_private_contact_details(): void
    {
        [$requestingUser, , $requestingRepresentation, , $targetRepresentation] = $this->validCollaborationFixture();
        $this->insertPrivateContactFixture($targetRepresentation->matrimonyProfile);

        $response = $this->actingAs($requestingUser)->get(route('suchak.search.index'));

        $response->assertOk();
        $response->assertSee('Available through: #', false);
        $response->assertSee('Request goes only to this Suchak', false);
        $response->assertSee('Send collaboration request', false);
        $response->assertSee((string) $requestingRepresentation->id, false);
        $response->assertDontSee('Sensitive Target Candidate', false);
        $response->assertDontSee('9876543210', false);
        $response->assertDontSee('Target Secret Lane', false);
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: SuchakProfileRepresentation, 3: SuchakAccount, 4: SuchakProfileRepresentation}
     */
    private function validCollaborationFixture(): array
    {
        [$requestingUser, $requestingAccount] = $this->verifiedSuchakActor();
        [, $targetAccount] = $this->verifiedSuchakActor();

        $requestingProfile = $this->activeProfile([
            'full_name' => 'Sensitive Requesting Candidate',
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'highest_education' => 'Requesting MBA',
        ]);
        $targetProfile = $this->activeProfile([
            'full_name' => 'Sensitive Target Candidate',
            'date_of_birth' => now()->subYears(27)->toDateString(),
            'highest_education' => 'Target B.Tech',
            'address_line' => 'Target Secret Lane',
        ]);

        $requestingRepresentation = $this->activeRepresentation($requestingAccount, $requestingProfile);
        $targetRepresentation = $this->activeRepresentation($targetAccount, $targetProfile);

        return [$requestingUser, $requestingAccount, $requestingRepresentation, $targetAccount, $targetRepresentation];
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
            'consent_status' => SuchakConsent::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'used_at' => now(),
            'otp_verified_at' => now(),
            'valid_from' => now(),
            'valid_until' => $representation->consent_valid_until,
        ]);

        return $representation->fresh(['suchakAccount', 'matrimonyProfile.gender']);
    }

    private function insertPrivateContactFixture(MatrimonyProfile $profile): void
    {
        $contactRow = [
            'profile_id' => $profile->id,
            'contact_name' => 'Sensitive Target Candidate',
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
    }
}
