<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Chat\ChatTemplateSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatTemplateSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_page_renders_suggestion_heading_and_categories(): void
    {
        $aUser = User::factory()->create();
        $bUser = User::factory()->create();
        $a = MatrimonyProfile::factory()->create(['user_id' => $aUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $b = MatrimonyProfile::factory()->create(['user_id' => $bUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);

        $this->actingAs($aUser)->post(route('chat.start', ['matrimony_profile' => $b->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        $response = $this->actingAs($aUser)->get(route('chat.show', ['conversation' => $conv->id]));
        $response->assertOk();
        $response->assertSee('प्रोफाइलवर आधारित सुचवलेले संदेश', false);
        $response->assertSee('ओळख', false);
        $response->assertSee('कुटुंब', false);
        $response->assertSee('करिअर', false);
        $response->assertSee('आणखी', false);
        $response->assertSee('id="chat-template-starters"', false);
    }

    public function test_default_identity_suggestions_present_in_page(): void
    {
        $aUser = User::factory()->create();
        $bUser = User::factory()->create();
        $a = MatrimonyProfile::factory()->create(['user_id' => $aUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $b = MatrimonyProfile::factory()->create([
            'user_id' => $bUser->id,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);
        $b->forceFill(['full_name' => ''])->save();

        $this->actingAs($aUser)->post(route('chat.start', ['matrimony_profile' => $b->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        $response = $this->actingAs($aUser)->get(route('chat.show', ['conversation' => $conv->id]));
        $response->assertOk();
        $response->assertSee('तुमचा प्रोफाइल आवडला', false);
    }

    public function test_profile_aware_introduction_uses_first_name_when_full_name_set(): void
    {
        $aUser = User::factory()->create();
        $bUser = User::factory()->create();
        $a = MatrimonyProfile::factory()->create(['user_id' => $aUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $b = MatrimonyProfile::factory()->create([
            'user_id' => $bUser->id,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'full_name' => 'Anita Patil',
        ]);

        $this->actingAs($aUser)->post(route('chat.start', ['matrimony_profile' => $b->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        $response = $this->actingAs($aUser)->get(route('chat.show', ['conversation' => $conv->id]));
        $response->assertOk();
        $response->assertSee('Anita', false);
        $response->assertSee('ओळख वाढवायला आवडेल', false);
    }

    public function test_service_includes_occupation_in_career_when_present(): void
    {
        $other = MatrimonyProfile::factory()->create([
            'occupation_title' => 'Software Engineer',
            'highest_education' => '',
        ]);

        $svc = app(ChatTemplateSuggestionService::class);
        $groups = $svc->getSuggestionGroupsForConversation($other->fresh());

        $career = implode(' ', $groups['career']['items'] ?? []);
        $this->assertStringContainsString('Software Engineer', $career);
    }

    public function test_service_fallback_when_no_extra_profile_fields(): void
    {
        $other = MatrimonyProfile::factory()->create([
            'occupation_title' => null,
            'highest_education' => null,
            'city_id' => null,
            'taluka_id' => null,
            'district_id' => null,
            'state_id' => null,
        ]);

        $svc = app(ChatTemplateSuggestionService::class);
        $groups = $svc->getSuggestionGroupsForConversation($other);

        $this->assertCount(3, $groups['career']['items']);
        $this->assertStringContainsString('कामाच्या स्वरूपाबद्दल', $groups['career']['items'][0]);
    }

    public function test_send_text_message_still_works_after_suggestions_render(): void
    {
        $aUser = User::factory()->create();
        $bUser = User::factory()->create();
        $a = MatrimonyProfile::factory()->create(['user_id' => $aUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $b = MatrimonyProfile::factory()->create(['user_id' => $bUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);

        $this->actingAs($aUser)->post(route('chat.start', ['matrimony_profile' => $b->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), [
            'body_text' => 'Hello from test',
        ])->assertRedirect();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conv->id,
            'body_text' => 'Hello from test',
        ]);
    }

    public function test_predefined_suggestions_exclude_unsafe_words(): void
    {
        $svc = app(ChatTemplateSuggestionService::class);
        $other = MatrimonyProfile::factory()->create();
        $groups = $svc->getSuggestionGroupsForConversation($other);
        $flat = json_encode($groups, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('whatsapp', strtolower($flat));
        $this->assertStringNotContainsString('video call', strtolower($flat));
        $this->assertStringNotContainsString('send pic', strtolower($flat));
        $this->assertStringNotContainsString('नंबर', $flat);
    }
}
