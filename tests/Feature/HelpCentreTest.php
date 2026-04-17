<?php

use App\Models\MatrimonyProfile;
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
