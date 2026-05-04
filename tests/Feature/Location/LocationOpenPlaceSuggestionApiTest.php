<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('guest cannot submit open place suggestion via api', function () {
    if (! Schema::hasTable('location_open_place_suggestions')) {
        $this->markTestSkipped('location_open_place_suggestions table not migrated');
    }

    $response = $this->postJson('/api/location/suggestions', [
        'input_text' => 'Some Village Name',
    ]);

    $response->assertUnauthorized();
});

test('authenticated user can submit open place suggestion via api', function () {
    if (! Schema::hasTable('location_open_place_suggestions')) {
        $this->markTestSkipped('location_open_place_suggestions table not migrated');
    }

    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/location/suggestions', [
        'input_text' => 'Unique Test Village '.uniqid('', true),
    ]);

    $response->assertOk();
    $response->assertJson(['success' => true]);
});
