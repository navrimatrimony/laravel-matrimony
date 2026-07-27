<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakProfileRequest;
use App\Modules\Suchak\Services\SuchakChatThreadService;
use App\Services\CommunicationPolicyService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Suchak\Concerns\BuildsSuchakRequestFixture;
use Tests\TestCase;

/**
 * The Suchak can READ.
 *
 * The pipeline was one-way: a Suchak's reply went into the member↔candidate
 * conversation and nothing ever brought the member's answer back, so a Suchak
 * was handling a match they could not hear. These tests pin the half that
 * closes it — and pin that it is the SAME chat engine, not a second one:
 *
 *  • the member's reply is visible to the Suchak, on the request itself
 *  • the Suchak's own relayed reply is attributed to the SUCHAK, not to the
 *    candidate whose profile carries it
 *  • another Suchak's conversation is a 403, always
 *  • a revoked consent closes reading, exactly as it closes replying
 *  • sending runs the existing ChatPolicyService — a reply-gate cooldown is
 *    surfaced, never bypassed
 *  • the unread count reflects unread MEMBER messages, and clears on read
 *  • no candidate phone number appears in any payload
 */
class SuchakChatReadApiTest extends TestCase
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

    public function test_the_suchak_sees_the_members_reply_and_their_own_words_as_their_own(): void
    {
        $fixture = $this->fixture();
        $requestId = $this->memberCreatesRequest($fixture, 'Hi, मला हे स्थळ आवडले आहे');

        // The Suchak answers — the existing one-way half of the pipeline.
        Sanctum::actingAs($fixture['account']->user);
        $this->postJson("/api/v1/suchak/profile-requests/{$requestId}/reply", [
            'reply_message' => 'तिचे शिक्षण B.Ed आहे.',
        ])->assertOk();

        // The member writes back. This is the message that used to vanish.
        Sanctum::actingAs($fixture['member']);
        $conversationId = (int) SuchakProfileRequest::query()
            ->whereKey($requestId)
            ->value('chat_conversation_id');
        $this->postJson("/api/v1/chats/{$conversationId}/messages", [
            'body_text' => 'okay, चालेल',
        ])->assertOk();

        Sanctum::actingAs($fixture['account']->user);
        $thread = $this->getJson("/api/v1/suchak/chats/{$conversationId}")->assertOk();

        $messages = $thread->json('data.messages');
        $this->assertCount(3, $messages, 'The whole exchange must be readable, not just the opening line.');

        // 1. the member's original request
        $this->assertSame(SuchakChatThreadService::AUTHOR_MEMBER, $messages[0]['author_role']);
        $this->assertStringContainsString('मला हे स्थळ आवडले आहे', $messages[0]['body_text']);

        // 2. the Suchak's own reply. It is STORED as sent by the candidate's
        // profile with the "सूचकांकडून संदेश (…)" prefix; if it is rendered raw
        // the Suchak reads their own words as somebody else's.
        $this->assertSame(SuchakChatThreadService::AUTHOR_SUCHAK, $messages[1]['author_role']);
        $this->assertSame('Adarsh Vivah Kendra', $messages[1]['author_label']);
        $this->assertSame('तिचे शिक्षण B.Ed आहे.', $messages[1]['body_text']);
        $this->assertStringNotContainsString('सूचकांकडून संदेश', $messages[1]['body_text']);

        // 3. the member's reply — the whole point of this feature.
        $this->assertSame(SuchakChatThreadService::AUTHOR_MEMBER, $messages[2]['author_role']);
        $this->assertSame('okay, चालेल', $messages[2]['body_text']);

        // A conversation never carries a contact number.
        $this->assertStringNotContainsString('9876500002', json_encode($thread->json(), JSON_THROW_ON_ERROR));
    }

    public function test_the_request_detail_screen_carries_the_whole_exchange(): void
    {
        $fixture = $this->fixture();
        $requestId = $this->memberCreatesRequest($fixture, 'Hi');

        Sanctum::actingAs($fixture['account']->user);
        $this->postJson("/api/v1/suchak/profile-requests/{$requestId}/reply", [
            'reply_message' => 'नक्की, कळवतो.',
        ])->assertOk();

        Sanctum::actingAs($fixture['member']);
        $conversationId = (int) SuchakProfileRequest::query()
            ->whereKey($requestId)
            ->value('chat_conversation_id');
        $this->postJson("/api/v1/chats/{$conversationId}/messages", [
            'body_text' => 'धन्यवाद',
        ])->assertOk();

        // The screen the Suchak is actually looking at must show it, without
        // them having to go and find a separate chat surface.
        Sanctum::actingAs($fixture['account']->user);
        $detail = $this->getJson("/api/v1/suchak/profile-requests/{$requestId}")->assertOk();

        $messages = $detail->json('data.chat.messages');
        $this->assertNotNull($messages, 'The request detail must carry the conversation.');
        $this->assertCount(3, $messages);
        $this->assertSame(SuchakChatThreadService::AUTHOR_SUCHAK, $messages[1]['author_role']);
        $this->assertSame('नक्की, कळवतो.', $messages[1]['body_text']);
        $this->assertSame('धन्यवाद', $messages[2]['body_text']);
    }

    public function test_a_suchak_cannot_open_a_conversation_belonging_to_another_suchaks_request(): void
    {
        $mine = $this->fixture();
        $theirs = $this->fixture('9876511001', '9876511002', '9876511003');

        $theirRequestId = $this->memberCreatesRequest($theirs, 'Interested.');
        $conversationId = (int) SuchakProfileRequest::query()
            ->whereKey($theirRequestId)
            ->value('chat_conversation_id');

        Sanctum::actingAs($mine['account']->user);
        $this->getJson("/api/v1/suchak/chats/{$conversationId}")->assertStatus(403);
        $this->postJson("/api/v1/suchak/chats/{$conversationId}/messages", [
            'body_text' => 'Not mine to answer.',
        ])->assertStatus(403);

        // And it never appears in their inbox in the first place.
        $this->getJson('/api/v1/suchak/chats')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_a_revoked_consent_closes_reading_exactly_as_it_closes_replying(): void
    {
        $fixture = $this->fixture();
        $requestId = $this->memberCreatesRequest($fixture, 'Interested.');
        $conversationId = (int) SuchakProfileRequest::query()
            ->whereKey($requestId)
            ->value('chat_conversation_id');

        Sanctum::actingAs($fixture['account']->user);
        $this->getJson("/api/v1/suchak/chats/{$conversationId}")->assertOk();

        // Consent is what makes the Suchak the person's representative.
        $fixture['representation']->forceFill([
            'consent_valid_until' => now()->subDay(),
        ])->save();

        $this->getJson("/api/v1/suchak/chats/{$conversationId}")->assertStatus(403);
        $this->getJson('/api/v1/suchak/chats')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_sending_works_and_still_obeys_the_existing_reply_gate(): void
    {
        $fixture = $this->fixture();
        $requestId = $this->memberCreatesRequest($fixture, 'Interested.');
        $conversationId = (int) SuchakProfileRequest::query()
            ->whereKey($requestId)
            ->value('chat_conversation_id');

        Sanctum::actingAs($fixture['account']->user);

        $sent = $this->postJson("/api/v1/suchak/chats/{$conversationId}/messages", [
            'body_text' => 'पहिला संदेश',
        ])->assertOk();

        // Sent as the Suchak, carrying the same prefix the /reply route writes.
        $sent->assertJsonPath('data.chat_message.author_role', SuchakChatThreadService::AUTHOR_SUCHAK);
        $sent->assertJsonPath('data.chat_message.body_text', 'पहिला संदेश');

        // The reply gate is admin policy applied by the SAME ChatPolicyService a
        // member goes through. The Suchak gets no exemption from it and no extra
        // gate either — only its own admin-configured threshold, because a
        // professional intermediary following up with a family is not the
        // harassment case the gate exists for.
        $maxConsecutive = CommunicationPolicyService::replyGateLimitsFor(
            CommunicationPolicyService::ACTOR_SUCHAK
        )['max_consecutive'];

        for ($i = 1; $i < $maxConsecutive; $i++) {
            $this->postJson("/api/v1/suchak/chats/{$conversationId}/messages", [
                'body_text' => 'पुन्हा '.$i,
            ])->assertOk();
        }

        $blocked = $this->postJson("/api/v1/suchak/chats/{$conversationId}/messages", [
            'body_text' => 'आणखी एक',
        ])->assertStatus(422);
        $this->assertNotEmpty($blocked->json('message'));

        // And the block is reported to the app rather than hidden, so the screen
        // can explain itself instead of looking broken.
        $this->getJson("/api/v1/suchak/chats/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('data.can_send.allowed', false);
    }

    public function test_unread_count_reflects_unread_member_messages_and_clears_on_read(): void
    {
        $fixture = $this->fixture();
        $requestId = $this->memberCreatesRequest($fixture, 'Interested.');
        $conversationId = (int) SuchakProfileRequest::query()
            ->whereKey($requestId)
            ->value('chat_conversation_id');

        Sanctum::actingAs($fixture['account']->user);
        $this->getJson('/api/v1/suchak/chats/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        Sanctum::actingAs($fixture['member']);
        $this->postJson("/api/v1/chats/{$conversationId}/messages", [
            'body_text' => 'अजून एक प्रश्न',
        ])->assertOk();

        Sanctum::actingAs($fixture['account']->user);
        $this->getJson('/api/v1/suchak/chats/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);

        $this->getJson('/api/v1/suchak/chats')
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.conversations.0.unread_count', 2);

        $this->postJson("/api/v1/suchak/chats/{$conversationId}/read")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function memberCreatesRequest(array $fixture, string $message): int
    {
        Sanctum::actingAs($fixture['member']);

        return (int) $this->postJson(
            "/api/v1/matrimony-profiles/{$fixture['target_profile']->id}/suchak-requests",
            ['message' => $message],
        )->assertCreated()->json('data.suchak_request.id');
    }
}
