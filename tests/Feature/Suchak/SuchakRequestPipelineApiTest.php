<?php

namespace Tests\Feature\Suchak;

use App\Models\City;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\Message;
use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\SuchakPipeline;
use App\Models\SuchakPipelineEvent;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakRequestPipelineService;
use App\Modules\Suchak\Services\SuchakRequestPresenter;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Suchak request pipeline, opened to BOTH mobile apps.
 *
 * Until now this pipeline existed only on the website: a Suchak using the app
 * never learned that a member had approached one of their customers. These
 * tests pin the contract that closes that hole, and — critically — that the
 * mobile path is the SAME pipeline, not a second mechanism:
 *
 *  • a request created from the member API is byte-comparable with a web one
 *  • the Suchak sees it in their API inbox and can reply from the app
 *  • the reply lands in the member↔candidate chat with the same prefix
 *  • a customer whose consent is gone cannot be answered at all
 *  • an SLA close frees the member to send a fresh request
 *  • candidate and Suchak race to answer; first wins, second is told cleanly
 */
class SuchakRequestPipelineApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        // Suchak requests are billed exactly like any other conversation, so the
        // real plan/quota tables must be present — no test-only bypass.
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_member_creates_a_suchak_request_from_the_api(): void
    {
        $fixture = $this->fixture();
        Sanctum::actingAs($fixture['member']);

        $response = $this->postJson("/api/v1/matrimony-profiles/{$fixture['target_profile']->id}/suchak-requests", [
            'request_reason' => 'interested',
            'message' => 'Please share more details about this profile.',
        ])->assertCreated();

        $requestId = (int) $response->json('data.suchak_request.id');

        $this->assertDatabaseHas('suchak_profile_requests', [
            'id' => $requestId,
            'requesting_user_id' => $fixture['member']->id,
            'requesting_matrimony_profile_id' => $fixture['member_profile']->id,
            'target_matrimony_profile_id' => $fixture['target_profile']->id,
            'selected_suchak_account_id' => $fixture['account']->id,
            'representation_id' => $fixture['representation']->id,
            'request_status' => SuchakProfileRequest::STATUS_PENDING,
        ]);

        // The attribution lock + SLA come from the same pipeline row the web builds.
        $pipeline = SuchakPipeline::query()->where('request_id', $requestId)->firstOrFail();
        $this->assertSame(SuchakPipeline::STATUS_PENDING, $pipeline->pipeline_status);
        $this->assertNotNull($pipeline->attribution_locked_at);
        $this->assertNotNull($pipeline->lock_expires_at);

        // The member is told WHICH Suchak has it, and never a candidate number.
        $response->assertJsonPath('data.suchak_request.suchak.suchak_account_id', $fixture['account']->id);
        $this->assertSame('Adarsh Vivah Kendra', $response->json('data.suchak_request.suchak.name'));
        $this->assertStringNotContainsString('9876500002', json_encode($response->json(), JSON_THROW_ON_ERROR));

        // Contact card state the app renders.
        $response->assertJsonPath('display.contact.state', SuchakRequestPresenter::CONTACT_STATE_PENDING);
    }

    public function test_profile_detail_contact_block_offers_and_then_tracks_the_request(): void
    {
        $fixture = $this->fixture();
        Sanctum::actingAs($fixture['member']);

        $this->getJson("/api/v1/matrimony-profiles/{$fixture['target_profile']->id}/suchak-requests")
            ->assertOk()
            ->assertJsonPath('data.is_suchak_routed', true)
            ->assertJsonPath('data.suchaks.0.state', SuchakRequestPresenter::CONTACT_STATE_AVAILABLE)
            ->assertJsonPath('data.suchaks.0.can_request', true)
            ->assertJsonPath('data.suchaks.0.request', null);

        $this->postJson("/api/v1/matrimony-profiles/{$fixture['target_profile']->id}/suchak-requests", [
            'message' => 'Interested.',
        ])->assertCreated();

        $this->getJson("/api/v1/matrimony-profiles/{$fixture['target_profile']->id}/suchak-requests")
            ->assertOk()
            ->assertJsonPath('data.suchaks.0.state', SuchakRequestPresenter::CONTACT_STATE_PENDING)
            ->assertJsonPath('data.suchaks.0.can_request', false)
            ->assertJsonPath('data.suchaks.0.request.status', SuchakProfileRequest::STATUS_PENDING);
    }

    public function test_the_suchak_sees_the_request_in_their_api_list_and_replying_reaches_the_chat(): void
    {
        $fixture = $this->fixture();
        Sanctum::actingAs($fixture['member']);

        $requestId = (int) $this->postJson("/api/v1/matrimony-profiles/{$fixture['target_profile']->id}/suchak-requests", [
            'message' => 'Interested in this profile.',
        ])->assertCreated()->json('data.suchak_request.id');

        Sanctum::actingAs($fixture['account']->user);

        $list = $this->getJson('/api/v1/suchak/profile-requests')
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.awaiting_action_count', 1);

        $this->assertSame($requestId, $list->json('data.profile_requests.0.id'));
        $this->assertSame('Seeking Member', $list->json('data.profile_requests.0.from_profile.name'));
        $this->assertTrue($list->json('data.profile_requests.0.actions.can_reply'));

        // Opening the request is what moves pending → viewed_by_suchak.
        $this->getJson("/api/v1/suchak/profile-requests/{$requestId}")
            ->assertOk()
            ->assertJsonPath('data.profile_request.status', SuchakProfileRequest::STATUS_VIEWED_BY_SUCHAK);

        $this->postJson("/api/v1/suchak/profile-requests/{$requestId}/reply", [
            'reply_message' => 'We will arrange a meeting.',
        ])
            ->assertOk()
            ->assertJsonPath('data.profile_request.status', SuchakProfileRequest::STATUS_ACCEPTED_BY_SUCHAK);

        // Exactly the web behaviour: the reply is injected into the existing
        // member↔candidate chat, authored by the candidate profile, prefixed
        // with the Suchak's name.
        $message = Message::query()
            ->where('sender_profile_id', $fixture['target_profile']->id)
            ->where('receiver_profile_id', $fixture['member_profile']->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(
            "सूचकांकडून संदेश (Adarsh Vivah Kendra):\nWe will arrange a meeting.",
            $message->body_text,
        );

        $this->assertDatabaseHas('suchak_pipeline_events', [
            'event_type' => SuchakPipelineEvent::EVENT_SUCHAK_REPLIED,
            'actor_type' => SuchakPipelineEvent::ACTOR_SUCHAK,
        ]);
    }

    public function test_a_customer_without_valid_consent_cannot_be_answered(): void
    {
        $fixture = $this->fixture();
        Sanctum::actingAs($fixture['member']);

        $requestId = (int) $this->postJson("/api/v1/matrimony-profiles/{$fixture['target_profile']->id}/suchak-requests", [
            'message' => 'Interested.',
        ])->assertCreated()->json('data.suchak_request.id');

        // Consent lapses AFTER the request was created — the reply path must
        // close, not just the create path.
        $fixture['representation']->forceFill([
            'consent_status' => SuchakProfileRepresentation::CONSENT_REVOKED,
            'revoked_at' => now(),
        ])->save();

        Sanctum::actingAs($fixture['account']->user);

        $this->postJson("/api/v1/suchak/profile-requests/{$requestId}/reply", [
            'reply_message' => 'We will arrange a meeting.',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('profile.suchak_request_consent_required'));

        $this->postJson("/api/v1/suchak/profile-requests/{$requestId}/decision", [
            'decision' => SuchakRequestPipelineService::DECISION_INTERESTED,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('profile.suchak_request_consent_required'));

        $this->assertSame(
            SuchakProfileRequest::STATUS_PENDING,
            SuchakProfileRequest::query()->findOrFail($requestId)->request_status,
        );
    }

    public function test_no_reply_inside_the_sla_closes_the_request_and_a_fresh_one_can_be_sent(): void
    {
        $fixture = $this->fixture();
        Sanctum::actingAs($fixture['member']);

        $firstId = (int) $this->postJson("/api/v1/matrimony-profiles/{$fixture['target_profile']->id}/suchak-requests", [
            'message' => 'First attempt.',
        ])->assertCreated()->json('data.suchak_request.id');

        // A second request while the first is open is refused — unchanged rule.
        $this->postJson("/api/v1/matrimony-profiles/{$fixture['target_profile']->id}/suchak-requests", [
            'message' => 'Too soon.',
        ])->assertStatus(422);

        // Run the SLA window out. Nothing else changes: the same expiry
        // mechanism the web relies on is what re-opens the door.
        SuchakPipeline::query()
            ->where('request_id', $firstId)
            ->update(['lock_expires_at' => now()->subMinute()]);

        $this->getJson("/api/v1/matrimony-profiles/{$fixture['target_profile']->id}/suchak-requests")
            ->assertOk()
            ->assertJsonPath('data.suchaks.0.state', SuchakRequestPresenter::CONTACT_STATE_CLOSED)
            ->assertJsonPath('data.suchaks.0.can_request', true)
            ->assertJsonPath('data.suchaks.0.request.status', SuchakProfileRequest::STATUS_EXPIRED)
            ->assertJsonPath('data.suchaks.0.request.can_resend', true);

        $secondId = (int) $this->postJson("/api/v1/matrimony-profiles/{$fixture['target_profile']->id}/suchak-requests", [
            'message' => 'Fresh attempt.',
        ])->assertCreated()->json('data.suchak_request.id');

        $this->assertNotSame($firstId, $secondId);

        // Neither the attribution lock nor a unique constraint blocks the
        // re-send: a second request row and a second pipeline row both exist.
        $this->assertSame(2, SuchakProfileRequest::query()
            ->where('requesting_matrimony_profile_id', $fixture['member_profile']->id)
            ->where('target_matrimony_profile_id', $fixture['target_profile']->id)
            ->count());
        $this->assertSame(2, SuchakPipeline::query()
            ->whereIn('request_id', [$firstId, $secondId])
            ->count());
    }

    public function test_candidate_and_suchak_race_to_answer_and_the_first_answer_wins(): void
    {
        $fixture = $this->fixture();
        Sanctum::actingAs($fixture['member']);

        $requestId = (int) $this->postJson("/api/v1/matrimony-profiles/{$fixture['target_profile']->id}/suchak-requests", [
            'message' => 'Interested.',
        ])->assertCreated()->json('data.suchak_request.id');

        // The candidate has their own account, so BOTH can answer.
        $this->assertTrue(
            app(SuchakRequestPipelineService::class)
                ->candidateCanAnswer(SuchakProfileRequest::query()->findOrFail($requestId)),
        );

        // The candidate answers first.
        Sanctum::actingAs($fixture['candidate']);
        $this->getJson('/api/v1/suchak-requests')
            ->assertOk()
            ->assertJsonPath('data.received.0.id', $requestId);

        $this->postJson("/api/v1/suchak-requests/{$requestId}/decision", [
            'decision' => SuchakRequestPipelineService::DECISION_INTERESTED,
            'note' => 'Family is keen.',
        ])
            ->assertOk()
            ->assertJsonPath('code', 'decision_recorded')
            ->assertJsonPath('data.already_answered', false)
            ->assertJsonPath('data.answered_by', 'candidate')
            ->assertJsonPath('data.suchak_request.status', SuchakProfileRequest::STATUS_CANDIDATE_INTERESTED);

        // The Suchak's answer lands second: clean "already answered", no error,
        // no overwrite, and the winner is named.
        Sanctum::actingAs($fixture['account']->user);
        $this->postJson("/api/v1/suchak/profile-requests/{$requestId}/decision", [
            'decision' => SuchakRequestPipelineService::DECISION_NOT_INTERESTED,
        ])
            ->assertOk()
            ->assertJsonPath('code', 'already_answered')
            ->assertJsonPath('data.already_answered', true)
            ->assertJsonPath('data.answered_by', 'candidate')
            ->assertJsonPath('data.answered_by_label', __('profile.suchak_request_answered_by_candidate'))
            ->assertJsonPath('data.suchak_request.status', SuchakProfileRequest::STATUS_CANDIDATE_INTERESTED);

        $this->assertSame(
            SuchakProfileRequest::STATUS_CANDIDATE_INTERESTED,
            SuchakProfileRequest::query()->findOrFail($requestId)->request_status,
        );

        // Exactly one decision event — the loser wrote nothing.
        $this->assertSame(1, SuchakPipelineEvent::query()
            ->whereIn('event_type', [
                SuchakPipelineEvent::EVENT_CANDIDATE_INTERESTED,
                SuchakPipelineEvent::EVENT_CANDIDATE_NOT_INTERESTED,
            ])
            ->count());
    }

    public function test_the_suchak_can_forward_to_the_candidate_and_relay_their_answer(): void
    {
        $fixture = $this->fixture();
        Sanctum::actingAs($fixture['member']);

        $requestId = (int) $this->postJson("/api/v1/matrimony-profiles/{$fixture['target_profile']->id}/suchak-requests", [
            'message' => 'Interested.',
        ])->assertCreated()->json('data.suchak_request.id');

        Sanctum::actingAs($fixture['account']->user);

        // forwarded_to_candidate was a declared status that no code ever set.
        $this->postJson("/api/v1/suchak/profile-requests/{$requestId}/forward", [
            'note' => 'Shared with the family.',
        ])
            ->assertOk()
            ->assertJsonPath('data.profile_request.status', SuchakProfileRequest::STATUS_FORWARDED_TO_CANDIDATE);

        $this->postJson("/api/v1/suchak/profile-requests/{$requestId}/decision", [
            'decision' => SuchakRequestPipelineService::DECISION_NOT_INTERESTED,
            'note' => 'Different expectations.',
        ])
            ->assertOk()
            ->assertJsonPath('data.answered_by', 'suchak')
            ->assertJsonPath('data.suchak_request.status', SuchakProfileRequest::STATUS_CANDIDATE_NOT_INTERESTED);

        foreach ([
            SuchakPipelineEvent::EVENT_FORWARDED_TO_CANDIDATE,
            SuchakPipelineEvent::EVENT_CANDIDATE_NOT_INTERESTED,
        ] as $eventType) {
            $this->assertDatabaseHas('suchak_pipeline_events', [
                'event_type' => $eventType,
                'actor_type' => SuchakPipelineEvent::ACTOR_SUCHAK,
            ]);
        }

        // The member learns the outcome through the same chat, prefixed.
        $message = Message::query()
            ->where('sender_profile_id', $fixture['target_profile']->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertStringContainsString('सूचकांकडून संदेश (Adarsh Vivah Kendra):', (string) $message->body_text);
        $this->assertStringContainsString(
            __('profile.suchak_request_decision_chat_not_interested'),
            (string) $message->body_text,
        );
    }

    public function test_an_api_created_request_is_indistinguishable_from_a_web_created_one(): void
    {
        $apiFixture = $this->fixture('9876511001', '9876511002', '9876511003');
        Sanctum::actingAs($apiFixture['member']);

        $apiRequestId = (int) $this->postJson("/api/v1/matrimony-profiles/{$apiFixture['target_profile']->id}/suchak-requests", [
            'request_reason' => 'interested',
            'message' => 'Parity payload.',
        ])->assertCreated()->json('data.suchak_request.id');

        $webFixture = $this->fixture('9876512001', '9876512002', '9876512003');
        $this->actingAs($webFixture['member']);

        $this->post(
            "/matrimony/profile/{$webFixture['target_profile']->id}/suchak-requests/{$webFixture['representation']->id}",
            [
                'request_reason' => 'interested',
                'message' => 'Parity payload.',
            ],
        )->assertRedirect();

        $webRequestId = (int) SuchakProfileRequest::query()
            ->where('requesting_matrimony_profile_id', $webFixture['member_profile']->id)
            ->latest('id')
            ->firstOrFail()
            ->id;

        $normalize = static function (int $id): array {
            $row = SuchakProfileRequest::query()->with('pipeline')->findOrFail($id);

            return [
                'request' => collect($row->getAttributes())
                    ->except(['id', 'created_at', 'updated_at', 'requesting_user_id',
                        'requesting_matrimony_profile_id', 'target_matrimony_profile_id',
                        'selected_suchak_account_id', 'representation_id',
                        'chat_conversation_id', 'request_chat_message_id', 'chat_message_id'])
                    ->all(),
                'pipeline' => collect($row->pipeline?->getAttributes() ?? [])
                    ->except(['id', 'request_id', 'created_at', 'updated_at',
                        'target_matrimony_profile_id', 'requesting_matrimony_profile_id',
                        'selected_suchak_account_id', 'representation_id',
                        'attribution_locked_at', 'lock_expires_at'])
                    ->all(),
            ];
        };

        $this->assertSame($normalize($apiRequestId), $normalize($webRequestId));

        // Both wrote the same event + activity trail.
        foreach ([$apiRequestId, $webRequestId] as $id) {
            $pipelineId = (int) SuchakPipeline::query()->where('request_id', $id)->value('id');
            $this->assertDatabaseHas('suchak_pipeline_events', [
                'pipeline_id' => $pipelineId,
                'event_type' => SuchakPipelineEvent::EVENT_REQUEST_CREATED,
                'actor_type' => SuchakPipelineEvent::ACTOR_USER,
            ]);
            $this->assertDatabaseHas('suchak_activity_logs', [
                'target_type' => 'suchak_profile_request',
                'target_id' => $id,
                'action_type' => 'user_request_created',
            ]);
        }
    }

    /**
     * @return array{
     *   member: User,
     *   member_profile: MatrimonyProfile,
     *   candidate: User,
     *   target_profile: MatrimonyProfile,
     *   account: SuchakAccount,
     *   representation: SuchakProfileRepresentation
     * }
     */
    private function fixture(
        string $memberMobile = '9876500001',
        string $candidateMobile = '9876500002',
        string $suchakMobile = '9876500003',
    ): array {
        foreach (['male' => 'Male', 'female' => 'Female'] as $key => $label) {
            MasterGender::query()->firstOrCreate(['key' => $key], ['label' => $label, 'is_active' => true]);
        }

        $maleId = (int) DB::table('master_genders')->where('key', 'male')->value('id');
        $femaleId = (int) DB::table('master_genders')->where('key', 'female')->value('id');

        $member = User::factory()->create(['mobile' => $memberMobile, 'mobile_verified_at' => now()]);
        $memberProfile = $this->activeProfile([
            'user_id' => $member->id,
            'full_name' => 'Seeking Member',
            'gender_id' => $maleId,
        ]);

        $candidate = User::factory()->create(['mobile' => $candidateMobile, 'mobile_verified_at' => now()]);
        $targetProfile = $this->activeProfile([
            'user_id' => $candidate->id,
            'full_name' => 'Represented Candidate',
            'gender_id' => $femaleId,
        ]);

        $suchakUser = User::factory()->create(['mobile' => $suchakMobile, 'mobile_verified_at' => now()]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'office_name' => 'Adarsh Vivah Kendra',
            'office_name_mr' => null,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $targetProfile->id,
            // A Suchak-created profile is Suchak-routed by definition, which is
            // what makes the member's contact card show the Suchak instead of a
            // number.
            'representation_mode' => SuchakProfileRepresentation::MODE_MANUAL_FORM_BY_SUCHAK,
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

        return [
            'member' => $member,
            'member_profile' => $memberProfile,
            'candidate' => $candidate,
            'target_profile' => $targetProfile,
            'account' => $account,
            'representation' => $representation,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeProfile(array $attributes): MatrimonyProfile
    {
        $profile = MatrimonyProfile::factory()->create(array_merge($attributes, [
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
}
