<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakPipeline;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\SuchakVisitConfirmation;
use App\Models\User;
use App\Support\Admin\AdminNavigationAccess;
use App\Support\Admin\AdminNavigationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Suchak Network section gate over the four meeting routes.
 *
 * EnsureAdminSectionAccess resolves a route's section through
 * AdminNavigationCatalog and, when no module claims the name, returns
 * `$next($request)` — it fails OPEN. The four `admin.suchak.visits.*` routes
 * were registered with the `admin.section` middleware but were absent from the
 * catalog, so the middleware was a no-op on exactly them: an admin explicitly
 * denied Suchak Network (403 on admin.suchak.payouts.index, the section's own
 * payout screen) still got 200 on the meetings screen and could drive
 * qualify-payout to completion — minting a real SuchakPlatformPayout.
 *
 * This test holds the whole family, not just the index. The GET half is also
 * covered by AdminNavigationAccessTest's unmapped-route sweep; the three POSTs
 * are covered nowhere else, and they are the ones that move money.
 */
class SuchakVisitAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_catalog_claims_every_visit_route_so_the_section_gate_cannot_fail_open(): void
    {
        foreach ([
            'admin.suchak.visits.index',
            'admin.suchak.visits.confirm',
            'admin.suchak.visits.dispute',
            'admin.suchak.visits.qualify-payout',
        ] as $routeName) {
            $this->assertSame(
                AdminNavigationAccess::SUCHAK_NETWORK,
                AdminNavigationCatalog::sectionForRouteName($routeName),
                $routeName.' resolves to no section, so admin.section waves it through.',
            );
        }
    }

    public function test_a_section_denied_admin_is_refused_on_all_four_visit_routes(): void
    {
        $visit = $this->visitRow();
        $admin = $this->sectionDeniedAdmin();

        // Control: the same admin is already correctly refused on the section's
        // own payout screen. Whatever refuses below is the same gate.
        $this->actingAs($admin)->get(route('admin.suchak.payouts.index'))->assertForbidden();

        $this->actingAs($admin)->get(route('admin.suchak.visits.index'))->assertForbidden();

        $this->actingAs($admin)->post(route('admin.suchak.visits.confirm', $visit), [
            'confirmation_note' => 'A denied admin must not be able to confirm this meeting.',
        ])->assertForbidden();

        $this->actingAs($admin)->post(route('admin.suchak.visits.dispute', $visit), [
            'dispute_reason' => 'A denied admin must not be able to open a dispute here.',
        ])->assertForbidden();

        // The most privileged action in the engine: it mints platform money.
        $this->actingAs($admin)->post(route('admin.suchak.visits.qualify-payout', $visit), [
            'amount' => '50000',
            'qualification_note' => 'A denied admin must not be able to qualify a payout.',
        ])->assertForbidden();

        $this->assertDatabaseCount('suchak_platform_payouts', 0);

        $fresh = DB::table('suchak_visit_confirmations')->where('id', $visit)->first();
        $this->assertSame(SuchakVisitConfirmation::STATUS_SCHEDULED, $fresh->visit_status);
        $this->assertNull($fresh->platform_payout_id);
        $this->assertNull($fresh->dispute_id);
    }

    public function test_an_admin_granted_the_section_still_reaches_the_meetings_screen(): void
    {
        // The gate must refuse the denied admin, not the section itself — a
        // catalog entry that 403s everybody would also make the test above pass.
        $this->visitRow();
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'data_admin']);

        DB::table('admin_capabilities')->insert($this->capabilityRow($admin, [
            AdminNavigationAccess::SUCHAK_NETWORK => true,
        ]));

        $this->actingAs($admin)->get(route('admin.suchak.visits.index'))->assertOk()->assertSee('Suchak Meetings');
    }

    private function sectionDeniedAdmin(): User
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'data_admin']);

        DB::table('admin_capabilities')->insert($this->capabilityRow($admin, [
            AdminNavigationAccess::COMMAND_CENTER => true,
        ]));

        return $admin;
    }

    /**
     * @param  array<string, bool>  $overrides
     * @return array<string, mixed>
     */
    private function capabilityRow(User $admin, array $overrides = []): array
    {
        $row = [
            'admin_id' => $admin->id,
            'can_manage_verification_tags' => false,
            'can_manage_serious_intents' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        foreach (AdminNavigationAccess::columns() as $section => $column) {
            $row[$column] = (bool) ($overrides[$section] ?? false);
        }

        return $row;
    }

    /**
     * A meeting row that route-model binding can resolve. Its state is
     * irrelevant: the gate must refuse before the service is ever consulted, so
     * this is deliberately the cheapest valid row rather than a scheduled visit
     * built through SuchakVisitConfirmationService.
     */
    private function visitRow(): int
    {
        $suchakUser = User::factory()->create();
        $requestingUser = User::factory()->create();

        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);
        $requestingProfile = MatrimonyProfile::factory()->create([
            'user_id' => $requestingUser->id,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $targetProfile = MatrimonyProfile::factory()->create([
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $targetProfile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);
        $request = SuchakProfileRequest::query()->create([
            'requesting_user_id' => $requestingUser->id,
            'requesting_matrimony_profile_id' => $requestingProfile->id,
            'target_matrimony_profile_id' => $targetProfile->id,
            'selected_suchak_account_id' => $account->id,
            'representation_id' => $representation->id,
            'request_status' => SuchakProfileRequest::STATUS_PENDING,
            'request_reason' => 'intro_visit',
            'message' => 'Please arrange an introduction.',
        ]);
        $pipeline = SuchakPipeline::query()->create([
            'request_id' => $request->id,
            'target_matrimony_profile_id' => $targetProfile->id,
            'requesting_matrimony_profile_id' => $requestingProfile->id,
            'selected_suchak_account_id' => $account->id,
            'representation_id' => $representation->id,
            'pipeline_status' => SuchakPipeline::STATUS_PENDING,
            'attribution_locked_at' => now(),
            'lock_expires_at' => now()->addDays(2),
            'sla_status' => SuchakPipeline::SLA_WITHIN,
        ]);

        return (int) DB::table('suchak_visit_confirmations')->insertGetId([
            'pipeline_id' => $pipeline->id,
            'suchak_account_id' => $account->id,
            'request_id' => $request->id,
            'representation_id' => $representation->id,
            'target_matrimony_profile_id' => $targetProfile->id,
            'requesting_matrimony_profile_id' => $requestingProfile->id,
            'visit_status' => SuchakVisitConfirmation::STATUS_SCHEDULED,
            'confirmation_policy_mode' => SuchakVisitConfirmation::POLICY_USER_AND_ADMIN,
            'scheduled_by_user_id' => $suchakUser->id,
            'scheduled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
