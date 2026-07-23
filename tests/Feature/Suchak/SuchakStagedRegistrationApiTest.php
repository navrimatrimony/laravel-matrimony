<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\SuchakVerificationRecord;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuchakStagedRegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_config_is_public(): void
    {
        $this->getJson('/api/v1/suchak/app-config')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'theme_color',
                    'tagline',
                    'asset_specs',
                ],
            ]);
    }

    public function test_register_rejects_bureau_business_type(): void
    {
        $this->postJson('/api/v1/suchak/register', [
            'suchak_name' => 'Bureau Test',
            'business_type' => SuchakAccount::BUSINESS_TYPE_BUREAU,
            'office_name' => 'Office',
            'whatsapp_number' => '9876501234',
            'address_line' => 'Pune',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertStatus(422);
    }

    public function test_start_mobile_issues_token_and_incomplete_account(): void
    {
        $response = $this->postJson('/api/v1/suchak/register/start', [
            'whatsapp_number' => '9876501235',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['token', 'otp']);

        $this->assertDatabaseHas('suchak_accounts', [
            'mobile_number' => '9876501235',
            'registration_completed_at' => null,
        ]);

        $token = $response->json('token');
        $this->assertNotEmpty($token);
    }

    public function test_staged_identity_rejects_incomplete_password_complete_without_photo(): void
    {
        Storage::fake('local');

        $start = $this->postJson('/api/v1/suchak/register/start', [
            'whatsapp_number' => '9876501236',
        ])->assertCreated();

        $user = User::query()->where('mobile', '9876501236')->firstOrFail();
        $debugOtp = $start->json('otp.debug_otp');
        $this->assertNotEmpty($debugOtp);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/suchak/register/otp/verify', [
            'otp' => $debugOtp,
        ])->assertOk();

        $this->postJson('/api/v1/suchak/register/identity', [
            'suchak_name' => 'Ramesh Suchak',
            'business_type' => SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
        ])->assertOk();

        $this->postJson('/api/v1/suchak/register/password', [
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertStatus(422);

        $this->mock(\App\Services\Image\ImageModerationService::class, function ($mock): void {
            $mock->shouldReceive('moderateProfilePhoto')
                ->once()
                ->andReturn([
                    'status' => 'approved',
                    'reason' => null,
                    'meta' => [],
                ]);
        });

        $file = UploadedFile::fake()->image('face.jpg', 600, 800);
        $this->post('/api/v1/suchak/register/photo', [
            'profile_photo' => $file,
        ], [
            'Accept' => 'application/json',
        ])->assertOk();

        $this->assertDatabaseHas('suchak_verification_records', [
            'verification_type' => SuchakVerificationRecord::TYPE_PROFILE_PHOTO,
            'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
        ]);

        // Location still required for complete — password complete still 422 without location.
        $this->postJson('/api/v1/suchak/register/password', [
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertStatus(422);
    }

    public function test_register_photo_rejects_unsafe_moderation(): void
    {
        Storage::fake('local');

        $start = $this->postJson('/api/v1/suchak/register/start', [
            'whatsapp_number' => '9876501299',
        ])->assertCreated();

        $user = User::query()->where('mobile', '9876501299')->firstOrFail();
        $debugOtp = $start->json('otp.debug_otp');
        $this->assertNotEmpty($debugOtp);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/suchak/register/otp/verify', [
            'otp' => $debugOtp,
        ])->assertOk();

        $this->mock(\App\Services\Image\ImageModerationService::class, function ($mock): void {
            $mock->shouldReceive('moderateProfilePhoto')
                ->once()
                ->andReturn([
                    'status' => 'rejected',
                    'reason' => 'Rejected by automated moderation (unsafe).',
                    'meta' => [],
                ]);
        });

        $file = UploadedFile::fake()->image('bad.jpg', 600, 800);
        $this->post('/api/v1/suchak/register/photo', [
            'profile_photo' => $file,
        ], [
            'Accept' => 'application/json',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['profile_photo']);

        $this->assertDatabaseMissing('suchak_verification_records', [
            'suchak_account_id' => $user->suchakAccount->id,
            'verification_type' => SuchakVerificationRecord::TYPE_PROFILE_PHOTO,
        ]);
    }

    public function test_existing_complete_accounts_still_can_operate_after_migration_backfill_semantics(): void
    {
        $user = User::factory()->create([
            'mobile' => '9876501237',
            'password' => Hash::make('Password1!'),
        ]);
        $account = SuchakAccount::query()->create([
            'user_id' => $user->id,
            'suchak_name' => 'Existing',
            'business_type' => SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
            'mobile_number' => '9876501237',
            'whatsapp_number' => '9876501237',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'registration_completed_at' => now(),
        ]);

        $access = app(\App\Modules\Suchak\Services\SuchakAccessService::class);
        $this->assertTrue($access->canOperate($account));
    }
}
