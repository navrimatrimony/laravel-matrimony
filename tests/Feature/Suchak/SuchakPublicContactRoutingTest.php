<?php

namespace Tests\Feature\Suchak;

use App\Models\City;
use App\Models\AdminSetting;
use App\Models\MatrimonyProfile;
use App\Models\ProfileVisibilitySetting;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakConsent;
use App\Models\SuchakPipelineEvent;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\User;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SuchakPublicContactRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
        AdminSetting::setValue('admin_bypass_mode', '1');
    }

    public function test_profile_show_default_routing_shows_suchak_option_and_keeps_direct_contact_visible(): void
    {
        [$viewer, $viewerProfile, $representation, $targetProfile] = $this->validRoutingFixture();

        $response = $this->actingAs($viewer)->get(route('matrimony.profile.show', $targetProfile));

        $response->assertOk();
        $this->assertTrue(Schema::hasColumn('profile_visibility_settings', 'contact_routing_mode'));
        $response->assertSee(__('profile.suchak_contact_title'), false);
        $response->assertSee(route('matrimony.profile.suchak-requests.store', [$targetProfile, $representation]), false);
        $response->assertSee('9876543210', false);
        $this->assertDatabaseHas('profile_visibility_settings', [
            'profile_id' => $targetProfile->id,
            'contact_routing_mode' => ProfileVisibilitySetting::CONTACT_ROUTING_DIRECT_AND_SUCHAK,
        ]);
        $this->assertSame($viewer->id, $viewerProfile->user_id);
    }

    public function test_profile_show_suchak_only_routing_hides_direct_contact_actions(): void
    {
        [$viewer, $viewerProfile, $representation, $targetProfile] = $this->validRoutingFixture([
            'contact_routing_mode' => ProfileVisibilitySetting::CONTACT_ROUTING_SUCHAK_ONLY,
        ]);

        $response = $this->actingAs($viewer)->get(route('matrimony.profile.show', $targetProfile));

        $response->assertOk();
        $response->assertSee(__('profile.suchak_contact_title'), false);
        $response->assertSee(route('matrimony.profile.suchak-requests.store', [$targetProfile, $representation]), false);
        $response->assertDontSee(route('matrimony.profile.contact-reveal', $targetProfile), false);
        $response->assertDontSee(route('contact-requests.store', $targetProfile), false);
        $response->assertDontSee('9876543210', false);
        $this->assertSame($viewer->id, $viewerProfile->user_id);
    }

    public function test_manual_suchak_created_profile_routes_contact_through_suchak_even_with_default_mode(): void
    {
        [$viewer, $viewerProfile, $representation, $targetProfile] = $this->validRoutingFixture([
            'representation' => [
                'representation_mode' => SuchakProfileRepresentation::MODE_MANUAL_FORM_BY_SUCHAK,
            ],
        ]);

        $response = $this->actingAs($viewer)->get(route('matrimony.profile.show', $targetProfile));

        $response->assertOk();
        $response->assertSee(__('profile.suchak_contact_title'), false);
        $response->assertSee(route('matrimony.profile.suchak-requests.store', [$targetProfile, $representation]), false);
        $response->assertDontSee(route('matrimony.profile.contact-reveal', $targetProfile), false);
        $response->assertDontSee(route('contact-requests.store', $targetProfile), false);
        $response->assertDontSee('9876543210', false);

        $this
            ->actingAs($viewer)
            ->post(route('contact-requests.store', $targetProfile), [
                'reason' => 'talk_to_family',
                'requested_scopes' => ['phone'],
            ])
            ->assertForbidden();

        $this
            ->actingAs($viewer)
            ->post(route('matrimony.profile.contact-reveal', $targetProfile))
            ->assertForbidden();

        $this->assertSame($viewer->id, $viewerProfile->user_id);
    }

    public function test_manual_suchak_created_profile_appears_in_member_search_results(): void
    {
        [$viewer, $viewerProfile, $representation, $targetProfile] = $this->validRoutingFixture([
            'target_profile' => [
                'full_name' => 'Manual Search Candidate',
                'visibility_override' => true,
            ],
            'representation' => [
                'representation_mode' => SuchakProfileRepresentation::MODE_MANUAL_FORM_BY_SUCHAK,
            ],
        ]);

        $response = $this->actingAs($viewer)->get(route('matrimony.profiles.index', [
            'sort' => 'latest',
            'per_page' => 50,
        ]));

        $response->assertOk();
        $response->assertSee('Manual Search Candidate', false);
        $response->assertSee(route('matrimony.profile.show', $targetProfile), false);
        $this->assertSame($viewer->id, $viewerProfile->user_id);
        $this->assertSame($targetProfile->id, $representation->matrimony_profile_id);
    }

    public function test_manual_suchak_created_profile_waits_for_routable_representation_before_member_search(): void
    {
        [$viewer, $viewerProfile] = $this->validRoutingFixture([
            'target_profile' => [
                'full_name' => 'Manual Pending Candidate',
                'visibility_override' => true,
            ],
            'representation' => [
                'representation_mode' => SuchakProfileRepresentation::MODE_MANUAL_FORM_BY_SUCHAK,
                'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
                'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
                'first_verified_consent_at' => null,
                'consent_verified_at' => null,
                'consent_valid_until' => null,
            ],
        ]);

        $response = $this->actingAs($viewer)->get(route('matrimony.profiles.index', [
            'sort' => 'latest',
            'per_page' => 50,
        ]));

        $response->assertOk();
        $response->assertDontSee('Manual Pending Candidate', false);
        $this->assertSame($viewer->id, $viewerProfile->user_id);
    }

    public function test_public_suchak_request_post_creates_day_9_pipeline_trace(): void
    {
        [$viewer, $viewerProfile, $representation, $targetProfile] = $this->validRoutingFixture();

        $response = $this
            ->actingAs($viewer)
            ->post(route('matrimony.profile.suchak-requests.store', [$targetProfile, $representation]), [
                'message' => 'Please route this contact request through Suchak.',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', __('profile.suchak_contact_request_success'));

        $request = SuchakProfileRequest::query()->firstOrFail();

        $this->assertSame($viewer->id, $request->requesting_user_id);
        $this->assertSame($viewerProfile->id, $request->requesting_matrimony_profile_id);
        $this->assertSame($targetProfile->id, $request->target_matrimony_profile_id);
        $this->assertSame($representation->id, $request->representation_id);

        $this->assertDatabaseHas('suchak_pipelines', [
            'request_id' => $request->id,
            'target_matrimony_profile_id' => $targetProfile->id,
            'requesting_matrimony_profile_id' => $viewerProfile->id,
            'representation_id' => $representation->id,
        ]);
        $this->assertDatabaseHas('suchak_pipeline_events', [
            'event_type' => SuchakPipelineEvent::EVENT_REQUEST_CREATED,
            'actor_type' => SuchakPipelineEvent::ACTOR_USER,
            'actor_id' => $viewer->id,
        ]);
        $this->assertDatabaseHas('suchak_activity_logs', [
            'action_type' => SuchakActivityLog::ACTION_USER_REQUEST_CREATED,
            'target_type' => 'suchak_profile_request',
            'target_id' => $request->id,
            'matrimony_profile_id' => $targetProfile->id,
        ]);
    }

    public function test_invalid_revoked_or_expired_suchak_representation_is_not_publicly_routable(): void
    {
        $fixture = $this->validRoutingFixture([
            'representation' => [
                'representation_status' => SuchakProfileRepresentation::STATUS_REVOKED,
                'consent_status' => SuchakProfileRepresentation::CONSENT_REVOKED,
                'revoked_at' => now(),
            ],
        ]);
        $viewer = $fixture[0];
        $representation = $fixture[2];
        $targetProfile = $fixture[3];

        $response = $this->actingAs($viewer)->get(route('matrimony.profile.show', $targetProfile));

        $response->assertOk();
        $response->assertDontSee(route('matrimony.profile.suchak-requests.store', [$targetProfile, $representation]), false);

        $this
            ->actingAs($viewer)
            ->post(route('matrimony.profile.suchak-requests.store', [$targetProfile, $representation]))
            ->assertSessionHas('error', 'Suchak request requires active representation with valid consent.');
    }

    public function test_existing_contact_paths_remain_available_for_direct_and_suchak_mode(): void
    {
        $fixture = $this->validRoutingFixture();
        $viewer = $fixture[0];
        $targetProfile = $fixture[3];

        $contactRequestResponse = $this
            ->actingAs($viewer)
            ->post(route('contact-requests.store', $targetProfile), [
                'reason' => 'talk_to_family',
                'requested_scopes' => ['phone'],
            ]);
        $this->assertNotSame(403, $contactRequestResponse->getStatusCode());

        $this
            ->actingAs($viewer)
            ->post(route('matrimony.profile.contact-reveal', $targetProfile))
            ->assertRedirect();

        $mediatorResponse = $this
            ->actingAs($viewer)
            ->post(route('matrimony.profile.mediator-request', $targetProfile));
        $this->assertNotSame(403, $mediatorResponse->getStatusCode());
    }

    public function test_existing_contact_paths_are_blocked_only_for_suchak_only_profile(): void
    {
        $fixture = $this->validRoutingFixture([
            'contact_routing_mode' => ProfileVisibilitySetting::CONTACT_ROUTING_SUCHAK_ONLY,
        ]);
        $viewer = $fixture[0];
        $targetProfile = $fixture[3];

        $this
            ->actingAs($viewer)
            ->post(route('contact-requests.store', $targetProfile), [
                'reason' => 'talk_to_family',
                'requested_scopes' => ['phone'],
            ])
            ->assertForbidden();

        $this
            ->actingAs($viewer)
            ->post(route('matrimony.profile.contact-reveal', $targetProfile))
            ->assertForbidden();

        $this
            ->actingAs($viewer)
            ->post(route('matrimony.profile.mediator-request', $targetProfile))
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: MatrimonyProfile, 2: SuchakProfileRepresentation, 3: MatrimonyProfile}
     */
    private function validRoutingFixture(array $overrides = []): array
    {
        $viewer = User::factory()->create(['is_admin' => true]);
        $viewerProfile = $this->createProfileWithOptionalActiveResidence(array_merge([
            'user_id' => $viewer->id,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ], $overrides['viewer_profile'] ?? []));

        $suchakUser = User::factory()->create();
        $account = SuchakAccount::factory()->create(array_merge([
            'user_id' => $suchakUser->id,
            'suchak_name' => 'Verified Suchak',
            'office_name' => 'Suchak Office',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ], $overrides['account'] ?? []));

        $targetProfile = $this->createProfileWithOptionalActiveResidence(array_merge([
            'full_name' => 'Sensitive Candidate',
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ], $overrides['target_profile'] ?? []));

        $this->insertPrivateContactFixture($targetProfile);
        $this->setContactRoutingMode(
            $targetProfile,
            $overrides['contact_routing_mode'] ?? ProfileVisibilitySetting::CONTACT_ROUTING_DIRECT_AND_SUCHAK,
        );

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

        return [$viewer, $viewerProfile, $representation, $targetProfile];
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
            ]
        );
    }
}
