<?php

use App\Models\MatrimonyProfile;
use App\Models\MasterAssetType;
use App\Models\MasterOwnershipType;
use App\Models\User;
use App\Services\MutationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('manual snapshot collapses property summary notes into first property asset row', function () {
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
        ]],
    ], (int) $user->id, 'manual');

    $rows = DB::table('profile_property_assets')->where('profile_id', $profile->id)->orderBy('id')->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->asset_type_id)->toBeGreaterThan(0)
        ->and($rows[0]->location)->toBe('Pune')
        ->and((string) $rows[0]->notes)->toContain('Owns agriculture: Yes')
        ->and((string) $rows[0]->notes)->toContain('Total land (acres): 2')
        ->and((string) $rows[0]->notes)->toContain('Legacy property note');
});

test('manual snapshot keeps notes only property in property assets table', function () {
    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->for($user)->create();

    app(MutationService::class)->applyManualSnapshot($profile, [
        'property_summary' => [[
            'summary_notes' => 'Only notes remain here',
        ]],
        'property_assets' => [[
            'asset_type_id' => null,
            'location' => '',
            'ownership_type_id' => null,
        ]],
    ], (int) $user->id, 'manual');

    $rows = DB::table('profile_property_assets')->where('profile_id', $profile->id)->orderBy('id')->get();

    expect(Schema::hasTable('profile_property_summary'))->toBeFalse()
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]->asset_type_id)->toBeNull()
        ->and($rows[0]->ownership_type_id)->toBeNull()
        ->and((string) $rows[0]->notes)->toBe('Only notes remain here');
});

test('property wizard saves additional information per asset row', function () {
    $this->seed(\Database\Seeders\MasterLookupSeeder::class);

    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->for($user)->create();
    $assetTypeId = MasterAssetType::query()->where('key', 'house')->value('id');
    $ownershipTypeId = MasterOwnershipType::query()->where('key', 'sole')->value('id');

    expect($assetTypeId)->not->toBeNull()
        ->and($ownershipTypeId)->not->toBeNull();

    $response = $this->actingAs($user)->post(route('matrimony.profile.wizard.store', ['section' => 'property']), [
        'property_assets' => [[
            'asset_type_id' => (string) $assetTypeId,
            'location' => 'Pune',
            'ownership_type_id' => (string) $ownershipTypeId,
            'additional_information' => 'Near market, 2nd floor',
        ]],
    ]);

    $response->assertSessionHasNoErrors();

    $row = DB::table('profile_property_assets')
        ->where('profile_id', $profile->id)
        ->orderByDesc('id')
        ->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->asset_type_id)->toBe((int) $assetTypeId)
        ->and((int) $row->ownership_type_id)->toBe((int) $ownershipTypeId)
        ->and((string) $row->location)->toBe('Pune')
        ->and((string) $row->additional_information)->toBe('Near market, 2nd floor');
});

test('property wizard GET renders additional information field for assets', function () {
    $this->seed(\Database\Seeders\MasterLookupSeeder::class);

    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->for($user)->create();
    $assetTypeId = MasterAssetType::query()->where('key', 'flat')->value('id');
    $ownershipTypeId = MasterOwnershipType::query()->where('key', 'joint')->value('id');

    DB::table('profile_property_assets')->insert([
        'profile_id' => $profile->id,
        'asset_type_id' => $assetTypeId,
        'location' => 'Mumbai',
        'ownership_type_id' => $ownershipTypeId,
        'notes' => null,
        'additional_information' => 'Sea facing unit',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('matrimony.profile.wizard.section', ['section' => 'property']))
        ->assertOk()
        ->assertSee('Additional Information', false)
        ->assertSee('Sea facing unit', false);
});
