<?php

namespace Tests\Feature\Suchak;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\SuchakProfileRequest;
use App\Models\User;
use App\Services\Profile\ProfileCanonicalResidenceService;
use App\Services\SubscriptionService;
use Database\Seeders\MinimalLocationSeeder;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Suchak\Concerns\BuildsSuchakRequestFixture;
use Tests\TestCase;

/**
 * What a chat SCREEN needs on top of "the messages came back".
 *
 * The redesigned Suchak chat screen draws an "इथून पुढे न वाचलेले" divider and
 * a ✓/✓✓ tick, and polls with ?since_id=. Those three answers must come from
 * the SAME engine the member app already uses — one unread state, one thread
 * window, one delivery status — or the two surfaces will disagree about the
 * same conversation and each will look broken to the other's user.
 *
 * The rules underneath are not relaxed anywhere here: authorization, consent,
 * the paid read gate, quotas and the reply gate are all left exactly as they
 * are (their own tests live in SuchakChatReadApiTest and FreeChatReplyGateTest).
 */
class SuchakChatThreadContractTest extends TestCase
{
    use BuildsSuchakRequestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_the_suchak_gets_an_unread_count_and_a_divider_that_clear_once_the_thread_is_read(): void
    {
        $fixture = $this->fixture();
        $conversationId = $this->conversationFor($fixture, 'पहिला प्रश्न');

        Sanctum::actingAs($fixture['member']);
        $this->postJson("/api/v1/chats/{$conversationId}/messages", [
            'body_text' => 'दुसरा प्रश्न',
        ])->assertOk();

        Sanctum::actingAs($fixture['account']->user);
        $opened = $this->getJson("/api/v1/suchak/chats/{$conversationId}")->assertOk();

        $messages = $opened->json('data.messages');
        $this->assertCount(2, $messages);

        // Both member messages are waiting, and the divider belongs above the
        // OLDEST of them — not above the newest, and not at the top of the thread.
        $opened->assertJsonPath('data.unread_count', 2);
        $opened->assertJsonPath('data.first_unread_message_id', $messages[0]['id']);

        // Opening the thread is what marks it read; the next poll must be clean,
        // or the divider would stick to the screen forever.
        $this->getJson("/api/v1/suchak/chats/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0)
            ->assertJsonPath('data.first_unread_message_id', null);
    }

    public function test_the_member_gets_the_same_two_answers_in_the_same_shape(): void
    {
        $fixture = $this->fixture();
        $conversationId = $this->conversationFor($fixture, 'नमस्कार');
        $this->subscribeToPlan($fixture['member'], 'basic_male');

        // Two answers come back to the member, through the existing relay.
        Sanctum::actingAs($fixture['account']->user);
        foreach (['ती B.Ed आहे.', 'फोटो पाठवतो.'] as $reply) {
            $this->postJson("/api/v1/suchak/chats/{$conversationId}/messages", [
                'body_text' => $reply,
            ])->assertOk();
        }

        Sanctum::actingAs($fixture['member']);
        $opened = $this->getJson("/api/v1/chats/{$conversationId}")->assertOk();

        $incoming = array_values(array_filter(
            $opened->json('messages'),
            static fn (array $row): bool => $row['is_mine'] === false,
        ));
        $this->assertCount(2, $incoming);

        $opened->assertJsonPath('unread_count', 2);
        $opened->assertJsonPath('first_unread_message_id', $incoming[0]['id']);

        $this->getJson("/api/v1/chats/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonPath('first_unread_message_id', null);
    }

    public function test_the_paid_read_gate_hides_the_words_but_never_falsifies_the_count(): void
    {
        $fixture = $this->fixture();
        $conversationId = $this->conversationFor($fixture, 'नमस्कार');
        $this->subscribeToPlan($fixture['member'], 'free_male');

        Sanctum::actingAs($fixture['account']->user);
        $this->postJson("/api/v1/suchak/chats/{$conversationId}/messages", [
            'body_text' => 'हे वाचायला plan लागतो',
        ])->assertOk();

        Sanctum::actingAs($fixture['member']);
        $opened = $this->getJson("/api/v1/chats/{$conversationId}")->assertOk();
        $opened->assertJsonPath('read_locked_for_incoming', true);

        $incoming = array_values(array_filter(
            $opened->json('messages'),
            static fn (array $row): bool => $row['is_mine'] === false,
        ));
        $this->assertCount(1, $incoming);

        // The gate withholds the BODY. It does not pretend the message is not
        // there — the inbox badge has always counted it, and the upgrade prompt
        // is built on that count being honest. A position is not a body.
        $this->assertNull($incoming[0]['body_text']);
        $this->assertTrue($incoming[0]['read_locked']);
        $opened->assertJsonPath('unread_count', 1);
        $opened->assertJsonPath('first_unread_message_id', $incoming[0]['id']);

        // And a locked member reading the screen does NOT consume the unread —
        // they never read it, so it must still be waiting after they upgrade.
        $this->getJson("/api/v1/chats/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('first_unread_message_id', $incoming[0]['id']);
    }

    public function test_since_id_on_the_suchak_endpoint_returns_only_newer_messages_under_the_same_fifty_cap(): void
    {
        $fixture = $this->fixture();
        $conversationId = $this->conversationFor($fixture, 'सुरुवात');

        $openingId = (int) Message::query()->where('conversation_id', $conversationId)->min('id');
        $this->appendMemberMessages($fixture, $conversationId, 60);

        Sanctum::actingAs($fixture['account']->user);
        $polled = $this->getJson("/api/v1/suchak/chats/{$conversationId}?since_id={$openingId}")->assertOk();

        $ids = array_column($polled->json('data.messages'), 'id');
        $this->assertCount(50, $ids, 'The Suchak poll must respect the same 50-message cap as the member poll.');
        $this->assertSame($openingId + 1, $ids[0], 'A poll starts at the message right after the cursor.');
        $this->assertSame($ids, array_values(array_unique($ids)));
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'A poll is ascending, oldest first.');

        // Identical window on the member endpoint — same engine, not a lookalike.
        Sanctum::actingAs($fixture['member']);
        $memberIds = array_column(
            $this->getJson("/api/v1/chats/{$conversationId}?since_id={$openingId}")->assertOk()->json('messages'),
            'id',
        );
        $this->assertSame($ids, $memberIds);

        // A cursor past the end is an empty poll, not the whole thread again.
        Sanctum::actingAs($fixture['account']->user);
        $this->getJson('/api/v1/suchak/chats/'.$conversationId.'?since_id='.((int) Message::query()->max('id')))
            ->assertOk()
            ->assertJsonPath('data.messages', []);
    }

    public function test_delivery_status_flips_to_read_only_once_the_other_side_has_read(): void
    {
        $fixture = $this->fixture();
        $conversationId = $this->conversationFor($fixture, 'वाचले का?');
        $this->subscribeToPlan($fixture['member'], 'basic_male');

        // The member's own bubble, before anybody on the other side has looked.
        Sanctum::actingAs($fixture['member']);
        $mine = $this->firstOwnMessage($this->getJson("/api/v1/chats/{$conversationId}")->assertOk()->json('messages'));
        $this->assertSame(Message::DELIVERY_SENT, $mine['delivery_status']);

        // The Suchak reads on the candidate's behalf — the recipient side.
        Sanctum::actingAs($fixture['account']->user);
        $this->getJson("/api/v1/suchak/chats/{$conversationId}")->assertOk();

        Sanctum::actingAs($fixture['member']);
        $mine = $this->firstOwnMessage($this->getJson("/api/v1/chats/{$conversationId}")->assertOk()->json('messages'));
        $this->assertSame(Message::DELIVERY_READ, $mine['delivery_status']);
    }

    public function test_messages_sent_in_the_same_second_keep_one_stable_order_and_one_stable_divider(): void
    {
        $fixture = $this->fixture();
        $conversationId = $this->conversationFor($fixture, 'पहिला');

        // Same-second sends are ordinary, and `sent_at` alone cannot separate
        // them. Without the id tie-breaker the thread would reshuffle between
        // polls and the divider would land on a different message each time.
        $sameSecond = now()->startOfSecond();
        $expected = [];
        foreach (['दुसरा', 'तिसरा', 'चौथा'] as $body) {
            $expected[] = (int) Message::create([
                'conversation_id' => $conversationId,
                'sender_profile_id' => $fixture['member_profile']->id,
                'receiver_profile_id' => $fixture['target_profile']->id,
                'message_type' => Message::TYPE_TEXT,
                'body_text' => $body,
                'sent_at' => $sameSecond,
                'read_at' => null,
                'delivery_status' => Message::DELIVERY_SENT,
            ])->id;
        }

        Sanctum::actingAs($fixture['account']->user);
        $first = $this->getJson("/api/v1/suchak/chats/{$conversationId}")->assertOk();
        $firstIds = array_column($first->json('data.messages'), 'id');

        // The opening message is older; the three same-second ones follow in id order.
        $this->assertSame($expected, array_slice($firstIds, -3));
        $first->assertJsonPath('data.first_unread_message_id', $firstIds[0]);

        // Read state cleared, the exact same window must come back identical.
        Message::query()->where('conversation_id', $conversationId)->update([
            'read_at' => null,
            'delivery_status' => Message::DELIVERY_SENT,
        ]);

        $again = $this->getJson("/api/v1/suchak/chats/{$conversationId}")->assertOk();
        $this->assertSame($firstIds, array_column($again->json('data.messages'), 'id'));
        $again->assertJsonPath('data.first_unread_message_id', $firstIds[0]);
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function conversationFor(array $fixture, string $message): int
    {
        Sanctum::actingAs($fixture['member']);

        $requestId = (int) $this->postJson(
            "/api/v1/matrimony-profiles/{$fixture['target_profile']->id}/suchak-requests",
            ['message' => $message],
        )->assertCreated()->json('data.suchak_request.id');

        $conversationId = (int) SuchakProfileRequest::query()
            ->whereKey($requestId)
            ->value('chat_conversation_id');

        $this->assertGreaterThan(0, $conversationId);

        return $conversationId;
    }

    /**
     * Raw rows on purpose: this fills a thread past the poll cap without
     * pretending to send 60 messages through the quota and reply-gate path,
     * which would (correctly) refuse long before 60.
     *
     * @param  array<string, mixed>  $fixture
     */
    private function appendMemberMessages(array $fixture, int $conversationId, int $count): void
    {
        $sentAt = now();
        for ($i = 1; $i <= $count; $i++) {
            Message::create([
                'conversation_id' => $conversationId,
                'sender_profile_id' => $fixture['member_profile']->id,
                'receiver_profile_id' => $fixture['target_profile']->id,
                'message_type' => Message::TYPE_TEXT,
                'body_text' => 'संदेश '.$i,
                'sent_at' => $sentAt->copy()->addSeconds($i),
                'read_at' => null,
                'delivery_status' => Message::DELIVERY_SENT,
            ]);
        }

        Conversation::query()->whereKey($conversationId)->update([
            'last_message_at' => $sentAt->copy()->addSeconds($count),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    private function firstOwnMessage(array $messages): array
    {
        foreach ($messages as $message) {
            if ($message['is_mine'] === true) {
                return $message;
            }
        }

        $this->fail('The viewer has no message of their own in this thread.');
    }

    private function subscribeToPlan(User $user, string $slug): void
    {
        $plan = Plan::query()->where('slug', $slug)->firstOrFail();
        $plan->loadMissing('terms');
        $termId = $plan->terms
            ->where('is_visible', true)
            ->sortBy('sort_order')
            ->first()?->id;

        app(SubscriptionService::class)->subscribe($user, $plan, $termId ? (int) $termId : null, null);
    }
}
