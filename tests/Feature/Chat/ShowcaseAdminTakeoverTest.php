<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MatrimonyProfile;
use App\Models\ShowcaseChatSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcaseAdminTakeoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reply_as_showcase_and_message_stored_normally(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $realUser = User::factory()->create();
        $showUser = User::factory()->create();

        $real = MatrimonyProfile::factory()->create(['user_id' => $realUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $showcase = MatrimonyProfile::factory()->create(['user_id' => $showUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false, 'is_showcase' => true]);

        ShowcaseChatSetting::create([
            'matrimony_profile_id' => $showcase->id,
            'enabled' => true,
            'ai_assisted_replies_enabled' => true,
            'business_hours_enabled' => false,
        ]);

        // Start conversation and send a user message.
        $this->actingAs($realUser)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();
        $this->actingAs($realUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), ['body_text' => 'Hello'])->assertRedirect();

        // Admin replies as showcase via admin route.
        $this->actingAs($admin)->post(route('admin.showcase-conversations.reply', ['conversation' => $conv->id]), [
            'showcase_profile_id' => $showcase->id,
            'body_text' => 'Thanks for your message.',
        ])->assertRedirect();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conv->id,
            'sender_profile_id' => $showcase->id,
            'receiver_profile_id' => $real->id,
            'message_type' => 'text',
        ]);
    }

    public function test_admin_reply_as_showcase_marks_user_messages_read_like_automated_flow(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $realUser = User::factory()->create();
        $showUser = User::factory()->create();

        $real = MatrimonyProfile::factory()->create(['user_id' => $realUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $showcase = MatrimonyProfile::factory()->create(['user_id' => $showUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false, 'is_showcase' => true]);

        ShowcaseChatSetting::create([
            'matrimony_profile_id' => $showcase->id,
            'enabled' => true,
            'ai_assisted_replies_enabled' => true,
            'business_hours_enabled' => false,
        ]);

        $this->actingAs($realUser)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();
        $this->actingAs($realUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), ['body_text' => 'Hello from user'])->assertRedirect();

        $userMsg = Message::query()
            ->where('conversation_id', $conv->id)
            ->where('receiver_profile_id', $showcase->id)
            ->firstOrFail();
        $this->assertNull($userMsg->read_at);

        $this->actingAs($admin)->post(route('admin.showcase-conversations.reply', ['conversation' => $conv->id]), [
            'showcase_profile_id' => $showcase->id,
            'body_text' => 'Admin showcase reply.',
        ])->assertRedirect();

        $userMsg->refresh();
        $this->assertNotNull($userMsg->read_at);
        $this->assertSame('read', $userMsg->delivery_status);
    }
}

