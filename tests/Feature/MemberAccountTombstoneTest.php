<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Maintenance\UserAccountDatabasePurger;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A member deleting their account must not take the other person's chat with them.
 *
 * The counterpart keeps the thread; the leaver keeps nothing. Both halves are
 * asserted here because either one alone is a bug: an erased thread breaks the
 * product promise, a surviving name breaks the privacy one.
 */
class MemberAccountTombstoneTest extends TestCase
{
    use RefreshDatabase;

    private int $leaf = 0;

    private function makeMember(string $name, string $mobile): MatrimonyProfile
    {
        if ($this->leaf === 0) {
            $this->seed(MinimalLocationSeeder::class);
            $this->leaf = (int) City::query()->where('name', 'Pune City')->value('id');
        }

        $user = User::factory()->create([
            'is_admin' => false,
            'name' => $name,
            'mobile' => $mobile,
        ]);

        // Created as draft then promoted, because MatrimonyProfileObserver
        // demands a residence on anything saved active.
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => $name,
            'is_showcase' => false,
            'lifecycle_state' => 'draft',
            'location_id' => $this->leaf,
        ]);
        $profile->lifecycle_state = 'active';
        $profile->save();

        return $profile;
    }

    public function test_deleting_an_account_keeps_the_counterparts_chat_and_erases_the_leaver(): void
    {
        $leaver = $this->makeMember('Bhagyashree Leaver', '9000000001');
        $stayer = $this->makeMember('Amit Stayer', '9000000002');

        $conversationId = DB::table('conversations')->insertGetId([
            'profile_one_id' => $leaver->id,
            'profile_two_id' => $stayer->id,
            'created_by_profile_id' => $stayer->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('messages')->insert([
            'conversation_id' => $conversationId,
            'sender_profile_id' => $stayer->id,
            'receiver_profile_id' => $leaver->id,
            'message_type' => 'text',
            'body_text' => 'Namaskar, tumcha profile awadla.',
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        UserAccountDatabasePurger::purgeUserAccount(
            $leaver->user()->first(),
            keepCounterpartConversations: true
        );

        // The stayer's record of the conversation survives intact.
        $this->assertDatabaseHas('conversations', ['id' => $conversationId]);
        $this->assertSame(
            'Namaskar, tumcha profile awadla.',
            DB::table('messages')->where('conversation_id', $conversationId)->value('body_text'),
            'the message the stayer sent must still be readable by them'
        );

        // Nothing identifying is left behind on either row.
        $profileRow = DB::table('matrimony_profiles')->where('id', $leaver->id)->first();
        $this->assertNotNull($profileRow, 'the anchor row must survive for the FK');
        $this->assertNotNull($profileRow->deleted_at, 'the profile must be soft-deleted');
        $this->assertSame('', (string) $profileRow->full_name);
        $this->assertNull($profileRow->date_of_birth);

        $userRow = DB::table('users')->where('id', $leaver->user_id)->first();
        $this->assertNotNull($userRow);
        $this->assertNull($userRow->name);
        $this->assertNull($userRow->email);
        $this->assertNull($userRow->password);
        $this->assertNotNull($userRow->account_deleted_at);

        // The released mobile lets the same person sign up again tomorrow.
        $this->assertNull($userRow->mobile);
        User::factory()->create(['mobile' => '9000000001', 'is_admin' => false]);
        $this->assertSame(1, User::query()->where('mobile', '9000000001')->count());
    }

    public function test_a_showcase_profile_is_still_erased_outright(): void
    {
        $showcase = $this->makeMember('Showcase Person', '9000000003');
        $showcase->is_showcase = true;
        $showcase->save();

        UserAccountDatabasePurger::purgeUserAccount($showcase->user()->first());

        $this->assertDatabaseMissing('matrimony_profiles', ['id' => $showcase->id]);
        $this->assertDatabaseMissing('users', ['id' => $showcase->user_id]);
    }
}
