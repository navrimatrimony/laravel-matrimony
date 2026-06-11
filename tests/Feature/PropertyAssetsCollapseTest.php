<?php

use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\MutationService;
use Illuminate\Support\Facades\Schema;

test('manual snapshot converts legacy property summary and assets to profile text', function () {
    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->for($user)->create();

    app(MutationService::class)->applyManualSnapshot($profile, [
        'property_summary' => [[
            'summary_notes' => 'Legacy property note',
            'owns_agriculture' => true,
            'total_land_acres' => 2,
        ]],
        'property_assets' => [[
            'asset_type' => 'house',
            'location' => 'Pune',
            'ownership_type' => 'sole',
            'additional_information' => 'Near market',
        ]],
    ], (int) $user->id, 'manual');

    $profile->refresh();

    expect(Schema::hasTable('profile_property_assets'))->toBeFalse()
        ->and((string) $profile->property_details)->toContain('Owns agriculture: Yes')
        ->and((string) $profile->property_details)->toContain('Total land (acres): 2')
        ->and((string) $profile->property_details)->toContain('Legacy property note')
        ->and((string) $profile->property_details)->toContain('house - Pune - sole - Near market');
});

test('property wizard saves one multiline property text field', function () {
    $user = User::factory()->create();
    MatrimonyProfile::factory()->for($user)->create();

    $response = $this->actingAs($user)->post(route('matrimony.profile.wizard.store', ['section' => 'property']), [
        'property_details' => "Farm land 3 acres\nOwn house in Sangli\nTractor and private vehicle",
    ]);

    $response->assertSessionHasNoErrors();

    $profile = MatrimonyProfile::query()->where('user_id', $user->id)->firstOrFail();

    expect((string) $profile->property_details)
        ->toBe("Farm land 3 acres\nOwn house in Sangli\nTractor and private vehicle");
});

test('property wizard GET renders the property details textarea', function () {
    $user = User::factory()->create();
    MatrimonyProfile::factory()->for($user)->create([
        'property_details' => "Sea facing unit\nShop near market",
    ]);

    $this->actingAs($user)
        ->get(route('matrimony.profile.wizard.section', ['section' => 'property']))
        ->assertOk()
        ->assertSee('Property details', false)
        ->assertSee('Sea facing unit', false)
        ->assertSee('Shop near market', false);
});
