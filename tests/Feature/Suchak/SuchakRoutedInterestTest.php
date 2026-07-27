<?php

namespace Tests\Feature\Suchak;

use App\Models\City;
use App\Models\Interest;
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
use App\Models\UserFeatureUsage;
use App\Modules\Suchak\Services\SuchakRequestPipelineService;
use App\Modules\Suchak\Services\SuchakRequestPresenter;
use App\Services\FeatureUsageService;
use App\Services\Interest\SuchakRoutedInterestService;
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
 * The last gap in the Suchak approach flow: a member's plain INTEREST (the heart).
 *
 * The heart is the primary action on every card and profile; the Suchak-request
 * CTA is buried inside the contact card. So almost every member sends an
 * interest, not a Suchak request — and before this change that interest created
 * an `interests` row nobody could act on. The Suchak's inbox lists
 * `suchak_profile_requests` only, so the approach died silently while the
 * member's own sent list confidently said "sent".
 *
 * These tests pin that a routed interest lands in the SAME pipeline as a Suchak
 * request (one inbox, one lifecycle, one SLA, one chat), that the member still
 * sees exactly ONE interest with a truthful status, that the two states settle
 * together, and — the regression guard that matters most — that an interest to a
 * NON-routed profile behaves exactly as it always did.
 */
class SuchakRoutedInterestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    // -------------------------------------------------------------------------
    // The gap itself
    // -------------------------------------------------------------------------

    public function test_an_interest_to_a_suchak_routed_profile_reaches_the_suchak_inbox(): void
    {
        $fixture = $this->fixture();

        $interestId = $this->sendInterest($fixture);

        // ONE interest for the member, exactly as before.
        $this->assertSame(1, Interest::query()->count());

        // ...and the SAME pipeline record a Suchak-request CTA would have made.
        $request = SuchakProfileRequest::query()->sole();
        $this->assertSame((int) $fixture['member_profile']->id, (int) $request->requesting_matrimony_profile_id);
        $this->assertSame((int) $fixture['target_profile']->id, (int) $request->target_matrimony_profile_id);
        $this->assertSame((int) $fixture['account']->id, (int) $request->selected_suchak_account_id);
        $this->assertSame(SuchakProfileRequest::STATUS_PENDING, $request->request_status);
        $this->assertSame(SuchakRoutedInterestService::REQUEST_REASON, $request->request_reason);

        // The attribution lock + SLA window come from the existing pipeline row.
        $pipeline = SuchakPipeline::query()->where('request_id', $request->id)->sole();
        $this->assertSame(SuchakPipeline::STATUS_PENDING, $pipeline->pipeline_status);
        $this->assertNotNull($pipeline->lock_expires_at);

        // The heart is not a message: no chat line is invented on the member's behalf.
        $this->assertNull($request->message);
        $this->assertSame(0, Message::query()->count());

        // And the Suchak finally SEES it in the list they already had.
        Sanctum::actingAs($fixture['account']->user);
        $this->getJson('/api/v1/suchak/profile-requests')
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.awaiting_action_count', 1)
            ->assertJsonPath('data.profile_requests.0.id', (int) $request->id)
            ->assertJsonPath('data.profile_requests.0.from_profile.name', 'Seeking Member')
            ->assertJsonPath('data.profile_requests.0.actions.can_reply', true);

        $this->assertSame('pending', Interest::query()->findOrFail($interestId)->status);
    }

    public function test_the_suchak_can_reply_to_a_routed_interest_and_it_reaches_the_chat(): void
    {
        $fixture = $this->fixture();
        $this->sendInterest($fixture);
        $requestId = (int) SuchakProfileRequest::query()->sole()->id;

        Sanctum::actingAs($fixture['account']->user);

        $this->postJson("/api/v1/suchak/profile-requests/{$requestId}/reply", [
            'reply_message' => 'We will arrange a meeting.',
        ])
            ->assertOk()
            ->assertJsonPath('data.profile_request.status', SuchakProfileRequest::STATUS_ACCEPTED_BY_SUCHAK);

        $message = Message::query()
            ->where('sender_profile_id', $fixture['target_profile']->id)
            ->where('receiver_profile_id', $fixture['member_profile']->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(
            "सूचकांकडून संदेश (Adarsh Vivah Kendra):\nWe will arrange a meeting.",
            $message->body_text,
        );

        // A reply is "I have your approach", NOT the family's answer — so the
        // interest correctly stays pending until a decision is recorded.
        $this->assertSame('pending', Interest::query()->sole()->status);
    }

    // -------------------------------------------------------------------------
    // The member sees ONE interest, with the truth
    // -------------------------------------------------------------------------

    public function test_the_members_sent_list_shows_the_routed_status_and_still_only_one_interest(): void
    {
        $fixture = $this->fixture();
        $this->sendInterest($fixture);

        Sanctum::actingAs($fixture['member']);

        $response = $this->getJson('/api/v1/interests/sent')->assertOk();

        $this->assertCount(1, $response->json('data.sent'));
        $response
            ->assertJsonPath('data.sent.0.status', 'pending')
            ->assertJsonPath('data.sent.0.suchak_routing.is_suchak_routed', true)
            ->assertJsonPath('data.sent.0.suchak_routing.state', SuchakRequestPresenter::CONTACT_STATE_PENDING)
            ->assertJsonPath('data.sent.0.suchak_routing.status', SuchakProfileRequest::STATUS_PENDING)
            ->assertJsonPath('data.sent.0.suchak_routing.suchak.name', 'Adarsh Vivah Kendra');

        // "Your request is with <Suchak>" — not a bare pending that reads as if
        // nobody received it.
        $this->assertSame(
            __('profile.suchak_request_pending_message', ['name' => 'Adarsh Vivah Kendra']),
            $response->json('data.sent.0.suchak_routing.message'),
        );

        // The candidate's own number is never in this payload.
        $this->assertStringNotContainsString('9876500002', json_encode($response->json(), JSON_THROW_ON_ERROR));
    }

    // -------------------------------------------------------------------------
    // The two states settle together
    // -------------------------------------------------------------------------

    public function test_an_interested_decision_settles_the_underlying_interest_row(): void
    {
        $fixture = $this->fixture();
        $interestId = $this->sendInterest($fixture);
        $requestId = (int) SuchakProfileRequest::query()->sole()->id;

        Sanctum::actingAs($fixture['account']->user);
        $this->postJson("/api/v1/suchak/profile-requests/{$requestId}/decision", [
            'decision' => SuchakRequestPipelineService::DECISION_INTERESTED,
        ])
            ->assertOk()
            ->assertJsonPath('data.suchak_request.status', SuchakProfileRequest::STATUS_CANDIDATE_INTERESTED);

        // ONE authoritative transition: the request and the interest agree.
        $this->assertSame('accepted', Interest::query()->findOrFail($interestId)->status);
    }

    public function test_a_not_interested_decision_settles_the_underlying_interest_row(): void
    {
        $fixture = $this->fixture();
        $interestId = $this->sendInterest($fixture);
        $requestId = (int) SuchakProfileRequest::query()->sole()->id;

        Sanctum::actingAs($fixture['account']->user);
        $this->postJson("/api/v1/suchak/profile-requests/{$requestId}/decision", [
            'decision' => SuchakRequestPipelineService::DECISION_NOT_INTERESTED,
        ])->assertOk();

        $this->assertSame('rejected', Interest::query()->findOrFail($interestId)->status);
    }

    // -------------------------------------------------------------------------
    // Consent
    // -------------------------------------------------------------------------

    public function test_a_customer_without_valid_consent_cannot_be_answered(): void
    {
        $fixture = $this->fixture();
        $this->sendInterest($fixture);
        $requestId = (int) SuchakProfileRequest::query()->sole()->id;

        // Consent lapses AFTER the interest was routed.
        $fixture['representation']->forceFill([
            'consent_status' => SuchakProfileRepresentation::CONSENT_REVOKED,
            'revoked_at' => now(),
        ])->save();

        Sanctum::actingAs($fixture['account']->user);

        $this->postJson("/api/v1/suchak/profile-requests/{$requestId}/reply", [
            'reply_message' => 'We will arrange a meeting.',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('profile.suchak_request_consent_required'));

        $this->postJson("/api/v1/suchak/profile-requests/{$requestId}/decision", [
            'decision' => SuchakRequestPipelineService::DECISION_INTERESTED,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('profile.suchak_request_consent_required'));

        $this->assertSame(SuchakProfileRequest::STATUS_PENDING, SuchakProfileRequest::query()->findOrFail($requestId)->request_status);
        $this->assertSame('pending', Interest::query()->sole()->status);
    }

    public function test_an_interest_to_a_profile_whose_consent_is_already_gone_is_never_routed(): void
    {
        $fixture = $this->fixture();

        $fixture['representation']->forceFill([
            'consent_status' => SuchakProfileRepresentation::CONSENT_REVOKED,
            'revoked_at' => now(),
        ])->save();

        $this->sendInterest($fixture);

        // No consent → not routed → nothing for a Suchak to answer, and the
        // interest behaves as an ordinary member-to-member one.
        $this->assertSame(0, SuchakProfileRequest::query()->count());
        $this->assertSame(1, Interest::query()->count());
    }

    // -------------------------------------------------------------------------
    // SLA close, then a fresh approach
    // -------------------------------------------------------------------------

    public function test_the_sla_close_lets_the_member_send_a_fresh_approach_on_the_same_interest(): void
    {
        $fixture = $this->fixture();
        $interestId = $this->sendInterest($fixture);
        $firstRequestId = (int) SuchakProfileRequest::query()->sole()->id;

        // Re-tapping the heart while the approach is open changes nothing.
        Sanctum::actingAs($fixture['member']);
        $this->postJson('/api/v1/interests', [
            'receiver_profile_id' => $fixture['target_profile']->id,
        ])->assertStatus(409);
        $this->assertSame(1, SuchakProfileRequest::query()->count());

        // Run the SLA window out — the same expiry mechanism, no second timer.
        SuchakPipeline::query()
            ->where('request_id', $firstRequestId)
            ->update(['lock_expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/interests', [
            'receiver_profile_id' => $fixture['target_profile']->id,
        ])->assertStatus(409);

        $this->assertSame(
            SuchakProfileRequest::STATUS_EXPIRED,
            SuchakProfileRequest::query()->findOrFail($firstRequestId)->request_status,
        );

        // A SECOND pipeline request now exists for the SAME single interest.
        $this->assertSame(2, SuchakProfileRequest::query()->count());
        $this->assertSame(1, Interest::query()->count());
        $this->assertSame($interestId, (int) Interest::query()->sole()->id);

        $freshRequest = SuchakProfileRequest::query()->latest('id')->firstOrFail();
        $this->assertNotSame($firstRequestId, (int) $freshRequest->id);
        $this->assertSame(SuchakProfileRequest::STATUS_PENDING, $freshRequest->request_status);

        // And the Suchak sees the fresh one.
        Sanctum::actingAs($fixture['account']->user);
        $this->getJson('/api/v1/suchak/profile-requests')
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.profile_requests.0.id', (int) $freshRequest->id);
    }

    public function test_an_answered_interest_is_never_re_routed(): void
    {
        $fixture = $this->fixture();
        $this->sendInterest($fixture);
        $requestId = (int) SuchakProfileRequest::query()->sole()->id;

        Sanctum::actingAs($fixture['account']->user);
        $this->postJson("/api/v1/suchak/profile-requests/{$requestId}/decision", [
            'decision' => SuchakRequestPipelineService::DECISION_NOT_INTERESTED,
        ])->assertOk();

        $this->assertSame('rejected', Interest::query()->sole()->status);

        // Re-tapping the heart on a rejected interest must not manufacture a new
        // approach — identical to the ordinary member-to-member rule.
        Sanctum::actingAs($fixture['member']);
        $this->postJson('/api/v1/interests', [
            'receiver_profile_id' => $fixture['target_profile']->id,
        ])->assertStatus(409);

        $this->assertSame(1, SuchakProfileRequest::query()->count());
    }

    // -------------------------------------------------------------------------
    // Both can answer
    // -------------------------------------------------------------------------

    public function test_candidate_and_suchak_race_to_answer_a_routed_interest_and_the_first_wins(): void
    {
        $fixture = $this->fixture();
        $interestId = $this->sendInterest($fixture);
        $requestId = (int) SuchakProfileRequest::query()->sole()->id;

        // The candidate answers first, from the member app.
        Sanctum::actingAs($fixture['candidate']);
        $this->postJson("/api/v1/suchak-requests/{$requestId}/decision", [
            'decision' => SuchakRequestPipelineService::DECISION_INTERESTED,
        ])
            ->assertOk()
            ->assertJsonPath('code', 'decision_recorded')
            ->assertJsonPath('data.answered_by', 'candidate');

        // The Suchak's answer lands second: clean already_answered, no overwrite.
        Sanctum::actingAs($fixture['account']->user);
        $this->postJson("/api/v1/suchak/profile-requests/{$requestId}/decision", [
            'decision' => SuchakRequestPipelineService::DECISION_NOT_INTERESTED,
        ])
            ->assertOk()
            ->assertJsonPath('code', 'already_answered')
            ->assertJsonPath('data.answered_by', 'candidate')
            ->assertJsonPath('data.suchak_request.status', SuchakProfileRequest::STATUS_CANDIDATE_INTERESTED);

        // The loser wrote nothing — including to the interest.
        $this->assertSame('accepted', Interest::query()->findOrFail($interestId)->status);
        $this->assertSame(1, SuchakPipelineEvent::query()
            ->whereIn('event_type', [
                SuchakPipelineEvent::EVENT_CANDIDATE_INTERESTED,
                SuchakPipelineEvent::EVENT_CANDIDATE_NOT_INTERESTED,
            ])
            ->count());
    }

    // -------------------------------------------------------------------------
    // Quota: one approach is billed once
    // -------------------------------------------------------------------------

    public function test_routing_does_not_charge_the_member_a_second_time(): void
    {
        $fixture = $this->fixture();
        $this->sendInterest($fixture);

        // The interest already consumed interest_send_limit. Routing it must NOT
        // also consume the Suchak-request/chat quota the contact-card CTA charges.
        $this->assertSame(0, UserFeatureUsage::query()
            ->where('user_id', $fixture['member']->id)
            ->where('feature_key', FeatureUsageService::FEATURE_CHAT_SEND_LIMIT)
            ->sum('used_count'));
    }

    // -------------------------------------------------------------------------
    // REGRESSION GUARD: ordinary member-to-member interests are untouched
    // -------------------------------------------------------------------------

    public function test_an_interest_to_a_non_routed_profile_behaves_exactly_as_before(): void
    {
        $fixture = $this->fixture();

        $otherUser = User::factory()->create(['mobile' => '9876599999', 'mobile_verified_at' => now()]);
        $otherProfile = $this->activeProfile([
            'user_id' => $otherUser->id,
            'full_name' => 'Plain Member',
            'gender_id' => (int) DB::table('master_genders')->where('key', 'female')->value('id'),
        ]);

        Sanctum::actingAs($fixture['member']);
        $this->postJson('/api/v1/interests', [
            'receiver_profile_id' => $otherProfile->id,
        ])->assertOk()->assertJsonPath('success', true);

        // No pipeline record of any kind, no routing block, plain pending status.
        $this->assertSame(0, SuchakProfileRequest::query()->count());
        $this->assertSame(0, SuchakPipeline::query()->count());

        $this->getJson('/api/v1/interests/sent')
            ->assertOk()
            ->assertJsonPath('data.sent.0.status', 'pending')
            ->assertJsonPath('data.sent.0.suchak_routing', null);

        // Accept still works exactly the same way from the receiver's side.
        $interest = Interest::query()->sole();
        Sanctum::actingAs($otherUser);
        $this->postJson("/api/v1/interests/{$interest->id}/accept")->assertOk();

        $this->assertSame('accepted', $interest->fresh()->status);
        $this->assertSame(0, SuchakProfileRequest::query()->count());
    }

    // -------------------------------------------------------------------------
    // Backfill
    // -------------------------------------------------------------------------

    public function test_the_backfill_command_rescues_an_already_invisible_interest_and_is_idempotent(): void
    {
        $fixture = $this->fixture();

        // An interest created the way production created id 359: no pipeline row.
        $interest = Interest::query()->create([
            'sender_profile_id' => $fixture['member_profile']->id,
            'receiver_profile_id' => $fixture['target_profile']->id,
            'status' => 'pending',
            'priority_score' => 1,
        ]);

        $this->assertSame(0, SuchakProfileRequest::query()->count());

        // Dry run reports it and writes nothing.
        $this->artisan('suchak:backfill-routed-interests')->assertExitCode(0);
        $this->assertSame(0, SuchakProfileRequest::query()->count());

        $this->artisan('suchak:backfill-routed-interests', ['--commit' => true])->assertExitCode(0);
        $this->assertSame(1, SuchakProfileRequest::query()->count());

        // Idempotent: a second commit run creates nothing.
        $this->artisan('suchak:backfill-routed-interests', ['--commit' => true])->assertExitCode(0);
        $this->assertSame(1, SuchakProfileRequest::query()->count());
        $this->assertSame('pending', $interest->fresh()->status);

        Sanctum::actingAs($fixture['account']->user);
        $this->getJson('/api/v1/suchak/profile-requests')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function sendInterest(array $fixture): int
    {
        Sanctum::actingAs($fixture['member']);

        $response = $this->postJson('/api/v1/interests', [
            'receiver_profile_id' => $fixture['target_profile']->id,
        ])->assertOk();

        return (int) $response->json('data.id');
    }

    /**
     * Same fixture shape as {@see SuchakRequestPipelineApiTest}: a member, a
     * Suchak-managed candidate with their own account, and a verified, publicly
     * active Suchak holding valid consent.
     *
     * @return array{
     *   member: User,
     *   member_profile: MatrimonyProfile,
     *   candidate: User,
     *   target_profile: MatrimonyProfile,
     *   account: SuchakAccount,
     *   representation: SuchakProfileRepresentation
     * }
     */
    private function fixture(): array
    {
        foreach (['male' => 'Male', 'female' => 'Female'] as $key => $label) {
            MasterGender::query()->firstOrCreate(['key' => $key], ['label' => $label, 'is_active' => true]);
        }

        $maleId = (int) DB::table('master_genders')->where('key', 'male')->value('id');
        $femaleId = (int) DB::table('master_genders')->where('key', 'female')->value('id');

        $member = User::factory()->create(['mobile' => '9876500001', 'mobile_verified_at' => now()]);
        $memberProfile = $this->activeProfile([
            'user_id' => $member->id,
            'full_name' => 'Seeking Member',
            'gender_id' => $maleId,
        ]);

        $candidate = User::factory()->create(['mobile' => '9876500002', 'mobile_verified_at' => now()]);
        $targetProfile = $this->activeProfile([
            'user_id' => $candidate->id,
            'full_name' => 'Represented Candidate',
            'gender_id' => $femaleId,
        ]);

        $suchakUser = User::factory()->create(['mobile' => '9876500003', 'mobile_verified_at' => now()]);
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
