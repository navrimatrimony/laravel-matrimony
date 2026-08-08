<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\Api\MobileEmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The contract this locks: an address reaches users.email only after its
 * holder proved possession. PATCH /account/details is not a way in, and the
 * OTP flow stays the only one — no second engine, no back door.
 */
class AccountEmailChangeContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_details_cannot_add_an_email(): void
    {
        $user = User::factory()->create(['email' => null, 'email_verified_at' => null]);
        Sanctum::actingAs($user);

        $this
            ->patchJson('/api/v1/account/details', [
                'creator_name' => 'Shankar Patil',
                'email' => 'new@example.com',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertNull($user->fresh()->email);
    }

    public function test_account_details_cannot_replace_a_verified_email(): void
    {
        $user = User::factory()->create([
            'email' => 'verified@example.com',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $this
            ->patchJson('/api/v1/account/details', [
                'creator_name' => 'Shankar Patil',
                'email' => 'attacker@example.com',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $user->refresh();
        $this->assertSame('verified@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_account_details_cannot_clear_an_email(): void
    {
        $user = User::factory()->create([
            'email' => 'verified@example.com',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($user);

        // An explicit null is allowed through validation (old clients send the
        // key unconditionally) but must not touch the stored address.
        $this
            ->patchJson('/api/v1/account/details', [
                'creator_name' => 'Shankar Patil',
                'email' => null,
            ])
            ->assertOk();

        $user->refresh();
        $this->assertSame('verified@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_account_details_still_updates_the_fields_it_owns(): void
    {
        $user = User::factory()->create(['email' => null, 'name' => 'Old Name']);
        Sanctum::actingAs($user);

        $this
            ->patchJson('/api/v1/account/details', [
                'creator_name' => 'New Name',
                'locale' => 'en',
            ])
            ->assertOk();

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('en', $user->preferred_locale);
    }

    public function test_email_still_changes_through_the_otp_flow(): void
    {
        $user = User::factory()->create(['email' => null, 'email_verified_at' => null]);
        Sanctum::actingAs($user);

        $sent = $this
            ->postJson('/api/v1/account/email-otp/send', ['email' => 'member@example.com'])
            ->assertOk()
            ->json();

        $this->assertNotNull($sent['debug_otp'] ?? null);

        $this
            ->postJson('/api/v1/account/email-otp/verify', [
                'challenge_id' => $sent['challenge_id'],
                'email' => 'member@example.com',
                'otp' => $sent['debug_otp'],
            ])
            ->assertOk();

        $user->refresh();
        $this->assertSame('member@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_otp_flow_still_rejects_an_email_owned_by_another_account(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create(['email' => null]);
        Sanctum::actingAs($user);

        $this
            ->postJson('/api/v1/account/email-otp/send', ['email' => 'taken@example.com'])
            ->assertStatus(409);

        $this->assertNull($user->fresh()->email);
    }

    public function test_a_verified_email_is_replaced_only_by_proving_the_new_one(): void
    {
        $user = User::factory()->create([
            'email' => 'first@example.com',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $sent = $this
            ->postJson('/api/v1/account/email-otp/send', ['email' => 'second@example.com'])
            ->assertOk()
            ->json();

        // Until the code is proved, the old address is still the account's.
        $this->assertSame('first@example.com', $user->fresh()->email);

        $this
            ->postJson('/api/v1/account/email-otp/verify', [
                'challenge_id' => $sent['challenge_id'],
                'email' => 'second@example.com',
                'otp' => '000000',
            ])
            ->assertStatus(422);

        $this->assertSame('first@example.com', $user->fresh()->email);

        $this
            ->postJson('/api/v1/account/email-otp/verify', [
                'challenge_id' => $sent['challenge_id'],
                'email' => 'second@example.com',
                'otp' => $sent['debug_otp'],
            ])
            ->assertOk();

        $this->assertSame('second@example.com', $user->fresh()->email);
    }

    public function test_the_otp_service_remains_the_only_writer_of_a_verified_email(): void
    {
        // Guards against a second engine appearing beside the one that exists.
        $this->assertTrue(class_exists(MobileEmailVerificationService::class));

        $controller = file_get_contents(
            app_path('Http/Controllers/Api/MobileAccountController.php')
        );

        $this->assertStringNotContainsString("'email' => \$email", $controller);
        $this->assertStringNotContainsString('email_verified_at', $controller);
    }
}
