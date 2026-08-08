<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The web now verifies and changes email through the same engine the apps use.
 *
 * What these lock down is not "the page works" but the two ways this feature
 * decays: a second place that can write users.email, and a code that verifies
 * an address other than the one it was sent to.
 */
class WebEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: string} challenge email and its OTP */
    private function requestCode(User $user, string $email): array
    {
        $this->actingAs($user)
            ->post(route('user.settings.email.otp.send'), ['email' => $email])
            ->assertRedirect(route('user.settings.email'));

        $challenge = session('settings_email_otp_challenge');
        $this->assertIsArray($challenge);

        return [(string) $challenge['email'], (string) $challenge['debug_otp']];
    }

    public function test_unverified_current_email_can_be_verified_with_an_otp(): void
    {
        $user = User::factory()->create([
            'email' => 'member@example.com',
            'email_verified_at' => null,
        ]);

        [, $otp] = $this->requestCode($user, 'member@example.com');

        $this->actingAs($user)
            ->post(route('user.settings.email.otp.verify'), ['otp' => $otp])
            ->assertRedirect(route('user.settings.email'))
            ->assertSessionHas('status', 'email-verified');

        $user->refresh();
        $this->assertSame('member@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_requesting_a_code_does_not_save_the_new_email(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'email_verified_at' => now(),
        ]);

        $this->requestCode($user, 'new@example.com');

        $user->refresh();
        $this->assertSame('old@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_the_new_email_is_saved_only_after_the_code_checks_out(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'email_verified_at' => now(),
        ]);

        [, $otp] = $this->requestCode($user, 'new@example.com');
        $this->assertSame('old@example.com', $user->fresh()->email);

        $this->actingAs($user)
            ->post(route('user.settings.email.otp.verify'), ['otp' => $otp])
            ->assertSessionHas('status', 'email-verified');

        $user->refresh();
        $this->assertSame('new@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_a_wrong_code_leaves_the_email_alone(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'email_verified_at' => now(),
        ]);

        $this->requestCode($user, 'new@example.com');

        $this->actingAs($user)
            ->post(route('user.settings.email.otp.verify'), ['otp' => '000000'])
            ->assertSessionHasErrors();

        $this->assertSame('old@example.com', $user->fresh()->email);
    }

    public function test_an_expired_challenge_leaves_the_email_alone(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'email_verified_at' => now(),
        ]);

        [, $otp] = $this->requestCode($user, 'new@example.com');

        // Ten minutes and one second later the challenge the server stored is gone.
        $this->travel(601)->seconds();

        $this->actingAs($user)
            ->post(route('user.settings.email.otp.verify'), ['otp' => $otp])
            ->assertSessionHasErrors();

        $this->assertSame('old@example.com', $user->fresh()->email);
        $this->travelBack();
    }

    public function test_an_email_owned_by_another_account_is_refused(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create(['email' => 'mine@example.com']);

        $this->actingAs($user)
            ->post(route('user.settings.email.otp.send'), ['email' => 'taken@example.com'])
            ->assertRedirect(route('user.settings.email'))
            ->assertSessionHasErrors('email');

        $this->assertNull(session('settings_email_otp_challenge'));
        $this->assertSame('mine@example.com', $user->fresh()->email);
    }

    public function test_a_verified_email_is_never_replaced_without_proving_the_new_one(): void
    {
        $user = User::factory()->create([
            'email' => 'verified@example.com',
            'email_verified_at' => now(),
        ]);

        // Ask for a code on a new address, then abandon it.
        $this->requestCode($user, 'attacker@example.com');
        $this->actingAs($user)->post(route('user.settings.email.otp.cancel'));

        $user->refresh();
        $this->assertSame('verified@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_the_code_verifies_the_address_it_was_sent_to_and_no_other(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'email_verified_at' => null,
        ]);

        [, $firstOtp] = $this->requestCode($user, 'first@example.com');

        // A second request replaces the challenge; the first code now belongs to
        // nothing, and the address on the account is still untouched.
        [$challengedEmail, $secondOtp] = $this->requestCode($user, 'second@example.com');
        $this->assertSame('second@example.com', $challengedEmail);

        if ($firstOtp !== $secondOtp) {
            $this->actingAs($user)
                ->post(route('user.settings.email.otp.verify'), ['otp' => $firstOtp])
                ->assertSessionHasErrors();
            $this->assertSame('old@example.com', $user->fresh()->email);
        }

        // The address the surviving code was sent to is the one that gets saved —
        // the form carries no email field, so nothing else can be substituted.
        $this->actingAs($user)
            ->post(route('user.settings.email.otp.verify'), [
                'otp' => $secondOtp,
                'email' => 'first@example.com',
            ])
            ->assertSessionHas('status', 'email-verified');

        $this->assertSame('second@example.com', $user->fresh()->email);
    }

    public function test_no_web_settings_route_can_write_an_email(): void
    {
        $user = User::factory()->create([
            'email' => 'mine@example.com',
            'email_verified_at' => now(),
        ]);

        // The generic settings writes the web still has. None of them takes an
        // email; posting one must be ignored, not honoured.
        $posts = [
            'user.settings.notifications.update',
            'user.settings.communication.update',
            'user.settings.privacy.update',
        ];

        foreach ($posts as $name) {
            $this->actingAs($user)->post(route($name), [
                'email' => 'hijack@example.com',
                'email_verified_at' => null,
            ]);
        }

        $user->refresh();
        $this->assertSame('mine@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_the_legacy_link_flow_is_no_longer_a_second_email_authority(): void
    {
        // The page that minted verification links on demand, and the page that
        // set an address before sending one, are both gone.
        $this->assertFalse(Route::has('verification.send'));
        $this->assertFalse(Route::has('matrimony.verification.email'));
        $this->assertFalse(Route::has('matrimony.verification.email.send'));

        // What remains — the signed link registration still mails — can only
        // confirm the address already on the account. It cannot set one.
        $user = User::factory()->create([
            'email' => 'member@example.com',
            'email_verified_at' => null,
        ]);

        $link = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1((string) $user->email)]
        );

        $this->actingAs($user)->get($link);

        $user->refresh();
        $this->assertSame('member@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_the_email_settings_page_shows_the_current_address_and_its_state(): void
    {
        $user = User::factory()->create([
            'email' => 'member@example.com',
            'email_verified_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('user.settings.email'))
            ->assertOk()
            ->assertSee('member@example.com')
            ->assertSee(__('settings_email.unverified'))
            ->assertSee(__('settings_email.verify_current_heading'))
            ->assertSee(__('settings_email.change_heading'));
    }
}
