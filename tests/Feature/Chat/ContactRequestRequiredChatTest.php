<?php

namespace Tests\Feature\Chat;

use App\Models\AdminSetting;
use App\Models\Conversation;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\ContactRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactRequestRequiredChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_sender_cannot_start_chat_without_valid_contact_grant(): void
    {
        AdminSetting::setValue('communication_messaging_mode', 'contact_request_required');

        $aUser = User::factory()->create();
        $bUser = User::factory()->create();

        $a = MatrimonyProfile::factory()->create(['user_id' => $aUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $b = MatrimonyProfile::factory()->create(['user_id' => $bUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);

        $this->actingAs($aUser)
            ->post(route('chat.start', ['matrimony_profile' => $b->id]))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_valid_contact_grant_allows_chat_and_revoke_blocks_again(): void
    {
        AdminSetting::setValue('communication_messaging_mode', 'contact_request_required');

        $aUser = User::factory()->create();
        $bUser = User::factory()->create();

        $a = MatrimonyProfile::factory()->create(['user_id' => $aUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $b = MatrimonyProfile::factory()->create(['user_id' => $bUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);

        // Required prerequisite in contact request flow: accepted interest (A -> B)
        Interest::create([
            'sender_profile_id' => $a->id,
            'receiver_profile_id' => $b->id,
            'status' => 'accepted',
        ]);

        /** @var ContactRequestService $svc */
        $svc = app(ContactRequestService::class);

        $req = $svc->createRequest($aUser, $bUser, 'need_more_details', ['phone'], null);
        $grant = $svc->approve($req, $bUser, ['phone'], 'approve_once');

        $this->actingAs($aUser)
            ->post(route('chat.start', ['matrimony_profile' => $b->id]))
            ->assertRedirect();

        $this->assertDatabaseCount('conversations', 1);

        $conv = Conversation::firstOrFail();

        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), [
            'body_text' => 'Hello',
        ])->assertRedirect();

        // Revoke grant -> chat should be blocked again
        $svc->revokeGrant($grant, $bUser);

        $this->actingAs($aUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), [
            'body_text' => 'Blocked?',
        ])->assertRedirect()->assertSessionHasErrors('policy');
    }
}

