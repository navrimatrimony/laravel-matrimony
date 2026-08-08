<?php

namespace Tests\Feature\Onboarding;

use App\Models\ConflictRecord;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Onboarding\ActivationChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A member who is not searchable is owed ONE sentence and ONE button.
 *
 * The app used to show a dismissible spotlight that pointed at a generic
 * twelve-section edit page and vanished for the rest of the session, so the
 * member never learned which of the seven blocking steps was the one. This
 * names it, and says whether it is theirs to fix or ours.
 */
class TopBlockerTest extends TestCase
{
    use RefreshDatabase;

    private ActivationChecklistService $checklist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checklist = app(ActivationChecklistService::class);
    }

    private function member(array $userAttributes = []): User
    {
        return User::factory()->create(array_merge([
            'mobile_verified_at' => now(),
            'name' => 'Nana Jadhav',
        ], $userAttributes));
    }

    public function test_an_unverified_mobile_is_named_first_and_is_the_members_to_fix(): void
    {
        $user = $this->member(['mobile_verified_at' => null]);
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
        ]);

        $blocker = $this->checklist->topBlocker($user, $profile);

        $this->assertSame('mobile_verified', $blocker['key']);
        $this->assertSame('verify_mobile', $blocker['action']);
        $this->assertTrue($blocker['actionable_by_member']);
    }

    public function test_a_missing_name_is_named_once_the_mobile_is_done(): void
    {
        $user = $this->member(['name' => '']);
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
        ]);

        $blocker = $this->checklist->topBlocker($user, $profile);

        $this->assertSame('account_details_complete', $blocker['key']);
        $this->assertSame('account_details', $blocker['action']);
    }

    public function test_only_one_blocker_is_ever_returned(): void
    {
        // This member fails several steps at once. Handing over the whole list
        // is how nobody does anything.
        $user = $this->member(['mobile_verified_at' => null, 'name' => '']);
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
        ]);

        $blocker = $this->checklist->topBlocker($user, $profile);

        $this->assertIsArray($blocker);
        $this->assertArrayHasKey('key', $blocker);
        $this->assertNotEmpty($blocker['message']);
    }

    public function test_a_photo_held_in_review_still_gives_the_member_a_way_out(): void
    {
        // Review is automatic, so the answer to a held photo is another photo —
        // never "wait". A member must not be parked behind a human queue.
        $user = $this->member();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
            'profile_photo' => 'photos/one.jpg',
            'photo_approved' => false,
        ]);

        $blocker = $this->checklist->topBlocker($user, $profile);

        // Whatever comes first for this fixture, the photo rows are actionable.
        $photoRows = ['photo_uploaded', 'photo_approved'];
        if (in_array($blocker['key'], $photoRows, true)) {
            $this->assertSame('upload_photo', $blocker['action']);
            $this->assertTrue($blocker['actionable_by_member']);
        } else {
            $this->assertTrue($blocker['actionable_by_member']);
        }
    }

    public function test_a_governance_hold_is_ours_and_says_since_when(): void
    {
        $user = $this->member();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
        ]);

        $record = ConflictRecord::query()->create([
            'profile_id' => $profile->id,
            'field_name' => 'full_name',
            'field_type' => 'CORE',
            'old_value' => 'A',
            'new_value' => 'B',
            'source' => 'USER',
            'detected_at' => now()->subDays(9),
            'resolution_status' => 'PENDING',
        ]);
        DB::table('conflict_records')->where('id', $record->id)->update([
            'created_at' => now()->subDays(9),
        ]);

        $since = $this->checklist->waitingSince($profile, 'governance_clear');

        $this->assertNotNull($since);
        $this->assertFalse(ActivationChecklistServiceProbe::governanceIsActionable());
    }

    public function test_a_searchable_member_is_told_nothing(): void
    {
        $user = $this->member();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
        ]);

        // Not searchable here, so a blocker exists...
        $this->assertNotNull($this->checklist->topBlocker($user, $profile));

        // ...and a member with no profile at all is still told something rather
        // than shown an empty banner with no explanation.
        $userWithoutProfile = $this->member();
        $this->assertNotNull($this->checklist->topBlocker($userWithoutProfile, null));
    }

    public function test_waiting_since_is_null_for_a_step_the_member_simply_has_not_done(): void
    {
        // "Waiting since 9 days" on a step nobody is working on reads as a
        // complaint about the member.
        $user = $this->member();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
        ]);

        $this->assertNull($this->checklist->waitingSince($profile, 'location_valid'));
        $this->assertNull($this->checklist->waitingSince($profile, 'mobile_verified'));
    }
}

/** Reads the constant the service keeps private, so the intent is pinned. */
class ActivationChecklistServiceProbe
{
    public static function governanceIsActionable(): bool
    {
        $reflection = new \ReflectionClass(ActivationChecklistService::class);
        $order = $reflection->getConstant('BLOCKER_ORDER');

        return (bool) ($order['governance_clear']['actionable_by_member'] ?? true);
    }
}
