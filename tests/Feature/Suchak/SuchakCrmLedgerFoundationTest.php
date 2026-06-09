<?php

namespace Tests\Feature\Suchak;

use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakConsent;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPipeline;
use App\Models\SuchakProfileNote;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCrmLedgerService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakCrmLedgerFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_suchak_crm_ledger_tables_exist_with_day_13_columns(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_profile_notes'));
        $this->assertTrue(Schema::hasTable('suchak_ledger_entries'));

        foreach ([
            'suchak_account_id',
            'matrimony_profile_id',
            'collaboration_request_id',
            'note_type',
            'note_text',
            'visibility',
            'follow_up_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_profile_notes', $column), $column);
        }

        foreach ([
            'suchak_account_id',
            'matrimony_profile_id',
            'pipeline_id',
            'collaboration_request_id',
            'entry_type',
            'amount',
            'currency',
            'status',
            'due_date',
            'paid_at',
            'note',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_ledger_entries', $column), $column);
        }

        $this->assertFalse(Schema::hasColumn('suchak_profile_notes', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('suchak_ledger_entries', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('suchak_profile_notes', 'phone_number'));
        $this->assertFalse(Schema::hasColumn('suchak_ledger_entries', 'payment_id'));
    }

    public function test_verified_suchak_can_create_private_profile_note_without_mutating_profile(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $profile = $this->activeProfile(['full_name' => 'Sensitive CRM Candidate']);
        $beforeUpdatedAt = $profile->updated_at?->toDateTimeString();

        $note = app(SuchakCrmLedgerService::class)->createProfileNote(
            $account,
            $user,
            $profile,
            [
                'note_type' => SuchakProfileNote::TYPE_FOLLOW_UP,
                'note_text' => 'Follow up after family meeting.',
                'follow_up_at' => now()->addDay(),
            ],
            '127.0.0.1',
            'Day-13 test',
        );

        $this->assertSame($account->id, $note->suchak_account_id);
        $this->assertSame($profile->id, $note->matrimony_profile_id);
        $this->assertSame(SuchakProfileNote::TYPE_FOLLOW_UP, $note->note_type);
        $this->assertSame(SuchakProfileNote::VISIBILITY_PRIVATE, $note->visibility);
        $this->assertSame($beforeUpdatedAt, $profile->fresh()->updated_at?->toDateTimeString());

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $user->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_CRM_NOTE_ADDED,
            'target_type' => 'suchak_profile_note',
            'target_id' => $note->id,
            'matrimony_profile_id' => $profile->id,
        ]);

        $metadata = SuchakActivityLog::query()
            ->where('action_type', SuchakActivityLog::ACTION_CRM_NOTE_ADDED)
            ->where('target_id', $note->id)
            ->firstOrFail()
            ->metadata_json;
        $encodedMetadata = json_encode($metadata, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Follow up after family meeting', $encodedMetadata);
        $this->assertStringNotContainsString('Sensitive CRM Candidate', $encodedMetadata);
    }

    public function test_private_notes_are_scoped_to_owning_suchak_only(): void
    {
        [$ownerUser, $ownerAccount] = $this->verifiedSuchakActor();
        [$otherUser, $otherAccount] = $this->verifiedSuchakActor();
        $profile = $this->activeProfile();
        $service = app(SuchakCrmLedgerService::class);

        $service->createProfileNote($ownerAccount, $ownerUser, $profile, [
            'note_text' => 'Owner private note.',
        ]);

        $this->assertCount(1, $service->privateNotesForProfile($ownerAccount, $ownerUser, $profile));
        $this->assertCount(0, $service->privateNotesForProfile($otherAccount, $otherUser, $profile));

        try {
            $service->privateNotesForProfile($ownerAccount, $otherUser, $profile);

            $this->fail('Non-owner actor should not read another Suchak note list.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Only the owning Suchak account can manage private CRM records.', $exception->getMessage());
        }
    }

    public function test_verified_suchak_can_create_ledger_entry_with_pipeline_and_collaboration_context(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $profile = $this->activeProfile(['full_name' => 'Ledger Candidate']);
        $pipeline = $this->pipelineFor($account, $profile);
        $collaboration = $this->collaborationFor($account, $profile);
        $beforeUpdatedAt = $profile->updated_at?->toDateTimeString();

        $entry = app(SuchakCrmLedgerService::class)->createLedgerEntry(
            $account,
            $user,
            $profile,
            [
                'pipeline_id' => $pipeline->id,
                'collaboration_request_id' => $collaboration->id,
                'entry_type' => SuchakLedgerEntry::TYPE_SUCCESS_FEE_EXPECTED,
                'amount' => '2500',
                'currency' => 'inr',
                'status' => SuchakLedgerEntry::STATUS_EXPECTED,
                'due_date' => now()->addDays(7)->toDateString(),
                'note' => 'Success fee expected after confirmed match.',
            ],
            '127.0.0.1',
            'Day-13 ledger test',
        );

        $this->assertSame($account->id, $entry->suchak_account_id);
        $this->assertSame($profile->id, $entry->matrimony_profile_id);
        $this->assertSame($pipeline->id, $entry->pipeline_id);
        $this->assertSame($collaboration->id, $entry->collaboration_request_id);
        $this->assertSame(SuchakLedgerEntry::TYPE_SUCCESS_FEE_EXPECTED, $entry->entry_type);
        $this->assertSame(SuchakLedgerEntry::STATUS_EXPECTED, $entry->status);
        $this->assertSame('2500.00', (string) $entry->amount);
        $this->assertSame('INR', $entry->currency);
        $this->assertSame($beforeUpdatedAt, $profile->fresh()->updated_at?->toDateTimeString());

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $user->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_LEDGER_ENTRY_CREATED,
            'target_type' => 'suchak_ledger_entry',
            'target_id' => $entry->id,
            'matrimony_profile_id' => $profile->id,
        ]);

        $metadata = SuchakActivityLog::query()
            ->where('action_type', SuchakActivityLog::ACTION_LEDGER_ENTRY_CREATED)
            ->where('target_id', $entry->id)
            ->firstOrFail()
            ->metadata_json;
        $encodedMetadata = json_encode($metadata, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Success fee expected after confirmed match', $encodedMetadata);
        $this->assertStringNotContainsString('Ledger Candidate', $encodedMetadata);
    }

    public function test_crm_ledger_rejects_private_contact_text_and_invalid_values(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $profile = $this->activeProfile();
        $service = app(SuchakCrmLedgerService::class);

        try {
            $service->createProfileNote($account, $user, $profile, [
                'note_text' => 'Call candidate on 9876543210',
            ]);

            $this->fail('Suchak note should not store private contact text.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak CRM records must not store private contact details.', $exception->getMessage());
        }

        try {
            $service->createLedgerEntry($account, $user, $profile, [
                'entry_type' => SuchakLedgerEntry::TYPE_PAYMENT_REMINDER,
                'note' => 'Email family at private@example.com',
            ]);

            $this->fail('Suchak ledger should not store private contact text.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak CRM records must not store private contact details.', $exception->getMessage());
        }

        try {
            $service->createLedgerEntry($account, $user, $profile, [
                'entry_type' => 'unsupported',
            ]);

            $this->fail('Unsupported ledger entry type should be blocked.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Invalid Suchak ledger entry type.', $exception->getMessage());
        }
    }

    public function test_pipeline_and_collaboration_context_must_belong_to_suchak_and_profile(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        [, $otherAccount] = $this->verifiedSuchakActor();
        $profile = $this->activeProfile();
        $otherProfile = $this->activeProfile();
        $otherPipeline = $this->pipelineFor($otherAccount, $profile);
        $otherCollaboration = $this->collaborationFor($otherAccount, $otherProfile);
        $service = app(SuchakCrmLedgerService::class);

        try {
            $service->createLedgerEntry($account, $user, $profile, [
                'pipeline_id' => $otherPipeline->id,
                'entry_type' => SuchakLedgerEntry::TYPE_PAYMENT_REMINDER,
            ]);

            $this->fail('Ledger pipeline from another Suchak should be blocked.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Ledger pipeline context must belong to the Suchak account.', $exception->getMessage());
        }

        try {
            $service->createProfileNote($account, $user, $profile, [
                'collaboration_request_id' => $otherCollaboration->id,
                'note_text' => 'Context mismatch note.',
            ]);

            $this->fail('Collaboration context from another Suchak should be blocked.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('CRM collaboration context must include the Suchak account.', $exception->getMessage());
        }
    }

    public function test_day_13_records_cannot_be_deleted(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $profile = $this->activeProfile();
        $service = app(SuchakCrmLedgerService::class);

        $note = $service->createProfileNote($account, $user, $profile, [
            'note_text' => 'No delete note.',
        ]);
        $entry = $service->createLedgerEntry($account, $user, $profile, [
            'entry_type' => SuchakLedgerEntry::TYPE_REGISTRATION_FEE_EXPECTED,
        ]);

        try {
            $note->delete();

            $this->fail('Suchak profile note delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak profile notes cannot be deleted.', $exception->getMessage());
        }

        try {
            $entry->delete();

            $this->fail('Suchak ledger delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak ledger entries cannot be deleted.', $exception->getMessage());
        }
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

    private function pipelineFor(SuchakAccount $account, MatrimonyProfile $targetProfile): SuchakPipeline
    {
        $requestingProfile = $this->activeProfile();
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

        $request = SuchakProfileRequest::factory()->create([
            'requesting_matrimony_profile_id' => $requestingProfile->id,
            'target_matrimony_profile_id' => $targetProfile->id,
            'selected_suchak_account_id' => $account->id,
            'representation_id' => $representation->id,
        ]);

        return SuchakPipeline::factory()->create([
            'request_id' => $request->id,
            'target_matrimony_profile_id' => $targetProfile->id,
            'requesting_matrimony_profile_id' => $requestingProfile->id,
            'selected_suchak_account_id' => $account->id,
            'representation_id' => $representation->id,
        ]);
    }

    private function collaborationFor(SuchakAccount $account, MatrimonyProfile $profile): SuchakCollaborationRequest
    {
        [, $targetAccount] = $this->verifiedSuchakActor();
        $targetProfile = $this->activeProfile();
        $requestingRepresentation = SuchakProfileRepresentation::query()
            ->where('suchak_account_id', $account->id)
            ->where('matrimony_profile_id', $profile->id)
            ->first();
        if (! $requestingRepresentation) {
            $requestingRepresentation = SuchakProfileRepresentation::factory()->create([
                'suchak_account_id' => $account->id,
                'matrimony_profile_id' => $profile->id,
                'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
                'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
                'first_verified_consent_at' => now(),
                'consent_verified_at' => now(),
                'consent_valid_until' => now()->addYear(),
            ]);
        }
        $targetRepresentation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $targetAccount->id,
            'matrimony_profile_id' => $targetProfile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        return SuchakCollaborationRequest::factory()->create([
            'requesting_suchak_account_id' => $account->id,
            'target_suchak_account_id' => $targetAccount->id,
            'requesting_matrimony_profile_id' => $profile->id,
            'target_matrimony_profile_id' => $targetProfile->id,
            'requesting_representation_id' => $requestingRepresentation->id,
            'target_representation_id' => $targetRepresentation->id,
        ]);
    }
}
