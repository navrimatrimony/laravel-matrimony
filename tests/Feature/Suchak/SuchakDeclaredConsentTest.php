<?php

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function makeSuchak(string $name): SuchakAccount
{
    $user = User::factory()->create();

    return SuchakAccount::query()->create([
        'user_id' => $user->id,
        'suchak_name' => $name,
        'business_type' => 'individual',
        'verification_status' => 'verified',
        'public_status' => 'published',
        // canPrepareCustomers() gates on this; without it every consent action
        // is refused before it starts.
        'registration_completed_at' => now(),
    ]);
}

function representationFor(SuchakAccount $account, MatrimonyProfile $profile): SuchakProfileRepresentation
{
    return SuchakProfileRepresentation::query()->create([
        'suchak_account_id' => $account->id,
        'matrimony_profile_id' => $profile->id,
        'representation_mode' => SuchakProfileRepresentation::MODE_MANUAL_FORM_BY_SUCHAK,
        'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
        'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
    ]);
}

function declareConsent(SuchakProfileRepresentation $representation, array $payload = [])
{
    return test()->postJson(
        '/api/v1/suchak/customers/'.$representation->id.'/consents/declare',
        array_merge(['candidate_name_affirmed' => 'Pooja Shinde'], $payload),
    );
}

test('a declaration is recorded as its own channel, never as candidate consent', function () {
    $account = makeSuchak('First Suchak');
    $profile = MatrimonyProfile::query()->create([
        'user_id' => User::factory()->create()->id,
        'full_name' => 'Pooja Shinde',
    ]);
    $representation = representationFor($account, $profile);

    Sanctum::actingAs($account->user);
    declareConsent($representation)->assertCreated();

    $consent = SuchakConsent::query()->firstOrFail();
    expect($consent->consent_channel)->toBe(SuchakConsent::CHANNEL_SUCHAK_DECLARED)
        ->and($consent->consent_status)->toBe(SuchakConsent::STATUS_ACCEPTED);

    $representation->refresh();
    expect($representation->consent_is_suchak_declared)->toBeTrue()
        // The candidate never answered, so nothing may claim they were verified.
        ->and($representation->consent_verified_at)->toBeNull();
});

test('a declaration does not stand in another Suchak way', function () {
    $mine = makeSuchak('First Suchak');
    $profile = MatrimonyProfile::query()->create([
        'user_id' => User::factory()->create()->id,
        'full_name' => 'Pooja Shinde',
    ]);
    $representation = representationFor($mine, $profile);

    Sanctum::actingAs($mine->user);
    declareConsent($representation)->assertCreated();

    // This is the rule that matters. One tick must not let a Suchak lock a
    // candidate away from everyone else without that candidate ever being asked.
    $blocking = SuchakProfileRepresentation::query()
        ->withCandidateGivenConsent()
        ->where('matrimony_profile_id', $profile->id)
        ->exists();

    expect($blocking)->toBeFalse();
});

test('consent the candidate actually gave does stand in the way', function () {
    $mine = makeSuchak('First Suchak');
    $profile = MatrimonyProfile::query()->create([
        'user_id' => User::factory()->create()->id,
        'full_name' => 'Pooja Shinde',
    ]);

    SuchakProfileRepresentation::query()->create([
        'suchak_account_id' => $mine->id,
        'matrimony_profile_id' => $profile->id,
        'representation_mode' => SuchakProfileRepresentation::MODE_MANUAL_FORM_BY_SUCHAK,
        'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
        'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
        'consent_is_suchak_declared' => false,
        'consent_valid_until' => now()->addYear(),
    ]);

    $blocking = SuchakProfileRepresentation::query()
        ->withCandidateGivenConsent()
        ->where('matrimony_profile_id', $profile->id)
        ->exists();

    expect($blocking)->toBeTrue();
});

test('a declared customer is still the declaring Suchak own customer', function () {
    $account = makeSuchak('First Suchak');
    $profile = MatrimonyProfile::query()->create([
        'user_id' => User::factory()->create()->id,
        'full_name' => 'Pooja Shinde',
    ]);
    $representation = representationFor($account, $profile);

    Sanctum::actingAs($account->user);
    declareConsent($representation)->assertCreated();

    // Narrowing the rival rule must not have cost the Suchak the customer they
    // declared for — that would defeat the whole point of offering it.
    $visible = SuchakProfileRepresentation::query()
        ->withValidConsent()
        ->whereKey($representation->id)
        ->exists();

    expect($visible)->toBeTrue();
});

test('the declaration is refused without the candidate name typed back', function () {
    $account = makeSuchak('First Suchak');
    $profile = MatrimonyProfile::query()->create([
        'user_id' => User::factory()->create()->id,
        'full_name' => 'Pooja Shinde',
    ]);
    $representation = representationFor($account, $profile);

    Sanctum::actingAs($account->user);

    // A bare tick is exactly what this must not be.
    test()->postJson('/api/v1/suchak/customers/'.$representation->id.'/consents/declare', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['candidate_name_affirmed']);

    expect(SuchakConsent::query()->count())->toBe(0);
});

test('one Suchak cannot declare consent on another Suchak customer', function () {
    $owner = makeSuchak('Owner Suchak');
    $stranger = makeSuchak('Stranger Suchak');
    $profile = MatrimonyProfile::query()->create([
        'user_id' => User::factory()->create()->id,
        'full_name' => 'Pooja Shinde',
    ]);
    $representation = representationFor($owner, $profile);

    Sanctum::actingAs($stranger->user);
    declareConsent($representation)->assertStatus(404);

    expect(SuchakConsent::query()->count())->toBe(0);
});
