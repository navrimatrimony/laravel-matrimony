<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\ShowcaseChatSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcaseChatTagTest extends TestCase
{
    use RefreshDatabase;

    public function test_showcase_conversation_shows_ai_assisted_replies_tag(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $real = MatrimonyProfile::factory()->create(['user_id' => $userA->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $showcase = MatrimonyProfile::factory()->create(['user_id' => $userB->id, 'lifecycle_state' => 'active', 'is_suspended' => false, 'is_showcase' => true]);

        ShowcaseChatSetting::create([
            'matrimony_profile_id' => $showcase->id,
            'enabled' => true,
            'ai_assisted_replies_enabled' => true,
        ]);

        $this->actingAs($userA)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        $this->actingAs($userA)
            ->get(route('chat.show', ['conversation' => $conv->id]))
            ->assertOk()
            ->assertSee('AI Assisted Replies');
    }

    public function test_non_showcase_conversation_does_not_show_tag(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $a = MatrimonyProfile::factory()->create(['user_id' => $userA->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $b = MatrimonyProfile::factory()->create(['user_id' => $userB->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);

        $this->actingAs($userA)->post(route('chat.start', ['matrimony_profile' => $b->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        $this->actingAs($userA)
            ->get(route('chat.show', ['conversation' => $conv->id]))
            ->assertOk()
            ->assertDontSee('AI Assisted Replies');
    }
}

