<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakMarketplaceChallenge;
use App\Models\SuchakPipeline;
use App\Models\SuchakPipelineEvent;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakServicePackage;
use App\Models\SuchakVisitConfirmation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use App\Modules\Suchak\Services\SuchakMarketplaceChallengeService;
use App\Modules\Suchak\Services\SuchakRequestPipelineService;
use App\Modules\Suchak\Services\SuchakVisitConfirmationService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AN ACCEPTED ENGAGEMENT GETS A PIPELINE — blueprint 5.1 / 6.1 / 6a.
 *
 * The meeting engine hangs off `suchak_pipelines` and nothing else; `suchak_visit_confirmations`
 * carries no `collaboration_request_id`. A pipeline had one creator — a MEMBER's request — so an
 * engagement between two Suchaks never had one, and the `meeting_scheduled` rung could be claimed
 * with no meeting able to exist behind it.
 *
 * What this class pins: exactly one pipeline per accepted engagement, filled by ROLE and not by
 * direction; a real meeting on it; idempotence; and the member-born path untouched.
 */
class SuchakEngagementPipelineTest extends TestCase
{
    use RefreshDatabase;

    // ── The pipeline exists, and every column says the truth ──────────────────────────────────

    public function test_an_accepted_marketplace_engagement_opens_exactly_one_pipeline(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [$publisherRepresentation, $customerAgreement] = $this->publishableCandidate($publisher, $publisherUser);
        $helperCandidate = $this->helperCandidate($helper);

        $challenge = $this->challengeService()->publish(
            $publisher,
            $publisherUser,
            $publisherRepresentation,
            $this->percentTerms(30),
        );
        $engagement = $this->challengeService()->proposeCandidate(
            $challenge,
            $helper,
            $helperUser,
            $helperCandidate,
        )['request'];

        // Nothing yet: a proposal is an invitation, not an engagement.
        $this->assertSame(0, SuchakPipeline::query()->count());

        $accepted = $this->collaborationService()->acceptRequest($engagement, $publisher, $publisherUser);
        $this->assertSame(SuchakCollaborationRequest::STATUS_ACCEPTED, $accepted->status);

        $pipelines = SuchakPipeline::query()->get();
        $this->assertCount(1, $pipelines);

        /** @var SuchakPipeline $pipeline */
        $pipeline = $pipelines->first();
        $this->assertSame((int) $engagement->id, (int) $pipeline->collaboration_request_id);
        $this->assertTrue($pipeline->isEngagementBorn());

        // No `suchak_profile_requests` row was invented to satisfy the column.
        $this->assertNull($pipeline->request_id);
        $this->assertSame(0, DB::table('suchak_profile_requests')->count());

        // ROLE, never direction. The publisher owns the customer, so he is the acting Suchak, his
        // candidate is the target and his mandate is the pipeline's representation.
        $this->assertSame((int) $publisher->id, (int) $pipeline->selected_suchak_account_id);
        $this->assertSame((int) $publisherRepresentation->matrimony_profile_id, (int) $pipeline->target_matrimony_profile_id);
        $this->assertSame((int) $publisherRepresentation->id, (int) $pipeline->representation_id);
        $this->assertSame((int) $helperCandidate->matrimony_profile_id, (int) $pipeline->requesting_matrimony_profile_id);

        // The pipeline's own invariant: its representation belongs to the acting Suchak and covers
        // the target profile. Break this and scheduleVisit()'s ownership gate names the wrong man.
        $representation = $pipeline->representation;
        $this->assertSame((int) $pipeline->selected_suchak_account_id, (int) $representation->suchak_account_id);
        $this->assertSame((int) $pipeline->target_matrimony_profile_id, (int) $representation->matrimony_profile_id);

        // No reply is pending, so no reply clock runs — and the SLA-risk board stays honest.
        $this->assertNull($pipeline->lock_expires_at);
        $this->assertFalse($pipeline->isPastSla());
        $this->assertNotNull($pipeline->attribution_locked_at);
        $this->assertSame(SuchakPipeline::STATUS_PENDING, $pipeline->pipeline_status);

        // Opened under its own event type, not filed as a request that was never made.
        $event = SuchakPipelineEvent::query()->where('pipeline_id', $pipeline->id)->sole();
        $this->assertSame(SuchakPipelineEvent::EVENT_ENGAGEMENT_ACCEPTED, $event->event_type);
        $this->assertSame(SuchakPipelineEvent::ACTOR_SUCHAK, $event->actor_type);
        $this->assertSame((int) $publisherUser->id, (int) $event->actor_id);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $publisher->id,
            'action_type' => SuchakActivityLog::ACTION_PIPELINE_STATUS_CHANGED,
            'target_type' => 'suchak_collaboration_request',
            'target_id' => $engagement->id,
        ]);

        // The frozen revision is still the publisher's — nothing about the engagement moved.
        $this->assertSame(
            (int) $customerAgreement->id,
            (int) $accepted->commissionAgreement->customer_agreement_id,
        );
    }

    // ── A meeting can actually be scheduled on it ─────────────────────────────────────────────

    public function test_a_meeting_can_be_scheduled_on_the_engagement_born_pipeline(): void
    {
        [$publisherUser, $publisher, $helperUser, $helper, $engagement] = $this->acceptedMarketplaceEngagement();
        unset($helperUser);

        /** @var SuchakPipeline $pipeline */
        $pipeline = SuchakPipeline::query()->where('collaboration_request_id', $engagement->id)->sole();

        $visit = $this->visitService()->scheduleVisit($pipeline, $publisherUser, [
            'scheduled_for' => now()->addDays(3)->toIso8601String(),
            'schedule_note' => 'Both families agreed on Sunday.',
            'helper_suchak_account_id' => $helper->id,
        ]);

        // THE WHOLE POINT: production had 20 pipelines and 0 meetings, and no marketplace
        // engagement could ever have produced one.
        $this->assertSame(1, SuchakVisitConfirmation::query()->count());
        $this->assertSame((int) $pipeline->id, (int) $visit->pipeline_id);
        $this->assertSame(SuchakVisitConfirmation::STATUS_SCHEDULED, $visit->visit_status);
        $this->assertSame(1, (int) $visit->meeting_sequence);
        $this->assertSame((int) $publisher->id, (int) $visit->suchak_account_id);
        $this->assertSame((int) $helper->id, (int) $visit->helper_suchak_account_id);
        // Copied straight off the pipeline, so it is null here too — and the NOT NULL that used to
        // stand on this column is what made the first marketplace meeting impossible.
        $this->assertNull($visit->request_id);

        // And the engine carries on: the arranging Suchak marks it complete.
        $completed = $this->visitService()->markSuchakCompleted($visit, $publisherUser, [
            'completion_note' => 'Meeting held at the girl\'s home.',
        ]);
        $this->assertSame(SuchakVisitConfirmation::STATUS_COMPLETED, $completed->visit_status);

        // The meeting is reachable FROM the engagement, through the pipeline and nothing else.
        $this->assertSame(
            (int) $engagement->id,
            (int) $completed->pipeline->collaboration_request_id,
        );
    }

    /**
     * The confirm/dispute door on a marketplace meeting must name the CUSTOMER's family, not the
     * helper's.
     *
     * `requesting_matrimony_profile_id` is a DIRECTION and on this path it holds the HELPER's
     * candidate, so the directional fallback would name the wrong family — which is why the
     * engagement is consulted before it.
     *
     * UPDATED 2026-08-06. This used to assert `customer_context_id` was NULL, because the only
     * route to a customer was a pipeline-keyed payment context and nothing in the marketplace flow
     * makes one. `scheduleVisit()` now falls back to the pipeline's representation, which owns at
     * most one customer, so the column is populated on this very path — and BOTH role sources now
     * agree. That agreement is the assertion worth holding: the family that confirms is the family
     * that is billed, resolved from one context rather than two.
     */
    public function test_the_confirm_door_on_an_engagement_meeting_names_the_customers_family(): void
    {
        [$publisherUser, $publisher, , $helper, $engagement] = $this->acceptedMarketplaceEngagement();
        unset($publisher);

        /** @var SuchakPipeline $pipeline */
        $pipeline = SuchakPipeline::query()->where('collaboration_request_id', $engagement->id)->sole();
        $visit = $this->visitService()->scheduleVisit($pipeline, $publisherUser, [
            'helper_suchak_account_id' => $helper->id,
        ]);

        // Resolved off the representation — no payment context was ever created on this pipeline.
        $this->assertNotNull($visit->customer_context_id);
        $this->assertNull($visit->payment_context_id);
        // Still null, and for the RIGHT reason now: this fixture's package quotes a post-marriage
        // fee and no per-meeting rate at all. Nothing was agreed for a meeting, so nothing is due.
        $this->assertNull($visit->fee_amount);

        $this->assertSame(
            (int) $engagement->customerOwnerMatrimonyProfileId(),
            (int) $this->visitService()->customerSideMatrimonyProfileId($visit),
        );
        $this->assertNotSame(
            (int) $visit->requesting_matrimony_profile_id,
            (int) $this->visitService()->customerSideMatrimonyProfileId($visit),
        );
    }

    // ── Idempotent ───────────────────────────────────────────────────────────────────────────

    public function test_accepting_twice_still_leaves_exactly_one_pipeline(): void
    {
        [$publisherUser, $publisher, , , $engagement] = $this->acceptedMarketplaceEngagement();

        $this->assertSame(1, SuchakPipeline::query()->count());

        // A second acceptance is refused by the lifecycle guard that already existed.
        try {
            $this->collaborationService()->acceptRequest($engagement->fresh(), $publisher, $publisherUser);
            $this->fail('An accepted collaboration was accepted a second time.');
        } catch (InvalidArgumentException) {
            // expected
        }

        // And a bare retry of the pipeline opener — a replayed job, a re-entered transaction —
        // returns the row that already exists instead of writing a second one.
        $again = $this->pipelineService()->openPipelineForEngagement(
            $engagement->fresh(),
            $publisherUser,
        );

        $this->assertSame(1, SuchakPipeline::query()->count());
        $this->assertSame(
            (int) SuchakPipeline::query()->sole()->id,
            (int) $again->id,
        );
        // One opening event, not two.
        $this->assertSame(1, SuchakPipelineEvent::query()
            ->where('event_type', SuchakPipelineEvent::EVENT_ENGAGEMENT_ACCEPTED)
            ->count());

        // The guarantee is the database's, not this method's.
        $unique = collect(Schema::getIndexes('suchak_pipelines'))
            ->first(fn (array $index): bool => $index['columns'] === ['collaboration_request_id']);
        $this->assertNotNull($unique, 'The engagement column on suchak_pipelines has no unique index.');
        $this->assertTrue((bool) $unique['unique']);
    }

    // ── The member-born pipeline is untouched ────────────────────────────────────────────────

    public function test_a_member_born_pipeline_is_unchanged(): void
    {
        [$suchakUser, $suchak] = $this->verifiedSuchakActor();
        unset($suchakUser);
        $representation = $this->helperCandidate($suchak);

        $memberProfile = $this->activeProfile('Amol Jadhav', null);
        $member = User::factory()->create();
        $memberProfile->forceFill(['user_id' => $member->id])->save();

        $created = $this->pipelineService()->createRequest(
            $member,
            $memberProfile->fresh(),
            $representation,
            ['message' => 'This match looks right for our family.'],
        );

        /** @var SuchakPipeline $pipeline */
        $pipeline = $created['pipeline'];

        // Every column the member path always wrote, still written the same way.
        $this->assertNotNull($pipeline->request_id);
        $this->assertSame((int) $created['request']->id, (int) $pipeline->request_id);
        $this->assertSame((int) $memberProfile->id, (int) $pipeline->requesting_matrimony_profile_id);
        $this->assertSame((int) $representation->matrimony_profile_id, (int) $pipeline->target_matrimony_profile_id);
        $this->assertSame((int) $representation->id, (int) $pipeline->representation_id);
        $this->assertSame((int) $suchak->id, (int) $pipeline->selected_suchak_account_id);
        $this->assertSame(SuchakPipeline::STATUS_PENDING, $pipeline->pipeline_status);
        $this->assertSame(SuchakPipeline::SLA_WITHIN, $pipeline->sla_status);

        // The reply clock still runs on this path — it is the member's SLA, and it is why the
        // engagement path leaves it null rather than reusing it.
        $this->assertNotNull($pipeline->attribution_locked_at);
        $this->assertNotNull($pipeline->lock_expires_at);
        $this->assertTrue($pipeline->lock_expires_at->greaterThan($pipeline->attribution_locked_at));

        // No engagement was invented on the other side.
        $this->assertNull($pipeline->collaboration_request_id);
        $this->assertFalse($pipeline->isEngagementBorn());

        // The opening event, the activity row and the chat injection all as before.
        $this->assertSame(
            SuchakPipelineEvent::EVENT_REQUEST_CREATED,
            $created['event']->event_type,
        );
        $this->assertSame(SuchakPipelineEvent::ACTOR_USER, $created['event']->actor_type);
        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $suchak->id,
            'action_type' => SuchakActivityLog::ACTION_USER_REQUEST_CREATED,
            'target_type' => 'suchak_profile_request',
            'target_id' => $created['request']->id,
        ]);
        $this->assertNotNull($created['request']->request_chat_message_id);

        // And the SLA sweep still expires it, exactly as it always did.
        $pipeline->forceFill(['lock_expires_at' => now()->subHour()])->save();
        $this->pipelineService()->expireDuePipelinesForAccount($suchak);
        $this->assertSame(SuchakPipeline::STATUS_EXPIRED, $pipeline->fresh()->pipeline_status);
    }

    /**
     * The sweep is scoped by an OPEN member request, so an engagement-born pipeline — which has no
     * request at all — is never in its reach. Without this the marketplace's funnel entry could be
     * expired by a clock that was never running on it.
     */
    public function test_the_member_sla_sweep_never_touches_an_engagement_born_pipeline(): void
    {
        [, $publisher, , , $engagement] = $this->acceptedMarketplaceEngagement();

        /** @var SuchakPipeline $pipeline */
        $pipeline = SuchakPipeline::query()->where('collaboration_request_id', $engagement->id)->sole();

        $this->travel(60)->days();
        $this->pipelineService()->expireDuePipelinesForAccount($publisher);

        $this->assertSame(SuchakPipeline::STATUS_PENDING, $pipeline->fresh()->pipeline_status);
        $this->assertSame(SuchakPipeline::SLA_WITHIN, $pipeline->fresh()->sla_status);
    }

    // ── Nothing is opened on a role nobody recorded ──────────────────────────────────────────

    public function test_a_direct_collaboration_with_no_recorded_customer_owner_opens_no_pipeline(): void
    {
        [$ownerUser, $owner] = $this->verifiedSuchakActor();
        [$targetUser, $target] = $this->verifiedSuchakActor();

        $direct = $this->collaborationService()->createRequest(
            $owner,
            $ownerUser,
            $this->helperCandidate($owner),
            $this->helperCandidate($target),
        )['request'];

        $accepted = $this->collaborationService()->acceptRequest($direct, $target, $targetUser);

        // `customer_owner_side` defaults to `target`, so reading it alone would have said "yes".
        // The recorded fact is its partner column, and it is absent — nobody has said whose
        // customer this is or under which terms, so nothing may be charged and nothing is opened.
        $this->assertSame(SuchakCollaborationRequest::SIDE_TARGET, $accepted->customer_owner_side);
        $this->assertFalse($accepted->hasRecordedCustomerOwner());
        $this->assertSame(0, SuchakPipeline::query()->count());

        // Acceptance itself is not blocked by the missing role.
        $this->assertSame(SuchakCollaborationRequest::STATUS_ACCEPTED, $accepted->status);
    }

    public function test_a_direct_collaboration_opens_its_pipeline_once_the_owner_binds_his_agreement(): void
    {
        [$ownerUser, $owner] = $this->verifiedSuchakActor();
        [$targetUser, $target] = $this->verifiedSuchakActor();
        [$targetRepresentation, $targetAgreement] = $this->publishableCandidate($target, $targetUser);

        $direct = $this->collaborationService()->createRequest(
            $owner,
            $ownerUser,
            $this->helperCandidate($owner),
            $targetRepresentation,
        )['request'];

        $this->collaborationService()->linkCustomerAgreement($direct, $target, $targetUser, $targetAgreement);
        $accepted = $this->collaborationService()->acceptRequest($direct->fresh(), $target, $targetUser);

        $this->assertTrue($accepted->hasRecordedCustomerOwner());

        /** @var SuchakPipeline $pipeline */
        $pipeline = SuchakPipeline::query()->sole();
        $this->assertSame((int) $direct->id, (int) $pipeline->collaboration_request_id);
        // The customer-owning side here is the TARGET, and the pipeline names him — not the
        // Suchak who happens to sit in the `requesting` slot.
        $this->assertSame((int) $target->id, (int) $pipeline->selected_suchak_account_id);
        $this->assertSame((int) $targetRepresentation->id, (int) $pipeline->representation_id);
    }

    // ── A pipeline must always say where it came from ────────────────────────────────────────

    public function test_a_pipeline_cannot_name_two_origins_or_none(): void
    {
        [$publisherUser, , , , $engagement] = $this->acceptedMarketplaceEngagement();
        unset($publisherUser);

        /** @var SuchakPipeline $pipeline */
        $pipeline = SuchakPipeline::query()->sole();

        try {
            SuchakPipeline::query()->create([
                'target_matrimony_profile_id' => $pipeline->target_matrimony_profile_id,
                'requesting_matrimony_profile_id' => $pipeline->requesting_matrimony_profile_id,
                'selected_suchak_account_id' => $pipeline->selected_suchak_account_id,
                'representation_id' => $pipeline->representation_id,
                'attribution_locked_at' => now(),
            ]);
            $this->fail('A pipeline was created that names no origin at all.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('names neither a member request nor an engagement', $exception->getMessage());
        }

        unset($engagement);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────────────────────

    /**
     * @return array{0: User, 1: SuchakAccount, 2: User, 3: SuchakAccount, 4: SuchakCollaborationRequest}
     */
    private function acceptedMarketplaceEngagement(): array
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [$publisherRepresentation] = $this->publishableCandidate($publisher, $publisherUser);

        $challenge = $this->challengeService()->publish(
            $publisher,
            $publisherUser,
            $publisherRepresentation,
            $this->percentTerms(30),
        );
        $engagement = $this->challengeService()->proposeCandidate(
            $challenge,
            $helper,
            $helperUser,
            $this->helperCandidate($helper),
        )['request'];

        $this->collaborationService()->acceptRequest($engagement, $publisher, $publisherUser);

        return [$publisherUser, $publisher, $helperUser, $helper, $engagement->fresh(['commissionAgreement'])];
    }

    private function challengeService(): SuchakMarketplaceChallengeService
    {
        return $this->app->make(SuchakMarketplaceChallengeService::class);
    }

    private function collaborationService(): SuchakCollaborationService
    {
        return $this->app->make(SuchakCollaborationService::class);
    }

    private function pipelineService(): SuchakRequestPipelineService
    {
        return $this->app->make(SuchakRequestPipelineService::class);
    }

    private function visitService(): SuchakVisitConfirmationService
    {
        return $this->app->make(SuchakVisitConfirmationService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function percentTerms(float $percent): array
    {
        return [
            'declared_share_type' => SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT,
            'declared_share_percent' => $percent,
        ];
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
     * @return array{0: SuchakProfileRepresentation, 1: SuchakCustomerAgreement}
     */
    private function publishableCandidate(SuchakAccount $account, User $user): array
    {
        $profile = $this->activeProfile('Sunita Gaikwad', null);

        /** @var SuchakProfileRepresentation $representation */
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        /** @var SuchakCustomerContext $context */
        $context = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'created_by_user_id' => $user->id,
            'opened_at' => now(),
        ]);

        /** @var SuchakServicePackage $package */
        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'package_name' => 'Engagement pipeline fixture '.$representation->id,
            'price_amount' => '25000',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $user->id,
            'published_at' => now(),
            'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_FIXED,
            'post_marriage_fee_amount' => '100000',
        ]);

        /** @var SuchakCustomerAgreement $agreement */
        $agreement = SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $context->id,
            'service_package_id' => $package->id,
            'agreement_revision' => 1,
            'terms_status' => SuchakCustomerAgreement::TERMS_ACCEPTED,
            'terms_policy_mode' => SuchakCustomerAgreement::POLICY_STRICT,
            'agreement_snapshot_hash' => hash('sha256', 'engagement-pipeline-'.$package->id),
            'package_name' => $package->package_name,
            'price_amount' => '25000',
            'currency' => 'INR',
            'agreement_title' => 'Accepted terms revision 1',
            'created_by_user_id' => $user->id,
            'accepted_by_user_id' => $user->id,
            'accepted_at' => now(),
        ]);

        return [$representation->fresh(), $agreement->fresh(['customerContext'])];
    }

    private function helperCandidate(SuchakAccount $account): SuchakProfileRepresentation
    {
        $profile = $this->activeProfile('Rahul Kadam', null);

        /** @var SuchakProfileRepresentation $representation */
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        return $representation->fresh(['suchakAccount', 'matrimonyProfile.gender']);
    }

    private function activeProfile(string $fullName, ?int $genderId): MatrimonyProfile
    {
        $state = $this->address('Maharashtra', 'state', 1, null);
        $district = $this->address('Pune', 'district', 2, $state);
        $taluka = $this->address('Shirur', 'taluka', 3, $district);
        $village = $this->address('Ranjangaon', 'village', 4, $taluka, 'rural');

        $profile = MatrimonyProfile::factory()->create(array_filter([
            'full_name' => $fullName,
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'gender_id' => $genderId,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], static fn ($value): bool => $value !== null));

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $village]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $village, null, true, false);
        }

        $profile->update([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        return $profile->fresh();
    }

    private function address(string $name, string $hierarchy, int $level, ?int $parent, ?string $tag = null): int
    {
        return DB::table('addresses')->insertGetId(array_filter([
            'name' => $name,
            'slug' => strtolower($name).'-'.$hierarchy.'-'.uniqid('', true),
            'hierarchy' => $hierarchy,
            'level' => $level,
            'parent_id' => $parent,
            'tag' => $tag,
            'created_at' => now(),
            'updated_at' => now(),
        ], static fn ($v): bool => $v !== null));
    }
}
