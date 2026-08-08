<?php

namespace Tests\Feature\Governance;

use App\Models\ConflictRecord;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The queue existed; nothing surfaced it.
 *
 * Three profiles sat invisible to every search for over a month, and the only
 * screen that would have shown it was one an admin had to think to open. A
 * count on its own would not have helped either — two unresolved records is
 * fine on Tuesday and a failure five weeks later — so the age is what the
 * dashboard reports and what turns it red.
 */
class HiddenProfilesAreVisibleToAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);
    }

    private function hiddenProfileWithConflictAgedDays(int $days): MatrimonyProfile
    {
        $owner = User::factory()->create(['is_admin' => false]);
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $owner->id,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);

        DB::table('matrimony_profiles')
            ->where('id', $profile->id)
            ->update(['lifecycle_state' => 'conflict_pending']);

        $record = ConflictRecord::query()->create([
            'profile_id' => $profile->id,
            'field_name' => 'other_relatives_text',
            'field_type' => 'CORE',
            'old_value' => 'जाधव',
            'new_value' => 'पवार',
            'source' => 'SYSTEM',
            'detected_at' => now()->subDays($days),
            'resolution_status' => 'PENDING',
        ]);
        DB::table('conflict_records')
            ->where('id', $record->id)
            ->update(['created_at' => now()->subDays($days)]);

        return $profile->fresh();
    }

    public function test_the_dashboard_reports_nothing_when_no_profile_is_hidden(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Profiles hidden by review')
            ->assertSee('Nobody is waiting.');
    }

    public function test_the_dashboard_names_hidden_profiles_and_how_long_they_have_waited(): void
    {
        $this->hiddenProfileWithConflictAgedDays(37);

        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Profiles hidden by review')
            ->assertSee('Oldest unresolved: 37 days.')
            // The way out has to be one click from the alarm.
            ->assertSee(route('admin.conflict-records.index'), false);
    }

    public function test_a_queue_older_than_a_week_is_shown_as_an_alarm(): void
    {
        $this->hiddenProfileWithConflictAgedDays(8);

        $response = $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk();

        $this->assertStringContainsString('bg-red-50', $response->getContent());
    }

    public function test_a_fresh_queue_is_reported_without_the_alarm(): void
    {
        $this->hiddenProfileWithConflictAgedDays(1);

        $response = $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk();

        $this->assertStringContainsString('Oldest unresolved: 1 days.', $response->getContent());
        $this->assertStringNotContainsString(
            'bg-red-50 dark:bg-red-900/30',
            $response->getContent()
        );
    }
}
