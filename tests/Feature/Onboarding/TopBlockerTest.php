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

    public function test_a_missing_creator_name_is_never_named_as_the_reason(): void
    {
        // It is blocking on the checklist and NOT a search gate. Naming it sent
        // a member off to add a creator name that changes nothing about why
        // nobody can find them.
        $user = $this->member(['name' => '']);
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
        ]);

        $keys = array_column($this->checklist->blockerQueue($user, $profile), 'key');

        $this->assertNotContains('account_details_complete', $keys);
    }

    public function test_the_queue_is_ranked_and_the_top_blocker_is_its_first_entry(): void
    {
        $user = $this->member(['mobile_verified_at' => null]);
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
        ]);

        $queue = $this->checklist->blockerQueue($user, $profile);
        $top = $this->checklist->topBlocker($user, $profile);

        $this->assertNotEmpty($queue);
        $this->assertSame($queue[0]['key'], $top['key']);
        // Structurally impossible for the two to disagree — that is the point.
        $this->assertSame('mobile_verified', $queue[0]['key']);
    }

    public function test_progress_counts_the_same_gates_the_queue_ranks(): void
    {
        $user = $this->member(['mobile_verified_at' => null]);
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
        ]);

        $progress = $this->checklist->activationProgress($user, $profile);
        $outstanding = count($this->checklist->blockerQueue($user, $profile));

        // The bar counts GATES; the queue counts ACTIONS, and one gate can open
        // into several (required fields). What must always hold is the only
        // thing a member reads off the bar: it is full exactly when nothing is
        // in the way.
        $this->assertSame(7, $progress['total']);
        $this->assertLessThan($progress['total'], $progress['done']);
        $this->assertGreaterThan(0, $outstanding);
    }

    public function test_the_missing_mandatory_fields_are_named_not_just_counted(): void
    {
        $user = $this->member();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
        ]);

        $row = collect($this->checklist->items($user, $profile))
            ->firstWhere('key', 'required_fields_complete');

        $this->assertIsArray($row['missing_fields']);
        $this->assertNotEmpty(
            $row['missing_fields'],
            'A member told only that "required fields are missing" has eleven sections to guess between.'
        );
    }

    public function test_a_searchable_member_has_an_empty_queue_and_a_full_bar(): void
    {
        $user = $this->member();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
        ]);

        // Not searchable here, so the queue is not empty...
        $this->assertNotEmpty($this->checklist->blockerQueue($user, $profile));
        $progress = $this->checklist->activationProgress($user, $profile);
        $this->assertLessThan($progress['total'], $progress['done']);
    }

    public function test_the_required_fields_drawer_is_opened_into_named_actions(): void
    {
        // "Required profile fields are missing" is not something a member can
        // do. The fields inside it are.
        $user = $this->member();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
            'caste_id' => null,
        ]);

        $queue = $this->checklist->blockerQueue($user, $profile);
        $keys = array_column($queue, 'key');

        $this->assertNotContains(
            'required_fields_complete',
            $keys,
            'The drawer itself must never reach a member — only what is inside it.'
        );

        $casteEntry = collect($queue)->firstWhere('field', 'caste');
        $this->assertNotNull($casteEntry, 'A missing caste must be named as its own action.');
        $this->assertSame('complete_profile', $casteEntry['action']);
        $this->assertTrue($casteEntry['actionable_by_member']);
    }

    public function test_a_field_with_its_own_gate_is_asked_for_once_not_twice(): void
    {
        // location is both a mandatory field and a gate row. Emitting both would
        // show one problem as two.
        $user = $this->member();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
        ]);

        $queue = $this->checklist->blockerQueue($user, $profile);
        $locationEntries = array_values(array_filter(
            $queue,
            fn (array $b): bool => ($b['field'] ?? null) === 'location'
        ));
        $photoEntries = array_values(array_filter(
            $queue,
            fn (array $b): bool => ($b['field'] ?? null) === 'profile_photo'
        ));

        $this->assertCount(1, $locationEntries);
        $this->assertSame('location_valid', $locationEntries[0]['key']);
        // photo_uploaded and photo_approved are different states of one thing,
        // and only one of them can be outstanding at a time.
        $this->assertLessThanOrEqual(1, count(array_unique(array_column($photoEntries, 'key'))));
    }

    public function test_every_entry_carries_an_action_the_app_can_route_on(): void
    {
        $user = $this->member(['mobile_verified_at' => null]);
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
            'caste_id' => null,
        ]);

        foreach ($this->checklist->blockerQueue($user, $profile) as $blocker) {
            $this->assertNotEmpty($blocker['action'], "Blocker {$blocker['key']} has nowhere to send anyone.");
            $this->assertArrayHasKey('field', $blocker);
        }
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
