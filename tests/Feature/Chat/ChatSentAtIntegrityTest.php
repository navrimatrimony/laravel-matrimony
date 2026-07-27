<?php

namespace Tests\Feature\Chat;

use App\Models\City;
use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatPolicyService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `messages.sent_at` is authored by the application and by nothing else.
 *
 * On production it was silently rewritten by MySQL: the column carried an implicit
 * `ON UPDATE CURRENT_TIMESTAMP`, so stamping `read_at` on a thread replaced every
 * one of that side's `sent_at` values with the *read* time (in the DB's UTC session
 * zone, 5:30 behind the app). Because the reply gate sorts on `sent_at DESC`, the
 * replied-to side sank below the unanswered side and the gate fired against a
 * conversation that had in fact been answered.
 */
class ChatSentAtIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_marking_a_conversation_read_does_not_rewrite_sent_at(): void
    {
        [$aUser, $bUser, $a, $b, $conversation] = $this->conversation();

        $service = app(ChatMessageService::class);

        $m1 = $service->sendTextMessage($a, $b, $conversation, 'A1');
        $m2 = $service->sendTextMessage($a, $b, $conversation, 'A2');

        $before = DB::table('messages')
            ->whereIn('id', [$m1->id, $m2->id])
            ->orderBy('id')
            ->pluck('sent_at', 'id')
            ->all();

        // The receiver opens the thread. This UPDATEs both rows (read_at,
        // delivery_status) and must not touch sent_at.
        $this->travel(2)->minutes();
        $service->markConversationReadForRepresentative($b, $conversation);

        $after = DB::table('messages')
            ->whereIn('id', [$m1->id, $m2->id])
            ->orderBy('id')
            ->pluck('sent_at', 'id')
            ->all();

        $this->assertSame(
            $before,
            $after,
            'sent_at was mutated by an unrelated UPDATE — the database is authoring this column.'
        );

        $this->assertNotNull(Message::find($m1->id)->read_at, 'The read receipt itself must still be written.');
    }

    public function test_alternating_conversation_never_trips_the_reply_gate_for_either_side(): void
    {
        [$aUser, $bUser, $a, $b, $conversation] = $this->conversation();

        $messages = app(ChatMessageService::class);
        $policy = app(ChatPolicyService::class);

        // Default policy allows 2 consecutive unanswered messages. Alternate well
        // past that, marking read on each turn (the exact sequence that corrupted
        // production), and neither side may ever be gated.
        for ($turn = 1; $turn <= 5; $turn++) {
            $this->assertTrue(
                $policy->canSendMessage($a, $b, $conversation)->allowed,
                "A was blocked on turn {$turn} despite B having replied."
            );
            $messages->sendTextMessage($a, $b, $conversation, "A{$turn}");
            $messages->markConversationReadForRepresentative($b, $conversation);

            $this->travel(1)->minutes();

            $this->assertTrue(
                $policy->canSendMessage($b, $a, $conversation)->allowed,
                "B was blocked on turn {$turn} despite A having replied."
            );
            $messages->sendTextMessage($b, $a, $conversation, "B{$turn}");
            $messages->markConversationReadForRepresentative($a, $conversation);

            $this->travel(1)->minutes();
        }

        // And the rule itself still bites: two in a row with no reply closes the gate.
        $messages->sendTextMessage($a, $b, $conversation, 'A-solo-1');
        $messages->sendTextMessage($a, $b, $conversation, 'A-solo-2');

        $decision = $policy->canSendMessage($a, $b, $conversation);
        $this->assertFalse($decision->allowed, 'The reply gate must still close after 2 unanswered messages.');
        $this->assertContains($decision->code, ['reply_gate_limit', 'reply_gate_cooldown']);
    }

    public function test_chat_timestamp_columns_are_not_database_generated(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Implicit TIMESTAMP auto-columns are a MySQL/MariaDB behaviour.');
        }

        foreach ([['messages', 'sent_at'], ['message_policy_cooldowns', 'locked_until']] as [$table, $column]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $row = DB::selectOne(
                'SELECT COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            );

            $this->assertNotNull($row, "{$table}.{$column} is missing.");
            $this->assertStringNotContainsStringIgnoringCase(
                'on update',
                (string) ($row->EXTRA ?? ''),
                "{$table}.{$column} carries ON UPDATE CURRENT_TIMESTAMP — the database will overwrite it."
            );
            $this->assertStringNotContainsStringIgnoringCase(
                'CURRENT_TIMESTAMP',
                (string) ($row->COLUMN_DEFAULT ?? ''),
                "{$table}.{$column} defaults to CURRENT_TIMESTAMP — the database will author it."
            );
        }
    }

    /**
     * @return array{0: User, 1: User, 2: MatrimonyProfile, 3: MatrimonyProfile, 4: Conversation}
     */
    private function conversation(): array
    {
        $aUser = User::factory()->create();
        $bUser = User::factory()->create();

        $a = $this->createActiveProfile($aUser);
        $b = $this->createActiveProfile($bUser);

        [$p1, $p2] = Conversation::normalizePairIds($a->id, $b->id);

        $conversation = Conversation::create([
            'profile_one_id' => $p1,
            'profile_two_id' => $p2,
            'created_by_profile_id' => $a->id,
            'status' => Conversation::STATUS_ACTIVE,
        ]);

        return [$aUser, $bUser, $a, $b, $conversation];
    }

    private function createActiveProfile(User $user): MatrimonyProfile
    {
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);

        $leafId = (int) City::query()->where('name', 'Pune City')->firstOrFail()->id;

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $leafId]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $leafId, null, true, false);
        }

        $profile->update(['lifecycle_state' => 'active']);

        return $profile->fresh();
    }
}
