<?php

use App\Models\Caste;
use App\Models\Religion;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('POST api v1 sub-castes duplicate detection stays scoped to same caste', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $rel = Religion::create(['key' => 'api-rel', 'label' => 'Api Rel', 'is_active' => true]);
    $caste = Caste::create([
        'religion_id' => $rel->id,
        'key' => 'api-caste',
        'label' => 'Api Caste',
        'is_active' => true,
    ]);

    $payload = [
        'caste_id' => $caste->id,
        'label' => 'My Sub Line',
    ];

    $first = $this->postJson('/api/v1/sub-castes', $payload);
    $first->assertCreated();

    $second = $this->postJson('/api/v1/sub-castes', $payload);
    $second->assertOk();
    expect($second->json('id'))->toBe($first->json('id'));

    $otherCaste = Caste::create([
        'religion_id' => $rel->id,
        'key' => 'api-caste-2',
        'label' => 'Api Caste Two',
        'is_active' => true,
    ]);

    $third = $this->postJson('/api/v1/sub-castes', [
        'caste_id' => $otherCaste->id,
        'label' => 'My Sub Line',
    ]);
    $third->assertCreated();
    expect($third->json('id'))->not->toBe($first->json('id'));
});
