<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\Message;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\ChatMessageLockedNotification;
use App\Notifications\NewChatMessageNotification;
use App\Services\Chat\ChatMessageService;
use App\Services\SubscriptionService;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ChatMisleadingNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_lock_banner_hidden_when_only_own_outgoing_messages_exist(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $senderUser = User::factory()->create();
        $receiverUser = User::factory()->create();

        [$senderProfile, $receiverProfile] = MatrimonyProfile::withoutEvents(function () use ($senderUser, $receiverUser): array {
            return [
                MatrimonyProfile::factory()->create([
                    'user_id' => $senderUser->id,
                    'full_name' => 'Sender A',
                    'lifecycle_state' => 'active',
                    'is_suspended' => false,
                ]),
                MatrimonyProfile::factory()->create([
                    'user_id' => $receiverUser->id,
                    'full_name' => 'Receiver B',
                    'lifecycle_state' => 'active',
                    'is_suspended' => false,
                ]),
            ];
        });

        [$one, $two] = Conversation::normalizePairIds((int) $senderProfile->id, (int) $receiverProfile->id);
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
            'receiver_profile_id' => $receiverProfile->id,
            'message_type' => Message::TYPE_TEXT,
            'body_text' => 'Hello from A only',
            'sent_at' => now(),
            'delivery_status' => Message::DELIVERY_SENT,
        ]);
        $conversation->update(['last_message_id' => $message->id, 'last_message_at' => $message->sent_at]);

        $this->subscribeToPlan($senderUser, 'free_male');

        $response = $this->actingAs($senderUser)
            ->get(route('chat.show', ['conversation' => $conversation->id]));

        $response->assertOk();
        $response->assertDontSee(__('chat_ui.read_lock_sender_title', ['name' => 'Receiver B']), false);
        $response->assertDontSee('replied to you', false);
    }

    public function test_sender_does_not_receive_chat_notification_when_sending(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        Notification::fake();

        $senderUser = User::factory()->create();
        $receiverUser = User::factory()->create();

        [$senderProfile, $receiverProfile] = MatrimonyProfile::withoutEvents(function () use ($senderUser, $receiverUser): array {
            return [
                MatrimonyProfile::factory()->create([
                    'user_id' => $senderUser->id,
                    'lifecycle_state' => 'active',
                    'is_suspended' => false,
                ]),
                MatrimonyProfile::factory()->create([
                    'user_id' => $receiverUser->id,
                    'lifecycle_state' => 'active',
                    'is_suspended' => false,
                ]),
            ];
        });

        [$one, $two] = Conversation::normalizePairIds((int) $senderProfile->id, (int) $receiverProfile->id);
        $conversation = Conversation::query()->create([
            'profile_one_id' => $one,
            'profile_two_id' => $two,
            'created_by_profile_id' => $senderProfile->id,
            'status' => Conversation::STATUS_ACTIVE,
            'last_message_at' => now(),
        ]);

        $this->subscribeToPlan($senderUser, 'basic_male');
        $this->subscribeToPlan($receiverUser, 'free_male');

        app(ChatMessageService::class)->sendTextMessage($senderProfile, $receiverProfile, $conversation, 'Hi B');

        Notification::assertNotSentTo($senderUser, NewChatMessageNotification::class);
        Notification::assertNotSentTo($senderUser, ChatMessageLockedNotification::class);
        Notification::assertSentTo($receiverUser, ChatMessageLockedNotification::class);
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
