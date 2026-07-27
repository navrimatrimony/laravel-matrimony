<?php

namespace Tests\Feature\Suchak;

use App\Models\AdminSetting;
use App\Models\MessagePolicyCooldown;
use App\Models\SuchakProfileRequest;
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
 * One reply gate, two thresholds.
 *
 * A member sending repeatedly is the harassment case the gate exists for. A
 * Suchak is a professional intermediary whose job IS to follow up with families
 * across many conversations, so the member numbers (2 messages / 96h) stop
 * legitimate work. The Suchak gets 4 / 24h — the SAME gate, the same service,
 * the same cooldown table, only a role-scoped threshold.
 *
 * The hard part these tests pin: a Suchak's reply is relayed into the
 * member↔candidate conversation under the CANDIDATE's profile, so
 * `sender_profile_id` cannot distinguish the two. The acting role travels with
 * the call instead, and the trailing counter stays SHARED per profile pair so
 * the member on the receiving end never faces two quotas back to back.
 */
class SuchakReplyGateThresholdTest extends TestCase
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

        // Production's member numbers. The Suchak pair is deliberately left on
        // its shipped defaults so this also proves the defaults.
        AdminSetting::setValue('communication_max_consecutive_messages_without_reply', '2');
        AdminSetting::setValue('communication_reply_gate_cooling_hours', '96');
    }

    public function test_the_two_thresholds_are_admin_configurable_and_role_scoped(): void
    {
        $member = CommunicationPolicyService::replyGateLimitsFor(CommunicationPolicyService::ACTOR_MEMBER);
        $this->assertSame(2, $member['max_consecutive']);
        $this->assertSame(96, $member['cooling_hours']);

        $suchak = CommunicationPolicyService::replyGateLimitsFor(CommunicationPolicyService::ACTOR_SUCHAK);
        $this->assertSame(4, $suchak['max_consecutive']);
        $this->assertSame(24, $suchak['cooling_hours']);

        // Admin-configurable through the same AdminSetting mechanism as the rest.
        AdminSetting::setValue('communication_suchak_max_consecutive_messages_without_reply', '6');
        AdminSetting::setValue('communication_suchak_reply_gate_cooling_hours', '12');

        $suchak = CommunicationPolicyService::replyGateLimitsFor(CommunicationPolicyService::ACTOR_SUCHAK);
        $this->assertSame(6, $suchak['max_consecutive']);
        $this->assertSame(12, $suchak['cooling_hours']);

        // …and the member numbers do not move when the Suchak ones do.
        $this->assertSame(2, CommunicationPolicyService::replyGateLimitsFor(CommunicationPolicyService::ACTOR_MEMBER)['max_consecutive']);
    }

    public function test_the_limit_message_names_the_acting_roles_own_number_in_latin_digits(): void
    {
        $suchakLine = (string) __('chat_ui.reply_gate_limit', [
            'max' => (string) CommunicationPolicyService::replyGateLimitsFor(CommunicationPolicyService::ACTOR_SUCHAK)['max_consecutive'],
        ]);
        $memberLine = (string) __('chat_ui.reply_gate_limit', [
            'max' => (string) CommunicationPolicyService::replyGateLimitsFor(CommunicationPolicyService::ACTOR_MEMBER)['max_consecutive'],
        ]);

        $this->assertStringContainsString('4', $suchakLine);
        $this->assertStringNotContainsString('2', $suchakLine);
        $this->assertStringContainsString('2', $memberLine);

        // Frozen rule: never Devanagari digits, in any locale.
        foreach ([$suchakLine, $memberLine, (string) __('chat_ui.reply_gate_cooldown')] as $line) {
            $this->assertDoesNotMatchRegularExpression('/[\x{0966}-\x{096F}]/u', $line);
        }

        // The cooldown wording promises no duration, so it stays true for a
        // 24-hour Suchak lock and a 96-hour member lock alike.
        $this->assertStringNotContainsString('96', (string) __('chat_ui.reply_gate_cooldown'));
        $this->assertStringNotContainsString('24', (string) __('chat_ui.reply_gate_cooldown'));
    }

    public function test_a_suchak_sends_four_relayed_messages_and_is_blocked_on_the_fifth_for_24_hours(): void
    {
        $now = now()->startOfHour();
        $this->travelTo($now);

        $fixture = $this->fixture();
        $requestId = $this->memberCreatesRequest($fixture, 'मला हे स्थळ आवडले आहे');
        $conversationId = $this->conversationId($requestId);

        Sanctum::actingAs($fixture['account']->user);

        // Four consecutive follow-ups with no member reply. Under the member
        // numbers the third would already have been refused.
        foreach (range(1, 4) as $i) {
            $this->postJson("/api/v1/suchak/chats/{$conversationId}/messages", [
                'body_text' => 'पाठपुरावा '.$i,
            ])->assertOk();
        }

        $this->postJson("/api/v1/suchak/chats/{$conversationId}/messages", [
            'body_text' => 'पाचवा',
        ])->assertStatus(422);

        // The lock is on the relayed identity (the candidate's profile) because
        // that is who the member sees writing — and it lasts the Suchak's 24
        // hours, not the member's 96.
        $cooldown = MessagePolicyCooldown::query()
            ->where('sender_profile_id', $fixture['target_profile']->id)
            ->where('receiver_profile_id', $fixture['member_profile']->id)
            ->where('reason', MessagePolicyCooldown::REASON_REPLY_GATE_LIMIT)
            ->firstOrFail();

        $this->assertTrue($cooldown->locked_until->equalTo($now->copy()->addHours(24)));

        // The Suchak app's own "can I send?" preview agrees with the send.
        $this->getJson("/api/v1/suchak/chats/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('data.can_send.allowed', false);
    }

    public function test_a_member_is_still_blocked_on_the_third_message_for_96_hours(): void
    {
        $now = now()->startOfHour();
        $this->travelTo($now);

        $fixture = $this->fixture();
        // The request itself is the member's first message in this conversation.
        $requestId = $this->memberCreatesRequest($fixture, 'पहिला संदेश');
        $conversationId = $this->conversationId($requestId);

        Sanctum::actingAs($fixture['member']);
        $this->postJson("/api/v1/chats/{$conversationId}/messages", [
            'body_text' => 'दुसरा संदेश',
        ])->assertOk();

        $this->postJson("/api/v1/chats/{$conversationId}/messages", [
            'body_text' => 'तिसरा संदेश',
        ])->assertStatus(422);

        $cooldown = MessagePolicyCooldown::query()
            ->where('sender_profile_id', $fixture['member_profile']->id)
            ->where('receiver_profile_id', $fixture['target_profile']->id)
            ->where('reason', MessagePolicyCooldown::REASON_REPLY_GATE_LIMIT)
            ->firstOrFail();

        $this->assertTrue($cooldown->locked_until->equalTo($now->copy()->addHours(96)));
    }

    public function test_the_candidates_own_account_shares_the_counter_and_keeps_the_member_wording(): void
    {
        $fixture = $this->fixture();
        $requestId = $this->memberCreatesRequest($fixture, 'नमस्कार');
        $conversationId = $this->conversationId($requestId);

        // Three relayed follow-ups: allowed for the Suchak (under 4), and no
        // lock exists yet.
        Sanctum::actingAs($fixture['account']->user);
        foreach (range(1, 3) as $i) {
            $this->postJson("/api/v1/suchak/chats/{$conversationId}/messages", [
                'body_text' => 'पाठपुरावा '.$i,
            ])->assertOk();
        }

        // The candidate now writes on their OWN account. Same profile id, so the
        // three relayed messages count against them — they are already past the
        // member threshold and are refused with the MEMBER's number, not 4.
        // Separate counters here would let the pair send 2 + 4 in a row at the
        // member on the other end; that would be widening the gate, not moving it.
        Sanctum::actingAs($fixture['candidate']);
        $blocked = $this->postJson("/api/v1/chats/{$conversationId}/messages", [
            'body_text' => 'माझा स्वतःचा संदेश',
        ])->assertStatus(422);

        $this->assertStringContainsString(
            (string) __('chat_ui.reply_gate_limit', ['max' => '2']),
            (string) json_encode($blocked->json(), JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_a_reply_from_the_member_clears_the_gate_for_the_suchak(): void
    {
        $fixture = $this->fixture();
        $requestId = $this->memberCreatesRequest($fixture, 'नमस्कार');
        $conversationId = $this->conversationId($requestId);

        Sanctum::actingAs($fixture['account']->user);
        foreach (range(1, 4) as $i) {
            $this->postJson("/api/v1/suchak/chats/{$conversationId}/messages", [
                'body_text' => 'पाठपुरावा '.$i,
            ])->assertOk();
        }
        $this->postJson("/api/v1/suchak/chats/{$conversationId}/messages", [
            'body_text' => 'पाचवा',
        ])->assertStatus(422);

        // One answer from the other side, and the gate is gone — same clearing
        // path both roles have always used.
        Sanctum::actingAs($fixture['member']);
        $this->postJson("/api/v1/chats/{$conversationId}/messages", [
            'body_text' => 'हो, चालेल',
        ])->assertOk();

        Sanctum::actingAs($fixture['account']->user);
        $this->postJson("/api/v1/suchak/chats/{$conversationId}/messages", [
            'body_text' => 'धन्यवाद',
        ])->assertOk();
    }

    private function conversationId(int $requestId): int
    {
        return (int) SuchakProfileRequest::query()
            ->whereKey($requestId)
            ->value('chat_conversation_id');
    }

    private function memberCreatesRequest(array $fixture, string $message): int
    {
        Sanctum::actingAs($fixture['member']);

        return (int) $this->postJson(
            "/api/v1/matrimony-profiles/{$fixture['target_profile']->id}/suchak-requests",
            ['message' => $message],
        )->assertCreated()->json('data.suchak_request.id');
    }
}
