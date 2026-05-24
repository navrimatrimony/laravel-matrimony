<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\Message;
use App\Models\Plan;
use App\Models\User;
use App\Services\FeatureUsageService;
use App\Services\MemberQuickHubService;
use App\Services\SubscriptionService;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ChatDockAndReadLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_dock_renders_empty_panel_when_composer_data_is_missing(): void
    {
        $user = User::factory()->create();
        MatrimonyProfile::withoutEvents(function () use ($user): void {
            MatrimonyProfile::factory()->create([
                'user_id' => $user->id,
                'lifecycle_state' => 'active',
                'is_suspended' => false,
            ]);
        });

        $this->actingAs($user)
            ->view('partials.chat-dock-widget', ['chatDockData' => null])
            ->assertSee('id="chatPanel"', false)
            ->assertSee('id="chat-dock-root"', false)
            ->assertSee('"can_read_incoming":false', false);
    }

    public function test_free_plan_locks_incoming_message_text_preview(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        [$viewerUser, , $conversation] = $this->conversationWithIncomingMessage('Secret incoming text');

        $this->subscribeToPlan($viewerUser, 'free_male');

        $response = $this->actingAs($viewerUser)
            ->getJson(route('chat.show', ['conversation' => $conversation->id, 'since_id' => 0]));

        $response->assertOk();
        $html = implode("\n", $response->json('html') ?? []);

        $this->assertStringContainsString(__('chat_ui.read_locked_new_message'), $html);
        $this->assertStringContainsString('Anand Shinde sent you a message', $html);
        $this->assertStringContainsString(__('chat_ui.read_lock_can_still_reply'), $html);
        $this->assertStringNotContainsString('From Anand Shinde', $html);
        $this->assertStringNotContainsString('From A*****', $html);
        $this->assertStringNotContainsString('Secret incoming text', $html);
    }

    public function test_chat_dock_keeps_rows_when_plan_read_check_fails(): void
    {
        [$viewerUser, ,] = $this->conversationWithIncomingMessage('Do not leak this text');

        $this->mock(FeatureUsageService::class, function ($mock): void {
            $mock->shouldReceive('canUse')
                ->withAnyArgs()
                ->andThrow(new RuntimeException('quota policy unavailable'));
        });

        $dock = app(MemberQuickHubService::class)->buildChatDockForUser($viewerUser);

        $this->assertIsArray($dock);
        $this->assertSame(1, $dock['unread_count']);
        $this->assertCount(1, $dock['chats']);
        $this->assertCount(1, $dock['unread']);
        $this->assertGreaterThan(0, $dock['chats'][0]['profile_id']);
        $this->assertNotSame('Member', $dock['chats'][0]['name']);
        $this->assertSame(__('chat_ui.read_locked_preview'), $dock['chats'][0]['preview']);
        $this->assertStringNotContainsString('Do not leak this text', $dock['chats'][0]['preview']);
    }

    public function test_basic_paid_plan_can_read_incoming_message_text(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        [$viewerUser, , $conversation] = $this->conversationWithIncomingMessage('Readable incoming text');

        $this->subscribeToPlan($viewerUser, 'basic_male');

        $response = $this->actingAs($viewerUser)
            ->getJson(route('chat.show', ['conversation' => $conversation->id, 'since_id' => 0]));

        $response->assertOk();
        $html = implode("\n", $response->json('html') ?? []);

        $this->assertStringContainsString('Readable incoming text', $html);
        $this->assertStringNotContainsString(__('chat_ui.read_locked_body'), $html);
    }

    /**
     * @return array{0: User, 1: User, 2: Conversation}
     */
    private function conversationWithIncomingMessage(string $body): array
    {
        $viewerUser = User::factory()->create();
        $senderUser = User::factory()->create();

        [$viewerProfile, $senderProfile] = MatrimonyProfile::withoutEvents(function () use ($viewerUser, $senderUser): array {
            return [
                MatrimonyProfile::factory()->create([
                    'user_id' => $viewerUser->id,
                    'lifecycle_state' => 'active',
                    'is_suspended' => false,
                ]),
                MatrimonyProfile::factory()->create([
                    'user_id' => $senderUser->id,
                    'full_name' => 'Anand Shinde',
                    'lifecycle_state' => 'active',
                    'is_suspended' => false,
                ]),
            ];
        });

        [$one, $two] = Conversation::normalizePairIds((int) $viewerProfile->id, (int) $senderProfile->id);
        $conversation = Conversation::query()->create([
            'profile_one_id' => $one,
            'profile_two_id' => $two,
            'created_by_profile_id' => $senderProfile->id,
            'status' => Conversation::STATUS_ACTIVE,
            'last_message_at' => now(),
        ]);

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_profile_id' => $senderProfile->id,
            'receiver_profile_id' => $viewerProfile->id,
            'message_type' => Message::TYPE_TEXT,
            'body_text' => $body,
            'sent_at' => now(),
            'delivery_status' => Message::DELIVERY_SENT,
        ]);

        $conversation->update([
            'last_message_id' => $message->id,
            'last_message_at' => $message->sent_at,
        ]);

        return [$viewerUser, $senderUser, $conversation];
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
