<?php

use App\Models\MatrimonyProfile;
use App\Models\HelpCentreTicket;
use App\Models\User;

test('help centre page is accessible for authenticated member', function () {
    $user = User::factory()->create();
    MatrimonyProfile::factory()->for($user)->create();

    $response = $this->actingAs($user)->get(route('help-centre.index'));

    $response->assertOk();
    $response->assertSee(__('help_centre.title'), false);
});

test('help centre blocks sensitive contact queries', function () {
    $user = User::factory()->create();
    MatrimonyProfile::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson(route('help-centre.ask'), [
        'message' => 'Please share her phone number and email',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'intent' => 'policy_sensitive',
            'escalated' => false,
        ]);
    expect((string) $response->json('reply'))->toContain('privacy');

    $this->assertDatabaseHas('help_centre_tickets', [
        'user_id' => $user->id,
        'intent' => 'policy_sensitive',
        'escalated' => 0,
        'status' => 'auto_resolved',
    ]);
});

test('help centre keeps payment issues open for support review', function () {
    $user = User::factory()->create();
    MatrimonyProfile::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson(route('help-centre.ask'), [
        'message' => 'Payment done but plan not active',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'intent' => 'payment_help',
            'escalated' => true,
        ]);
    expect((string) $response->json('ticket_id'))->toStartWith('HC-');

    $this->assertDatabaseHas('help_centre_tickets', [
        'user_id' => $user->id,
        'intent' => 'payment_help',
        'escalated' => 1,
        'status' => 'open',
    ]);
    $this->assertDatabaseCount('help_centre_ticket_workflows', 1);
});

test('member can view own help request details only', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    MatrimonyProfile::factory()->for($user)->create();
    MatrimonyProfile::factory()->for($other)->create();

    $ticket = HelpCentreTicket::query()->create([
        'user_id' => $user->id,
        'ticket_code' => 'HC-OWN123',
        'query_text' => 'Payment done but plan not active',
        'normalized_query' => 'payment done but plan not active',
        'intent' => 'payment_help',
        'escalated' => true,
        'status' => 'open',
        'bot_reply' => 'Support will review this.',
    ]);

    $this->actingAs($user)
        ->get(route('help-centre.requests.show', $ticket))
        ->assertOk()
        ->assertSee('HC-OWN123')
        ->assertSee('Payment done but plan not active');

    $this->actingAs($other)
        ->get(route('help-centre.requests.show', $ticket))
        ->assertNotFound();
});

test('help centre escalates unknown issues with ticket id', function () {
    config(['help_centre.ai.enabled' => false]);

    $user = User::factory()->create();
    MatrimonyProfile::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson(route('help-centre.ask'), [
        'message' => 'I have a very specific issue not covered by menu options',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'intent' => 'escalation',
            'escalated' => true,
        ]);
    expect((string) $response->json('ticket_id'))->toStartWith('HC-');

    $this->assertDatabaseHas('help_centre_tickets', [
        'user_id' => $user->id,
        'intent' => 'escalation',
        'escalated' => 1,
        'status' => 'open',
    ]);
    $this->assertDatabaseCount('help_centre_ticket_workflows', 1);
});
