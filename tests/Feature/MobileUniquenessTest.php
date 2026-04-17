<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobileUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_rejects_duplicate_normalized_mobile(): void
    {
        User::factory()->create([
            'mobile' => '9123456789',
            'email' => 'first@example.com',
        ]);

        $response = $this->post('/register', [
            'name' => 'Second User',
            'mobile' => '+91 9123456789',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'registering_for' => 'self',
        ]);

        $response->assertSessionHasErrors('mobile');
    }

    public function test_login_with_mobile_succeeds_for_single_match(): void
    {
        User::factory()->create([
            'mobile' => '9988776655',
            'email' => 'login@example.com',
            'password' => Hash::make('Secret123!'),
        ]);

        $response = $this->post('/login', [
            'login' => '+91 9988776655',
            'password' => 'Secret123!',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_duplicate_suffix_user_can_still_login_via_email_or_name(): void
    {
        $primary = User::factory()->create([
            'mobile' => '9112233445',
            'email' => 'primary_dup@example.com',
            'password' => Hash::make('Password1!'),
            'name' => 'Primary Holder',
        ]);

        $secondary = User::factory()->create([
            'mobile' => '9112233445_dup_2',
            'email' => 'secondary_dup@example.com',
            'password' => Hash::make('Password1!'),
            'name' => 'Secondary Holder',
            'mobile_duplicate_of_user_id' => $primary->id,
        ]);

        $this->post('/login', [
            'login' => 'secondary_dup@example.com',
            'password' => 'Password1!',
        ])->assertRedirect();
        $this->assertAuthenticated();
        $this->assertSame($secondary->id, auth()->id());
    }
}
