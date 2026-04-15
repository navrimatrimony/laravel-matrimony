<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\Message;
use App\Models\ShowcaseChatSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatMessageModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function createPair(): array
    {
        $aUser = User::factory()->create();
        $bUser = User::factory()->create();
        $a = MatrimonyProfile::factory()->create(['user_id' => $aUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $b = MatrimonyProfile::factory()->create(['user_id' => $bUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);

        return [$aUser, $bUser, $a, $b];
    }

    public function test_clean_message_sends_successfully(): void
    {
        [$aUser, $bUser, $a, $b] = $this->createPair();

        $this->actingAs($aUser)->post(route('chat.start', ['matrimony_profile' => $b->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), [
            'body_text' => 'Namaste, kasa ahes?',
        ])->assertRedirect()->assertSessionMissing('errors');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conv->id,
            'body_text' => 'Namaste, kasa ahes?',
        ]);
    }

    public function test_severe_sexual_content_is_blocked_pre_send(): void
    {
        [$aUser, $bUser, $a, $b] = $this->createPair();

        $this->actingAs($aUser)->post(route('chat.start', ['matrimony_profile' => $b->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), [
            'body_text' => 'sex chat now please',
        ])->assertRedirect()->assertSessionHasErrors('body_text');

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_caste_religion_abusive_message_is_blocked_pre_send(): void
    {
        [$aUser, $bUser, $a, $b] = $this->createPair();

        $this->actingAs($aUser)->post(route('chat.start', ['matrimony_profile' => $b->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), [
            'body_text' => 'your caste is dirty and you know it',
        ])->assertRedirect()->assertSessionHasErrors('body_text');

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_solicitation_phrase_triggers_pre_send_validation_error(): void
    {
        [$aUser, $bUser, $a, $b] = $this->createPair();

        $this->actingAs($aUser)->post(route('chat.start', ['matrimony_profile' => $b->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), [
            'body_text' => 'video call now',
        ])->assertRedirect()->assertSessionHasErrors('body_text');

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_harmless_sentence_with_family_photo_context_is_not_blocked(): void
    {
        [$aUser, $bUser, $a, $b] = $this->createPair();

        $this->actingAs($aUser)->post(route('chat.start', ['matrimony_profile' => $b->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), [
            'body_text' => 'Namaste, family photo nantar share karu.',
        ])->assertRedirect()->assertSessionMissing('errors');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conv->id,
        ]);
    }

    public function test_post_save_render_masks_suspicious_message_saved_without_pre_send_guard(): void
    {
        [$aUser, $bUser, $a, $b] = $this->createPair();

        $this->actingAs($aUser)->post(route('chat.start', ['matrimony_profile' => $b->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        $msg = Message::create([
            'conversation_id' => $conv->id,
            'sender_profile_id' => $a->id,
            'receiver_profile_id' => $b->id,
            'message_type' => Message::TYPE_TEXT,
            'body_text' => 'send nudes',
            'image_path' => null,
            'sent_at' => now(),
            'read_at' => null,
            'delivery_status' => Message::DELIVERY_SENT,
        ]);

        $conv->update([
            'last_message_id' => $msg->id,
            'last_message_at' => $msg->sent_at,
        ]);

        $response = $this->actingAs($bUser)->get(route('chat.show', ['conversation' => $conv->id]));
        $response->assertOk();
        $response->assertSee('हा संदेश उपलब्ध नाही.', false);
        $response->assertDontSee('send nudes', false);
    }

    public function test_admin_showcase_conversation_shows_filtered_indicator_for_flagged_message(): void
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

        $msg = Message::create([
            'conversation_id' => $conv->id,
            'sender_profile_id' => $real->id,
            'receiver_profile_id' => $showcase->id,
            'message_type' => Message::TYPE_TEXT,
            'body_text' => 'send nudes',
            'image_path' => null,
            'sent_at' => now(),
            'read_at' => null,
            'delivery_status' => Message::DELIVERY_SENT,
        ]);
        $conv->update([
            'last_message_id' => $msg->id,
            'last_message_at' => $msg->sent_at,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.showcase-conversations.show', ['conversation' => $conv->id]));
        $response->assertOk();
        $response->assertSee('Filtered', false);
        $response->assertSee('Safety filter applied:', false);
        $response->assertSee('send nudes', false);
    }

    public function test_end_user_chat_page_does_not_expose_internal_category_names(): void
    {
        [$aUser, $bUser, $a, $b] = $this->createPair();

        $this->actingAs($aUser)->post(route('chat.start', ['matrimony_profile' => $b->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        $msg = Message::create([
            'conversation_id' => $conv->id,
            'sender_profile_id' => $a->id,
            'receiver_profile_id' => $b->id,
            'message_type' => Message::TYPE_TEXT,
            'body_text' => 'send nudes',
            'image_path' => null,
            'sent_at' => now(),
            'read_at' => null,
            'delivery_status' => Message::DELIVERY_SENT,
        ]);
        $conv->update([
            'last_message_id' => $msg->id,
            'last_message_at' => $msg->sent_at,
        ]);

        $response = $this->actingAs($bUser)->get(route('chat.show', ['conversation' => $conv->id]));
        $response->assertOk();
        $response->assertDontSee('sexual_explicit', false);
        $response->assertDontSee('hate_caste_religion', false);
    }
}
