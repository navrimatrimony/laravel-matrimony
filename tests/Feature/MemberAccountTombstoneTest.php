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

    /**
     * The cross-feature half of leaving: the Suchak stops counting the departed
     * member as a customer, but the representation row itself survives — it is
     * undeletable by design and the marriage/consent history hangs off it.
     */
    public function test_purging_a_represented_member_deactivates_the_representation_and_wipes_the_alias(): void
    {
        $leaver = $this->makeMember('Represented Leaver', '9000000004');
        $other = $this->makeMember('Represented Stayer', '9000000005');

        $suchakUser = User::factory()->create(['is_admin' => false]);
        $account = \App\Models\SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'verification_status' => \App\Models\SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => \App\Models\SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        $makeRepresentation = function (MatrimonyProfile $profile) use ($account) {
            return \App\Models\SuchakProfileRepresentation::query()->create([
                'suchak_account_id' => $account->id,
                'matrimony_profile_id' => $profile->id,
                'representation_status' => \App\Models\SuchakProfileRepresentation::STATUS_ACTIVE,
                'representation_mode' => \App\Models\SuchakProfileRepresentation::MODE_MANUAL_FORM_BY_SUCHAK,
                'consent_status' => \App\Models\SuchakProfileRepresentation::CONSENT_ACCEPTED,
                'consent_verified_at' => now(),
                'shared_display_name' => 'Sunita G. (Lakhandur)',
            ]);
        };
        $leaverRep = $makeRepresentation($leaver);
        $otherRep = $makeRepresentation($other);

        UserAccountDatabasePurger::purgeUserAccount(
            $leaver->user()->first(),
            keepCounterpartConversations: true
        );

        $leaverRow = DB::table('suchak_profile_representations')->where('id', $leaverRep->id)->first();
        $this->assertNotNull($leaverRow, 'the representation must survive — it is undeletable history');
        $this->assertNotNull($leaverRow->candidate_deactivated_at);
        $this->assertNull(
            $leaverRow->shared_display_name,
            'the display alias is PII and must not outlive the erasure promise'
        );

        // The neighbouring customer is untouched.
        $otherRow = DB::table('suchak_profile_representations')->where('id', $otherRep->id)->first();
        $this->assertNull($otherRow->candidate_deactivated_at);
        $this->assertSame('Sunita G. (Lakhandur)', $otherRow->shared_display_name);
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
