<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAccountDeletionService;
use App\Services\Account\MemberAccountDeletionService;
use App\Support\Suchak\SuchakContactRouting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Suchak closing their own business account.
 *
 * The assertion that matters is the third one: after archiving, the candidate's
 * contact must stop being routed. That protection is not written in the
 * deletion service — it falls out of scopePubliclyRoutable() requiring the
 * account to be verified and public_active. If someone ever relaxes that scope,
 * this test is what notices.
 */
class SuchakAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: SuchakAccount, 2: MatrimonyProfile}
     */
    private function suchakWithCandidate(): array
    {
        $suchakUser = User::factory()->create(['is_admin' => false]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        $candidate = MatrimonyProfile::factory()->create([
            'user_id' => User::factory()->create(['is_admin' => false])->id,
            'is_showcase' => false,
        ]);

        SuchakProfileRepresentation::query()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $candidate->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'representation_mode' => SuchakProfileRepresentation::MODE_MANUAL_FORM_BY_SUCHAK,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'consent_verified_at' => now(),
        ]);

        return [$suchakUser, $account, $candidate];
    }

    public function test_deleting_a_suchak_account_archives_it_revokes_representations_and_blocks_contact(): void
    {
        [$suchakUser, $account, $candidate] = $this->suchakWithCandidate();

        $this->assertTrue(
            SuchakContactRouting::isRouted($candidate->fresh()),
            'the candidate must start out routed through the Suchak'
        );

        $result = app(SuchakAccountDeletionService::class)
            ->requestDeletion($account, $suchakUser, 'Closing my bureau.');

        $this->assertTrue($result['archived']);
        $this->assertSame(1, $result['representations_revoked']);

        $account->refresh();
        $this->assertSame(SuchakAccount::VERIFICATION_ARCHIVED, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->public_status);

        $this->assertDatabaseMissing('suchak_profile_representations', [
            'suchak_account_id' => $account->id,
            'revoked_at' => null,
        ]);

        // The whole point: contact protection is a consequence of archiving,
        // not something the deletion service writes.
        $this->assertFalse(
            SuchakContactRouting::isRouted($candidate->fresh()),
            'contact must stop being routed the moment the account is archived'
        );

        // Enrolled in the sweep that already runs daily — no second scheduler.
        $suchakUser->refresh();
        $this->assertNotNull($suchakUser->deletion_requested_at);
        $this->assertTrue(
            app(MemberAccountDeletionService::class)
                ->dueForPurge(0)
                ->contains(fn (User $due): bool => $due->is($suchakUser)),
            'the existing member sweep must already select this Suchak'
        );
    }

    public function test_a_second_request_does_not_restart_the_clock(): void
    {
        [$suchakUser, $account] = $this->suchakWithCandidate();
        $service = app(SuchakAccountDeletionService::class);

        $service->requestDeletion($account, $suchakUser, 'first');
        $firstRequestedAt = $suchakUser->fresh()->deletion_requested_at;

        $this->travel(2)->days();
        $service->requestDeletion($account->fresh(), $suchakUser->fresh(), 'second');

        $this->assertEquals(
            $firstRequestedAt->toDateTimeString(),
            $suchakUser->fresh()->deletion_requested_at->toDateTimeString(),
            'a second tap must not buy another 30 days'
        );
    }

    public function test_the_typed_confirmation_is_enforced_on_the_server(): void
    {
        [$suchakUser] = $this->suchakWithCandidate();

        $this->actingAs($suchakUser, 'sanctum')
            ->postJson('/api/v1/suchak/account/deletion', ['confirmation' => 'yes'])
            ->assertStatus(422);

        $this->assertNull($suchakUser->fresh()->deletion_requested_at);
    }

    public function test_the_endpoint_completes_the_request_when_the_word_is_right(): void
    {
        [$suchakUser] = $this->suchakWithCandidate();

        $this->actingAs($suchakUser, 'sanctum')
            ->postJson('/api/v1/suchak/account/deletion', ['confirmation' => 'delete'])
            ->assertOk()
            ->assertJsonPath('deletion.archived', true)
            ->assertJsonPath('deletion.grace_days', MemberAccountDeletionService::GRACE_DAYS);

        $this->assertNotNull($suchakUser->fresh()->deletion_requested_at);
    }
}
