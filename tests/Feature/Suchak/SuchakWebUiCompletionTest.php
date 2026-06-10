<?php

namespace Tests\Feature\Suchak;

use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakBiodataExport;
use App\Models\SuchakConsent;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileUpdateSuggestion;
use App\Models\SuchakQrToken;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakPdfQrFoundationService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuchakWebUiCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_verified_suchak_can_operate_dashboard_actions_from_ui_routes(): void
    {
        [$suchakUser, $account, $profile, $representation] = $this->activeRepresentationFixture([
            'highest_education' => 'B.Com',
        ]);

        $dashboard = $this->actingAs($suchakUser)->get(route('suchak.dashboard'));

        $dashboard->assertOk()
            ->assertSee('Create intake source', false)
            ->assertSee('Masked search', false)
            ->assertSee('Generate PDF/QR', false)
            ->assertSee('Suggest profile update', false)
            ->assertSee(route('suchak.intakes.create'), false)
            ->assertSee(route('suchak.search.index'), false);

        $exportResponse = $this->actingAs($suchakUser)
            ->post(route('suchak.representations.exports.store', $representation));

        $exportResponse->assertRedirect(route('suchak.dashboard'))
            ->assertSessionHas('qr_url_path');

        $qrPath = (string) $this->app['session']->get('qr_url_path');
        $rawToken = Str::after($qrPath, '/r/');

        $this->assertSame('/r/'.$rawToken, $qrPath);
        $this->assertSame(64, strlen($rawToken));
        $this->assertSame(1, SuchakBiodataExport::query()->where('representation_id', $representation->id)->count());
        $this->assertSame(1, SuchakQrToken::query()->where('representation_id', $representation->id)->count());
        $this->assertDatabaseMissing('suchak_qr_tokens', ['token_hash' => $rawToken]);

        $suggestionResponse = $this->actingAs($suchakUser)
            ->post(route('suchak.representations.profile-update-suggestions.store', $representation), [
                'field_key' => 'highest_education',
                'suggested_value' => 'M.Com',
            ]);

        $suggestionResponse->assertRedirect(route('suchak.dashboard'));

        $this->assertDatabaseHas('suchak_profile_update_suggestions', [
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'field_key' => 'highest_education',
            'old_value' => 'B.Com',
            'suggested_value' => 'M.Com',
            'suggestion_status' => SuchakProfileUpdateSuggestion::STATUS_PENDING_CANDIDATE_CONFIRMATION,
        ]);
        $this->assertSame('B.Com', $profile->fresh()->highest_education);
    }

    public function test_public_qr_route_renders_only_masked_candidate_summary(): void
    {
        [$suchakUser, , $profile, $representation] = $this->activeRepresentationFixture([
            'full_name' => 'Sensitive QR Candidate',
            'address_line' => 'Hidden QR Address',
            'highest_education' => 'B.Tech',
        ]);
        $this->insertPrivateContactFixture($profile, '9876543210');

        $result = app(SuchakPdfQrFoundationService::class)
            ->createGovernedBiodataPdfExport($representation, $suchakUser);

        $response = $this->get($result['qr_url_path']);

        $response->assertOk()
            ->assertSee('Masked Candidate Preview', false)
            ->assertSee('B.Tech', false)
            ->assertDontSee('Sensitive QR Candidate', false)
            ->assertDontSee('9876543210', false)
            ->assertDontSee('Hidden QR Address', false);

        $this->assertSame(1, $result['qr_token']->fresh()->scan_count);
    }

    public function test_admin_suchak_dashboard_exposes_account_review_navigation(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.suchak.dashboard'))
            ->assertOk()
            ->assertSee('Review accounts', false)
            ->assertSee(route('admin.suchak.accounts.index'), false)
            ->assertSee('Settings', false)
            ->assertSee(route('admin.suchak.settings.index'), false)
            ->assertSee('Suchak', false);
    }

    /**
     * @param  array<string, mixed>  $profileAttributes
     * @return array{0: User, 1: SuchakAccount, 2: MatrimonyProfile, 3: SuchakProfileRepresentation}
     */
    private function activeRepresentationFixture(array $profileAttributes = []): array
    {
        $suchakUser = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        $candidateUser = User::factory()->create();
        $profile = $this->activeProfile(array_merge([
            'user_id' => $candidateUser->id,
            'full_name' => 'Suchak UI Candidate',
            'highest_education' => 'B.Com',
            'address_line' => 'Sensitive UI Address',
        ], $profileAttributes));

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

        return [$suchakUser, $account, $profile, $representation];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeProfile(array $attributes = []): MatrimonyProfile
    {
        $profile = MatrimonyProfile::factory()->create(array_merge([
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'height_cm' => 164,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], $attributes, [
            'lifecycle_state' => 'draft',
        ]));

        $leafId = (int) City::query()->where('name', 'Pune City')->firstOrFail()->id;

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $leafId]);
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $leafId, null, true, false);
        }

        DB::table($profile->getTable())
            ->where('id', $profile->id)
            ->update([
                'lifecycle_state' => 'active',
                'is_suspended' => false,
                'updated_at' => now(),
            ]);

        return $profile->fresh();
    }

    private function insertPrivateContactFixture(MatrimonyProfile $profile, string $phoneNumber): void
    {
        $contactRow = [
            'profile_id' => $profile->id,
            'contact_name' => 'Private QR Contact',
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
}
