<?php

namespace Tests\Feature\Suchak;

use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakConsent;
use App\Models\SuchakPipeline;
use App\Models\SuchakPipelineEvent;
use App\Models\SuchakPolicy;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakRequestPipelineService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakRequestPipelineFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_suchak_request_pipeline_tables_exist_with_day_9_columns(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_profile_requests'));
        $this->assertTrue(Schema::hasTable('suchak_pipelines'));
        $this->assertTrue(Schema::hasTable('suchak_pipeline_events'));

        foreach ([
            'requesting_user_id',
            'requesting_matrimony_profile_id',
            'target_matrimony_profile_id',
            'selected_suchak_account_id',
            'representation_id',
            'request_status',
            'request_reason',
            'message',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_profile_requests', $column), $column);
        }

        foreach ([
            'request_id',
            'target_matrimony_profile_id',
            'requesting_matrimony_profile_id',
            'selected_suchak_account_id',
            'representation_id',
            'pipeline_status',
            'attribution_locked_at',
            'lock_expires_at',
            'sla_status',
            'converted_at',
            'closed_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_pipelines', $column), $column);
        }

        foreach ([
            'pipeline_id',
            'event_type',
            'actor_type',
            'actor_id',
            'event_note',
            'created_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_pipeline_events', $column), $column);
        }

        $this->assertFalse(Schema::hasColumn('suchak_profile_requests', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('suchak_pipelines', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('suchak_pipeline_events', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('suchak_pipeline_events', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('suchak_profile_requests', 'contact_number'));
        $this->assertFalse(Schema::hasColumn('suchak_pipelines', 'contact_number'));
    }

    public function test_user_can_create_request_pipeline_for_valid_public_suchak_representation(): void
    {
        [$requestingUser, $requestingProfile, $representation, $targetProfile] = $this->validRequestFixture();

        $result = app(SuchakRequestPipelineService::class)->createRequest(
            $requestingUser,
            $requestingProfile,
            $representation,
            [
                'request_reason' => 'interested',
                'message' => 'Please route this request through Suchak.',
            ],
            '127.0.0.1',
            'Day-9 feature test',
        );

        $request = $result['request'];
        $pipeline = $result['pipeline'];
        $event = $result['event'];

        $this->assertSame(SuchakProfileRequest::STATUS_PENDING, $request->request_status);
        $this->assertSame($requestingUser->id, $request->requesting_user_id);
        $this->assertSame($requestingProfile->id, $request->requesting_matrimony_profile_id);
        $this->assertSame($targetProfile->id, $request->target_matrimony_profile_id);
        $this->assertSame($representation->id, $request->representation_id);

        $this->assertSame($request->id, $pipeline->request_id);
        $this->assertSame(SuchakPipeline::STATUS_PENDING, $pipeline->pipeline_status);
        $this->assertSame(SuchakPipeline::SLA_WITHIN, $pipeline->sla_status);
        $this->assertNotNull($pipeline->attribution_locked_at);
        $this->assertTrue($pipeline->lock_expires_at->greaterThan(now()->addHours(47)));
        $this->assertTrue($pipeline->lock_expires_at->lessThan(now()->addHours(49)));

        $this->assertSame($pipeline->id, $event->pipeline_id);
        $this->assertSame(SuchakPipelineEvent::EVENT_REQUEST_CREATED, $event->event_type);
        $this->assertSame(SuchakPipelineEvent::ACTOR_USER, $event->actor_type);
        $this->assertSame($requestingUser->id, $event->actor_id);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $representation->suchak_account_id,
            'actor_user_id' => $requestingUser->id,
            'actor_type' => SuchakActivityLog::ACTOR_USER,
            'action_type' => SuchakActivityLog::ACTION_USER_REQUEST_CREATED,
            'target_type' => 'suchak_profile_request',
            'target_id' => $request->id,
            'matrimony_profile_id' => $targetProfile->id,
        ]);
    }

    public function test_request_sla_reads_suchak_policy_not_fixed_72_hours(): void
    {
        DB::table('suchak_policies')
            ->where('policy_key', 'request_action_sla_hours')
            ->update(['policy_value' => '6']);

        [$requestingUser, $requestingProfile, $representation] = $this->validRequestFixture();

        $pipeline = app(SuchakRequestPipelineService::class)->createRequest(
            $requestingUser,
            $requestingProfile,
            $representation,
        )['pipeline'];

        $this->assertTrue($pipeline->lock_expires_at->greaterThan(now()->addHours(5)));
        $this->assertTrue($pipeline->lock_expires_at->lessThan(now()->addHours(7)));
    }

    public function test_duplicate_open_request_is_blocked_until_sla_expiry_allows_alternate_attempt(): void
    {
        [$requestingUser, $requestingProfile, $representation] = $this->validRequestFixture();
        $service = app(SuchakRequestPipelineService::class);
        $first = $service->createRequest($requestingUser, $requestingProfile, $representation);

        try {
            $service->createRequest($requestingUser, $requestingProfile, $representation);

            $this->fail('Duplicate open request should be blocked.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('An open Suchak request already exists for this selected Suchak.', $exception->getMessage());
        }

        SuchakPipeline::query()
            ->whereKey($first['pipeline']->id)
            ->update(['lock_expires_at' => now()->subMinute()]);

        $expired = $service->expirePipelineIfPastSla($first['pipeline']->fresh());

        $this->assertSame(SuchakPipeline::STATUS_EXPIRED, $expired->pipeline_status);
        $this->assertSame(SuchakPipeline::SLA_EXPIRED, $expired->sla_status);
        $this->assertSame(SuchakProfileRequest::STATUS_EXPIRED, $expired->request->request_status);
        $this->assertTrue($service->allowsAlternateSuchakSelection($expired->request));

        $second = $service->createRequest($requestingUser, $requestingProfile, $representation);

        $this->assertNotSame($first['request']->id, $second['request']->id);
        $this->assertDatabaseHas('suchak_pipeline_events', [
            'pipeline_id' => $expired->id,
            'event_type' => SuchakPipelineEvent::EVENT_EXPIRED,
            'actor_type' => SuchakPipelineEvent::ACTOR_SYSTEM,
        ]);
        $this->assertDatabaseHas('suchak_activity_logs', [
            'action_type' => SuchakActivityLog::ACTION_PIPELINE_STATUS_CHANGED,
            'target_type' => 'suchak_pipeline',
            'target_id' => $expired->id,
            'actor_type' => SuchakActivityLog::ACTOR_SYSTEM,
        ]);
    }

    public function test_request_creation_requires_public_valid_suchak_representation_and_active_profiles(): void
    {
        [$requestingUser, $requestingProfile, $representation] = $this->validRequestFixture([
            'account' => [
                'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            ],
        ]);

        try {
            app(SuchakRequestPipelineService::class)->createRequest($requestingUser, $requestingProfile, $representation);

            $this->fail('Hidden Suchak should not be selectable for Day-9 request foundation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Selected Suchak must be verified and publicly active.', $exception->getMessage());
        }

        [$expiredUser, $expiredRequestingProfile, $expiredRepresentation] = $this->validRequestFixture([
            'representation' => [
                'consent_valid_until' => now()->subDay(),
            ],
        ]);

        try {
            app(SuchakRequestPipelineService::class)->createRequest($expiredUser, $expiredRequestingProfile, $expiredRepresentation);

            $this->fail('Expired consent representation should not create a request.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak request requires active representation with valid consent.', $exception->getMessage());
        }

        [$draftUser, $draftRequestingProfile, $draftRepresentation] = $this->validRequestFixture([
            'requesting_profile' => [
                'lifecycle_state' => 'draft',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Requesting profile must be active to create a Suchak request.');

        app(SuchakRequestPipelineService::class)->createRequest($draftUser, $draftRequestingProfile, $draftRepresentation);
    }

    public function test_activity_and_pipeline_event_do_not_leak_private_candidate_contact(): void
    {
        [$requestingUser, $requestingProfile, $representation, $targetProfile] = $this->validRequestFixture();
        $this->insertPrivateContactFixture($targetProfile);

        $result = app(SuchakRequestPipelineService::class)->createRequest(
            $requestingUser,
            $requestingProfile,
            $representation,
            ['message' => 'Please check compatibility.'],
        );

        $activityPayload = SuchakActivityLog::query()
            ->where('action_type', SuchakActivityLog::ACTION_USER_REQUEST_CREATED)
            ->where('target_id', $result['request']->id)
            ->firstOrFail()
            ->metadata_json;

        $encodedActivity = json_encode($activityPayload, JSON_THROW_ON_ERROR);
        $encodedEvent = json_encode($result['event']->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Sensitive Candidate', $encodedActivity);
        $this->assertStringNotContainsString('9876543210', $encodedActivity);
        $this->assertStringNotContainsString('1997-05-15', $encodedActivity);
        $this->assertStringNotContainsString('Sensitive Candidate', $encodedEvent);
        $this->assertStringNotContainsString('9876543210', $encodedEvent);
    }

    public function test_day_9_records_and_events_cannot_be_deleted_or_mutated(): void
    {
        $request = SuchakProfileRequest::factory()->create();
        $pipeline = SuchakPipeline::factory()->create();
        $event = SuchakPipelineEvent::factory()->create();

        try {
            $request->delete();

            $this->fail('Suchak profile request delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak profile request records cannot be deleted.', $exception->getMessage());
        }

        try {
            $pipeline->delete();

            $this->fail('Suchak pipeline delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak pipeline records cannot be deleted.', $exception->getMessage());
        }

        try {
            $event->update(['event_type' => SuchakPipelineEvent::EVENT_EXPIRED]);

            $this->fail('Suchak pipeline event update should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak pipeline events are immutable and cannot be modified or deleted.', $exception->getMessage());
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $overrides
     * @return array{0: User, 1: MatrimonyProfile, 2: SuchakProfileRepresentation, 3: MatrimonyProfile}
     */
    private function validRequestFixture(array $overrides = []): array
    {
        $requestingUser = User::factory()->create();
        $requestingProfile = $this->createProfileWithOptionalActiveResidence(array_merge([
            'user_id' => $requestingUser->id,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ], $overrides['requesting_profile'] ?? []));

        $suchakUser = User::factory()->create();
        $account = SuchakAccount::factory()->create(array_merge([
            'user_id' => $suchakUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ], $overrides['account'] ?? []));

        $targetProfile = $this->createProfileWithOptionalActiveResidence(array_merge([
            'full_name' => 'Sensitive Candidate',
            'date_of_birth' => '1997-05-15',
            'father_contact_1' => '8888888888',
            'mother_contact_1' => '7777777777',
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ], $overrides['target_profile'] ?? []));

        $representation = SuchakProfileRepresentation::factory()->create(array_merge([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $targetProfile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ], $overrides['representation'] ?? []));

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

        return [$requestingUser, $requestingProfile, $representation, $targetProfile];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createProfileWithOptionalActiveResidence(array $attributes): MatrimonyProfile
    {
        $targetLifecycleState = (string) ($attributes['lifecycle_state'] ?? 'draft');
        $attributes['lifecycle_state'] = 'draft';

        $profile = MatrimonyProfile::factory()->create($attributes);

        if ($targetLifecycleState !== 'active') {
            return $profile->fresh();
        }

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

    private function insertPrivateContactFixture(MatrimonyProfile $profile): void
    {
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
    }
}
