<?php

namespace Tests\Feature\Suchak;

use App\Jobs\ParseIntakeJob;
use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\ProfileVisibilitySetting;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakConsent;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPipelineEvent;
use App\Models\SuchakPlan;
use App\Models\SuchakPlanFeature;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\SuchakProfileUpdateSuggestion;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAccountLifecycleService;
use App\Modules\Suchak\Services\SuchakBillingCatalogService;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use App\Modules\Suchak\Services\SuchakConsentService;
use App\Modules\Suchak\Services\SuchakCrmLedgerService;
use App\Modules\Suchak\Services\SuchakPdfQrFoundationService;
use App\Modules\Suchak\Services\SuchakProfileUpdateSuggestionService;
use App\Modules\Suchak\Services\SuchakRepresentationService;
use App\Modules\Suchak\Services\SuchakRepresentationShutdownService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SuchakIntegratedQaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
        AdminSetting::setValue('admin_bypass_mode', '1');
    }

    public function test_phase_6_integrated_suchak_flow_preserves_ssot_boundaries(): void
    {
        Bus::fake();

        $admin = User::factory()->create(['is_admin' => true]);
        [$suchakUser, $suchakAccount] = $this->pendingSuchakAccount();

        app(SuchakAccountLifecycleService::class)->approve(
            $suchakAccount,
            $admin,
            'Day-17 integrated QA admin verification.',
        );
        $suchakAccount = $suchakAccount->fresh();
        $this->assertSame(SuchakAccount::VERIFICATION_VERIFIED, $suchakAccount->verification_status);
        $this->makePublicSuchak($suchakAccount);

        $candidateUser = User::factory()->create();
        $candidateProfile = $this->activeProfile([
            'user_id' => $candidateUser->id,
            'full_name' => 'Integrated Sensitive Candidate',
            'date_of_birth' => now()->subYears(27)->toDateString(),
            'highest_education' => 'B.Com',
            'address_line' => 'Integrated Secret Lane',
        ]);
        $this->insertPrivateContactFixture($candidateProfile);
        $this->setContactRoutingMode($candidateProfile, ProfileVisibilitySetting::CONTACT_ROUTING_SUCHAK_ONLY);

        $sourceLink = $this->createSourceLinkThroughSuchakIntakeRoute($suchakUser, $suchakAccount, $candidateProfile);
        $representation = app(SuchakRepresentationService::class)->createPendingFromSourceLink(
            $suchakAccount,
            $suchakUser,
            $sourceLink,
            $candidateProfile,
            SuchakProfileRepresentation::MODE_MATCHED_EXISTING_PROFILE,
        );

        $consentService = app(SuchakConsentService::class);
        $consent = $consentService->requestConsent($representation, $suchakUser, [
            'consent_mobile_number' => '9876543210',
        ])['consent'];
        $acceptedConsent = $consentService->verifyOtpAndAccept(
            $consentService->recordOtpSent($consent, '123456', $suchakUser),
            '123456',
            [
                'consent_given_by_name' => 'Candidate Parent',
                'relationship_to_candidate' => 'father',
                'consent_mobile_number' => '9876543210',
            ],
        );
        $representation = $representation->fresh(['suchakAccount', 'matrimonyProfile']);
        $this->assertSame(SuchakProfileRepresentation::STATUS_ACTIVE, $representation->representation_status);
        $this->assertDatabaseMissing('suchak_consents', ['otp_hash' => '123456']);

        $pdfResult = app(SuchakPdfQrFoundationService::class)->createGovernedBiodataPdfExport($representation, $suchakUser);
        $this->assertDatabaseMissing('suchak_qr_tokens', ['token_hash' => $pdfResult['raw_qr_token']]);
        $qrScan = app(SuchakPdfQrFoundationService::class)->scanQrToken($pdfResult['raw_qr_token']);
        $this->assertMaskedPayloadHasNoPrivateCandidateData($qrScan['candidate_summary']);

        $viewer = User::factory()->create(['is_admin' => true]);
        $viewerProfile = $this->activeProfile([
            'user_id' => $viewer->id,
            'full_name' => 'Integrated Viewer',
        ]);

        $profileResponse = $this->actingAs($viewer)->get(route('matrimony.profile.show', $candidateProfile));
        $profileResponse->assertOk();
        $profileResponse->assertSee(route('matrimony.profile.suchak-requests.store', [$candidateProfile, $representation]), false);
        $profileResponse->assertDontSee('9876543210', false);

        $requestResponse = $this->actingAs($viewer)->post(
            route('matrimony.profile.suchak-requests.store', [$candidateProfile, $representation]),
            ['message' => 'Please route this through Suchak.'],
        );
        $requestResponse->assertRedirect();

        $profileRequest = SuchakProfileRequest::query()->firstOrFail();
        $pipeline = $profileRequest->pipeline()->firstOrFail();
        $this->assertSame($viewerProfile->id, $profileRequest->requesting_matrimony_profile_id);
        $this->assertSame($candidateProfile->id, $pipeline->target_matrimony_profile_id);
        $this->assertDatabaseHas('suchak_pipeline_events', [
            'pipeline_id' => $pipeline->id,
            'event_type' => SuchakPipelineEvent::EVENT_REQUEST_CREATED,
        ]);

        [$targetSuchakUser, $targetSuchakAccount, $targetRepresentation, $targetProfile] = $this->activePublicRepresentationFixture([
            'full_name' => 'Search Private Candidate',
            'address_line' => 'Search Secret Lane',
        ]);
        $this->insertPrivateContactFixture($targetProfile, '9765432109');

        $searchResponse = $this->actingAs($suchakUser)->get(route('suchak.search.index'));
        $searchResponse->assertOk();
        $searchResponse->assertSee('Request collaboration', false);
        $searchResponse->assertDontSee('9765432109', false);
        $searchResponse->assertDontSee('Search Secret Lane', false);

        $collaborationResponse = $this->actingAs($suchakUser)->post(route('suchak.collaborations.store'), [
            'requesting_representation_id' => $representation->id,
            'target_representation_id' => $targetRepresentation->id,
            'message' => 'Integrated QA collaboration request.',
            'commission_ack' => '1',
        ]);
        $collaborationResponse->assertRedirect();

        $collaboration = SuchakCollaborationRequest::query()->firstOrFail();
        $this->actingAs($targetSuchakUser)
            ->post(route('suchak.collaborations.accept', $collaboration))
            ->assertRedirect();
        $this->assertTrue(app(SuchakCollaborationService::class)->canExchangeContact($collaboration->fresh()));

        $ledgerEntry = app(SuchakCrmLedgerService::class)->createLedgerEntry(
            $suchakAccount,
            $suchakUser,
            $candidateProfile,
            [
                'pipeline_id' => $pipeline->id,
                'collaboration_request_id' => $collaboration->id,
                'entry_type' => SuchakLedgerEntry::TYPE_SUCCESS_FEE_EXPECTED,
                'amount' => '2500',
                'note' => 'Integrated QA ledger note without contact details.',
            ],
        );
        $this->assertSame('2500.00', (string) $ledgerEntry->amount);

        $suggestionService = app(SuchakProfileUpdateSuggestionService::class);
        $suggestion = $suggestionService->createCoreFieldSuggestion(
            $suchakAccount,
            $suchakUser,
            $representation->fresh(),
            'highest_education',
            'M.Com',
        );
        $withSuggestionOtp = $suggestionService->recordOtpSent($suggestion, '654321', $suchakUser);
        $appliedSuggestion = $suggestionService->verifyCandidateOtpAndApply($withSuggestionOtp, '654321', $candidateUser);
        $this->assertSame(SuchakProfileUpdateSuggestion::STATUS_APPLIED, $appliedSuggestion->suggestion_status);
        $this->assertSame('M.Com', $candidateProfile->fresh()->highest_education);
        $this->assertDatabaseHas('profile_change_history', [
            'profile_id' => $candidateProfile->id,
            'field_name' => 'highest_education',
            'old_value' => 'B.Com',
            'new_value' => 'M.Com',
        ]);
        $this->assertDatabaseMissing('suchak_profile_update_suggestions', ['otp_hash' => '654321']);

        $plan = SuchakPlan::factory()->create([
            'name' => 'Suchak Enterprise',
            'slug' => 'suchak-enterprise-integrated-qa',
            'price_amount' => null,
            'currency' => null,
            'is_active' => true,
            'is_visible' => true,
        ]);
        SuchakPlanFeature::factory()->create([
            'suchak_plan_id' => $plan->id,
            'feature_key' => SuchakPlanFeature::FEATURE_ACTIVE_PROFILE_LIMIT,
            'value_type' => SuchakPlanFeature::TYPE_INTEGER,
            'feature_value' => '200',
            'is_enabled' => true,
        ]);
        app(SuchakBillingCatalogService::class)->assignManualSubscription(
            $suchakAccount,
            $plan,
            $admin,
            'Integrated QA manual Suchak subscription.',
        );
        $this->assertSame(200, app(SuchakBillingCatalogService::class)->currentFeatureValue(
            $suchakAccount,
            SuchakPlanFeature::FEATURE_ACTIVE_PROFILE_LIMIT,
        ));
        $this->assertSame(0, Plan::query()->count());
        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(0, DB::table('payments')->count());

        DB::table('matrimony_profiles')
            ->where('id', $targetProfile->id)
            ->update(['lifecycle_state' => 'archived', 'updated_at' => now()]);
        $shutdown = app(SuchakRepresentationShutdownService::class)->markCandidateDeactivated($targetProfile->fresh());
        $this->assertCount(1, $shutdown);
        $this->assertSame(SuchakProfileRepresentation::STATUS_CANDIDATE_DEACTIVATED, $targetRepresentation->fresh()->representation_status);

        $revoked = $consentService->revoke($acceptedConsent->fresh(), $suchakUser, 'Integrated QA consent revocation.');
        $this->assertSame(SuchakConsent::STATUS_REVOKED, $revoked->consent_status);
        $this->assertSame(SuchakProfileRepresentation::STATUS_REVOKED, $representation->fresh()->representation_status);

        $this->assertDatabaseHas('suchak_activity_logs', ['action_type' => SuchakActivityLog::ACTION_ADMIN_AUDIT_LINKED]);
        $this->assertDatabaseHas('suchak_activity_logs', ['action_type' => SuchakActivityLog::ACTION_SOURCE_LINK_CREATED]);
        $this->assertDatabaseHas('suchak_activity_logs', ['action_type' => SuchakActivityLog::ACTION_REPRESENTATION_CREATED]);
        $this->assertDatabaseHas('suchak_activity_logs', ['action_type' => SuchakActivityLog::ACTION_CONSENT_VERIFIED]);
        $this->assertDatabaseHas('suchak_activity_logs', ['action_type' => SuchakActivityLog::ACTION_PDF_GENERATED]);
        $this->assertDatabaseHas('suchak_activity_logs', ['action_type' => SuchakActivityLog::ACTION_QR_SCANNED]);
        $this->assertDatabaseHas('suchak_activity_logs', ['action_type' => SuchakActivityLog::ACTION_USER_REQUEST_CREATED]);
        $this->assertDatabaseHas('suchak_activity_logs', ['action_type' => SuchakActivityLog::ACTION_COLLABORATION_REQUEST_ACCEPTED]);
        $this->assertDatabaseHas('suchak_activity_logs', ['action_type' => SuchakActivityLog::ACTION_LEDGER_ENTRY_CREATED]);
        $this->assertDatabaseHas('suchak_activity_logs', ['action_type' => SuchakActivityLog::ACTION_PROFILE_UPDATE_SUGGESTION_APPLIED]);
        $this->assertDatabaseHas('suchak_activity_logs', ['action_type' => SuchakActivityLog::ACTION_BILLING_LIMIT_CHANGED]);
        $this->assertDatabaseHas('suchak_activity_logs', ['action_type' => SuchakActivityLog::ACTION_REPRESENTATION_CANDIDATE_DEACTIVATED]);
        $this->assertDatabaseHas('suchak_activity_logs', ['action_type' => SuchakActivityLog::ACTION_CONSENT_REVOKED]);

        $this->assertSame(0, $this->sensitiveActivityLeakCount([
            '9876543210',
            '9765432109',
            'Integrated Secret Lane',
            'Search Secret Lane',
        ]));
    }

    public function test_phase_6_suchak_route_and_scope_boundaries_remain_explicit(): void
    {
        foreach ([
            'suchak.register.info',
            'suchak.apply.create',
            'suchak.apply.store',
            'suchak.dashboard',
            'suchak.intakes.create',
            'suchak.intakes.store',
            'suchak.search.index',
            'suchak.representations.exports.store',
            'suchak.representations.profile-update-suggestions.store',
            'suchak.qr.show',
            'suchak.collaborations.store',
            'suchak.collaborations.accept',
            'suchak.collaborations.reject',
            'admin.suchak.dashboard',
            'admin.suchak.accounts.index',
            'admin.suchak.accounts.show',
            'admin.suchak.accounts.approve',
            'admin.suchak.accounts.reject',
            'admin.suchak.accounts.suspend',
            'matrimony.profile.suchak-requests.store',
        ] as $routeName) {
            $this->assertTrue(Route::has($routeName), $routeName);
        }

        $this->assertFalse(Schema::hasTable('suchak_disputes'));
        $this->assertFalse(Route::has('suchak.payments.start'));
        $this->assertFalse(Route::has('suchak.subscriptions.payu.start'));
    }

    /**
     * @return array{0: User, 1: SuchakAccount}
     */
    private function pendingSuchakAccount(): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'verified_at' => null,
        ]);

        return [$user, $account];
    }

    /**
     * @param  array<string, mixed>  $profileAttributes
     * @return array{0: User, 1: SuchakAccount, 2: SuchakProfileRepresentation, 3: MatrimonyProfile}
     */
    private function activePublicRepresentationFixture(array $profileAttributes = []): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        $profile = $this->activeProfile($profileAttributes);
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

        return [$user, $account, $representation, $profile];
    }

    private function makePublicSuchak(SuchakAccount $account): void
    {
        SuchakAccount::query()
            ->whereKey($account->id)
            ->update([
                'public_status' => SuchakAccount::PUBLIC_ACTIVE,
                'updated_at' => now(),
            ]);

        $account->refresh();
    }

    private function createSourceLinkThroughSuchakIntakeRoute(
        User $suchakUser,
        SuchakAccount $account,
        MatrimonyProfile $profile,
    ): SuchakBiodataIntakeLink {
        $this->actingAs($suchakUser)
            ->get(route('suchak.intakes.create'))
            ->assertOk();

        $this->actingAs($suchakUser)
            ->post(route('suchak.intakes.store'), [
                'raw_text' => 'Integrated QA biodata upload through Suchak intake route.',
            ])
            ->assertRedirect();

        $intake = BiodataIntake::query()
            ->where('uploaded_by', $suchakUser->id)
            ->latest('id')
            ->firstOrFail();

        Bus::assertDispatched(ParseIntakeJob::class);

        $sourceLink = SuchakBiodataIntakeLink::query()
            ->where('suchak_account_id', $account->id)
            ->where('biodata_intake_id', $intake->id)
            ->firstOrFail();

        SuchakBiodataIntakeLink::query()
            ->whereKey($sourceLink->id)
            ->update([
                'matrimony_profile_id' => $profile->id,
                'source_status' => SuchakBiodataIntakeLink::STATUS_LINKED_TO_EXISTING_PROFILE,
                'updated_at' => now(),
            ]);

        return $sourceLink->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeProfile(array $attributes = []): MatrimonyProfile
    {
        $targetLifecycleState = (string) ($attributes['lifecycle_state'] ?? 'active');
        $attributes['lifecycle_state'] = 'draft';

        $profile = MatrimonyProfile::factory()->create(array_merge([
            'full_name' => 'Integrated Candidate',
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'height_cm' => 164,
            'highest_education' => 'Generic Education',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], $attributes));

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

    private function insertPrivateContactFixture(MatrimonyProfile $profile, string $phoneNumber = '9876543210'): void
    {
        $contactRow = [
            'profile_id' => $profile->id,
            'contact_name' => 'Integrated Private Contact',
            'phone_number' => $phoneNumber,
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

    private function setContactRoutingMode(MatrimonyProfile $profile, string $mode): void
    {
        DB::table('profile_visibility_settings')->updateOrInsert(
            ['profile_id' => $profile->id],
            [
                'visibility_scope' => 'public',
                'show_photo_to' => 'all',
                'show_contact_to' => 'everyone',
                'hide_from_blocked_users' => true,
                'contact_visibility_json' => null,
                'contact_routing_mode' => ProfileVisibilitySetting::normalizeContactRoutingMode($mode),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertMaskedPayloadHasNoPrivateCandidateData(array $payload): void
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Integrated Sensitive Candidate', $encoded);
        $this->assertStringNotContainsString('9876543210', $encoded);
        $this->assertStringNotContainsString('Integrated Secret Lane', $encoded);
        $this->assertTrue((bool) ($payload['contact']['is_masked'] ?? false));
        $this->assertFalse((bool) ($payload['visibility']['contact_reveal_allowed'] ?? true));
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function sensitiveActivityLeakCount(array $needles): int
    {
        return SuchakActivityLog::query()
            ->get()
            ->filter(function (SuchakActivityLog $activity) use ($needles): bool {
                $encoded = json_encode($activity->metadata_json ?? [], JSON_THROW_ON_ERROR);

                foreach ($needles as $needle) {
                    if (str_contains($encoded, $needle)) {
                        return true;
                    }
                }

                return false;
            })
            ->count();
    }
}
