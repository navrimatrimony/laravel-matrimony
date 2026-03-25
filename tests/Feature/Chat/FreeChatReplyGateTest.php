<?php

namespace Tests\Feature\Chat;

use App\Models\AdminSetting;
use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreeChatReplyGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_sender_can_send_two_messages_but_third_is_blocked_without_reply(): void
    {
        $aUser = User::factory()->create();
        $bUser = User::factory()->create();

        $a = MatrimonyProfile::factory()->create([
            'user_id' => $aUser->id,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);
        $b = MatrimonyProfile::factory()->create([
            'user_id' => $bUser->id,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        // Defaults: free_chat_with_reply_gate, max consecutive = 2
        $this->actingAs($aUser)
            ->post(route('chat.start', ['matrimony_profile' => $b->id]))
            ->assertRedirect();

        $conversation = Conversation::firstOrFail();

        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conversation->id]), [
            'body_text' => 'Hi 1',
        ])->assertRedirect();

        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conversation->id]), [
            'body_text' => 'Hi 2',
        ])->assertRedirect();

        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conversation->id]), [
            'body_text' => 'Hi 3',
        ])->assertRedirect()->assertSessionHasErrors('policy');
    }

    public function test_reply_clears_reply_gate_immediately(): void
    {
        $aUser = User::factory()->create();
        $bUser = User::factory()->create();

        $a = MatrimonyProfile::factory()->create(['user_id' => $aUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $b = MatrimonyProfile::factory()->create(['user_id' => $bUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);

        $this->actingAs($aUser)->post(route('chat.start', ['matrimony_profile' => $b->id]))->assertRedirect();
        $conversation = Conversation::firstOrFail();

        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conversation->id]), ['body_text' => 'A1'])->assertRedirect();
        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conversation->id]), ['body_text' => 'A2'])->assertRedirect();

        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conversation->id]), ['body_text' => 'A3'])
            ->assertRedirect()->assertSessionHasErrors('policy');

        // Receiver replies
        $this->actingAs($bUser)->post(route('chat.messages.text', ['conversation' => $conversation->id]), ['body_text' => 'B1'])->assertRedirect();

        // Sender can send again
        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conversation->id]), ['body_text' => 'A4'])->assertRedirect();
    }

    public function test_cooldown_expiry_allows_fresh_quota_without_reply(): void
    {
        AdminSetting::setValue('communication_max_consecutive_messages_without_reply', '2');
        AdminSetting::setValue('communication_reply_gate_cooling_hours', '1');

        $aUser = User::factory()->create();
        $bUser = User::factory()->create();
        $a = MatrimonyProfile::factory()->create(['user_id' => $aUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $b = MatrimonyProfile::factory()->create(['user_id' => $bUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);

        $now = now()->startOfHour();
        $this->travelTo($now);

        $this->actingAs($aUser)->post(route('chat.start', ['matrimony_profile' => $b->id]))->assertRedirect();
        $conversation = Conversation::firstOrFail();

        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conversation->id]), ['body_text' => 'A1'])->assertRedirect();
        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conversation->id]), ['body_text' => 'A2'])->assertRedirect();

        // Immediately blocked
        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conversation->id]), ['body_text' => 'A3'])
            ->assertRedirect()->assertSessionHasErrors('policy');

        // After cooldown expires, sender can send again even without reply (fresh quota)
        $this->travelTo($now->copy()->addHours(2));
        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conversation->id]), ['body_text' => 'A4'])->assertRedirect();
        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conversation->id]), ['body_text' => 'A5'])->assertRedirect();

        // Now blocked again until next cooldown
        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conversation->id]), ['body_text' => 'A6'])
            ->assertRedirect()->assertSessionHasErrors('policy');
    }

    public function test_reply_gate_is_pair_specific_sender_can_message_others(): void
    {
        $aUser = User::factory()->create();
        $bUser = User::factory()->create();
        $cUser = User::factory()->create();

        $a = MatrimonyProfile::factory()->create(['user_id' => $aUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $b = MatrimonyProfile::factory()->create(['user_id' => $bUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $c = MatrimonyProfile::factory()->create(['user_id' => $cUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);

        $this->actingAs($aUser)->post(route('chat.start', ['matrimony_profile' => $b->id]))->assertRedirect();
        $convAB = Conversation::firstOrFail();

        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $convAB->id]), ['body_text' => 'AB1'])->assertRedirect();
        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $convAB->id]), ['body_text' => 'AB2'])->assertRedirect();
        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $convAB->id]), ['body_text' => 'AB3'])
            ->assertRedirect()->assertSessionHasErrors('policy');

        // Still allowed to chat with C
        $this->actingAs($aUser)->post(route('chat.start', ['matrimony_profile' => $c->id]))->assertRedirect();
        $convAC = Conversation::query()->orderByDesc('id')->firstOrFail();
        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $convAC->id]), ['body_text' => 'AC1'])->assertRedirect();
    }
}

