<?php

use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\ContactRequestService;
use App\Services\SubscriptionService;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('contact request is blocked until receiver accepts interest (no mutual required)', function () {
    $senderProfile = MatrimonyProfile::factory()->create([
        'lifecycle_state' => 'active',
    ]);
    $receiverProfile = MatrimonyProfile::factory()->create([
        'lifecycle_state' => 'active',
    ]);

    DB::table('profile_contacts')->insert([
        'profile_id' => $receiverProfile->id,
        'contact_name' => 'Primary',
        'phone_number' => '9876543210',
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Sender sends interest but receiver has not accepted yet.
    Interest::create([
        'sender_profile_id' => $senderProfile->id,
        'receiver_profile_id' => $receiverProfile->id,
        'status' => 'pending',
    ]);

    $senderUser = $senderProfile->user ?: User::factory()->create(['gender' => 'Male']);
    $receiverUser = $receiverProfile->user ?: User::factory()->create(['gender' => 'Male']);
    $senderProfile->update(['user_id' => $senderUser->id]);
    $receiverProfile->update(['user_id' => $receiverUser->id]);

    // UI: Request Contact CTA must not be clickable.
    $response = $this->actingAs($senderUser)->get(route('matrimony.profile.show', $receiverProfile->id));
    $response->assertDontSee('$root.openRequestModal = true', false);

    // Backend: contact request must fail without accepted interest.
    $service = app(ContactRequestService::class);
    try {
        $service->createRequest($senderUser, $receiverUser, 'meet', ['phone']);
        $this->fail('Expected ValidationException was not thrown.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('contact_request');
    }
});

test('contact request works after accepted interest only + reveals only primary phone', function () {
    $this->seed(SubscriptionPlansSeeder::class);
    $this->seed(PlanStandardFeatureKeysSeeder::class);

    $senderProfile = MatrimonyProfile::factory()->create([
        'lifecycle_state' => 'active',
    ]);
    $receiverProfile = MatrimonyProfile::factory()->create([
        'lifecycle_state' => 'active',
    ]);

    DB::table('profile_contacts')->insert([
        'profile_id' => $receiverProfile->id,
        'contact_name' => 'Primary',
        'phone_number' => '9876543210',
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $senderUser = $senderProfile->user ?: User::factory()->create(['gender' => 'Male']);
    $receiverUser = $receiverProfile->user ?: User::factory()->create(['gender' => 'Male']);
    $senderProfile->update(['user_id' => $senderUser->id]);
    $receiverProfile->update(['user_id' => $receiverUser->id]);

    DB::table('profile_visibility_settings')->insert([
        'profile_id' => $receiverProfile->id,
        'visibility_scope' => 'public',
        'show_photo_to' => 'all',
        'show_contact_to' => 'unlock_only',
        'hide_from_blocked_users' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->where('slug', 'silver')->firstOrFail();
    $price = PlanPrice::query()
        ->where('plan_id', $plan->id)
        ->where('is_visible', true)
        ->orderBy('sort_order')
        ->firstOrFail();
    app(SubscriptionService::class)->subscribe($senderUser, $plan, null, $price->id);

    // Sender interest accepted by receiver. No reverse interest created.
    Interest::create([
        'sender_profile_id' => $senderProfile->id,
        'receiver_profile_id' => $receiverProfile->id,
        'status' => 'accepted',
    ]);

    // UI: Request Contact CTA must become clickable as soon as interest is accepted.
    $response = $this->actingAs($senderUser)->get(route('matrimony.profile.show', $receiverProfile->id));
    $response->assertSee('$root.openRequestModal = true', false);

    // Backend: contact request should be allowed without reverse/counter-interest.
    $service = app(ContactRequestService::class);
    $contactRequest = $service->createRequest(
        $senderUser,
        $receiverUser,
        'meet',
        ['email', 'phone', 'whatsapp']
    );

    // Receiver approves (grant may include email/whatsapp, but UI reveal must still show only primary phone).
    $service->approve(
        $contactRequest,
        $receiverUser,
        ['email', 'phone', 'whatsapp'],
        'approve_once'
    );

    $response = $this->actingAs($senderUser)->get(route('matrimony.profile.show', $receiverProfile->id));

    // Phone should be revealed.
    $response->assertSee('Phone:', false);
    $response->assertSee('9876543210');

    // Email/WhatsApp must not be shown after acceptance.
    $response->assertDontSee('Email:');
    $response->assertDontSee('WhatsApp:');
});
