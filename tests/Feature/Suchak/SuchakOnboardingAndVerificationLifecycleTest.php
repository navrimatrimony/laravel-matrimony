<?php

namespace Tests\Feature\Suchak;

use App\Jobs\ProcessProfilePhoto;
use App\Models\AdminAuditLog;
use App\Models\City;
use App\Models\District;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\State;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakContactNumber;
use App\Models\SuchakConsent;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakPolicy;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakVerificationRecord;
use App\Models\Taluka;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Modules\Suchak\Services\SuchakWorkAreaService;
use App\Services\Location\CurrentLocationResolverService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SuchakOnboardingAndVerificationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_separate_suchak_registration_page_is_available_without_creating_account_on_get(): void
    {
        $this->get(route('suchak.register.info'))
            ->assertOk()
            ->assertSee('Suchak Registration', false)
            ->assertSee('1. Information and password', false)
            ->assertSee('location-typeahead-wrapper', false)
            ->assertSee('name="location_id"', false)
            ->assertSee('Office area / city', false)
            ->assertSee('location-gps-btn', false)
            ->assertSee(route('suchak.register.resolve-current-location'), false)
            ->assertSee('data-gps-auto-apply="1"', false)
            ->assertSee('aria-label="Use current location"', false)
            ->assertDontSee('Aadhaar card / Passport upload', false)
            ->assertSee('Register Free', false);

        $this->assertDatabaseCount('suchak_accounts', 0);
    }

    public function test_guest_suchak_registration_location_resolve_is_public_suggestion_only(): void
    {
        $this->mock(CurrentLocationResolverService::class, function ($mock): void {
            $mock->shouldReceive('resolve')
                ->once()
                ->with(0, 18.5204, 73.8567)
                ->andReturn([
                    'success' => true,
                    'status' => 'resolved',
                    'display_label' => 'Pune City, Haveli, Pune, Maharashtra',
                    'city_id' => 10,
                    'taluka_id' => 20,
                    'district_id' => 30,
                    'state_id' => 40,
                    'country_id' => 50,
                    'alternatives' => [],
                ]);
        });

        $this->postJson(route('suchak.register.resolve-current-location'), [
            'lat' => 18.5204,
            'lon' => 73.8567,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('display_label', 'Pune City, Haveli, Pune, Maharashtra')
            ->assertJsonPath('city_id', 10);

        $this->assertDatabaseCount('suchak_accounts', 0);
        $this->assertDatabaseCount('matrimony_profiles', 0);
    }

    public function test_suchak_home_hero_registration_form_can_be_disabled_by_admin_policy(): void
    {
        $this->get(route('suchak.home'))
            ->assertOk()
            ->assertSee('Suchak registration', false)
            ->assertSee('name="suchak_name"', false)
            ->assertSee('Register Free', false);

        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_HERO_REGISTRATION_FORM_ENABLED],
            [
                'policy_value' => 'false',
                'value_type' => SuchakPolicy::TYPE_BOOLEAN,
                'description' => 'Disable hero registration form for test.',
                'is_active' => true,
            ],
        );

        $this->get(route('suchak.home'))
            ->assertOk()
            ->assertDontSee('name="suchak_name"', false)
            ->assertSee('Register as Suchak', false);

        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_HERO_IMAGE_PATH],
            [
                'policy_value' => 'suchak/hero-images/custom-hero.webp',
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Custom hero image for test.',
                'is_active' => true,
            ],
        );

        $this->get(route('suchak.home'))
            ->assertOk()
            ->assertSee('/storage/suchak/hero-images/custom-hero.webp', false);
    }

    public function test_suchak_home_hero_has_inline_login_and_recovery_options_without_home_link(): void
    {
        $this->get(route('suchak.home'))
            ->assertOk()
            ->assertSee('data-suchak-auth-panel="register"', false)
            ->assertSee('data-suchak-auth-panel="login"', false)
            ->assertSee('data-suchak-auth-toggle="login"', false)
            ->assertSee('name="login"', false)
            ->assertSee('Forgot your password?', false)
            ->assertSee('New Suchak? Register here', false)
            ->assertDontSee('>Home</a>', false);
    }

    public function test_suchak_home_hero_inline_login_uses_selected_marathi_locale(): void
    {
        $this->get(route('suchak.home', ['locale' => 'mr']))
            ->assertOk()
            ->assertSee('सूचक login', false)
            ->assertSee('पासवर्ड विसरलात?', false)
            ->assertSee('नवीन सूचक? नोंदणी करा', false)
            ->assertDontSee('New Suchak? Register here', false);
    }

    public function test_guest_can_register_separate_suchak_account_and_reaches_otp_step(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        Storage::fake('local');

        $city = City::query()->where('name', 'Pune City')->firstOrFail();
        $taluka = Taluka::query()->where('name', 'Haveli')->firstOrFail();
        $district = District::query()->where('name', 'Pune')->firstOrFail();
        $state = State::query()->where('name', 'Maharashtra')->firstOrFail();

        $this->post(route('suchak.register.store'), [
            'suchak_name' => 'Ganesh Suchak',
            'office_name' => 'Ganesh Marriage Bureau',
            'business_type' => SuchakAccount::BUSINESS_TYPE_BUREAU,
            'whatsapp_number' => '9876543210',
            'email' => 'ganesh-suchak@example.test',
            'address_line' => 'Pune office',
            'location_id' => $city->id,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
            ->assertRedirect(route('suchak.register.verify'))
            ->assertSessionHas('suchak_registration_otp_display');

        $this->assertAuthenticated();

        $user = User::query()->where('mobile', '9876543210')->firstOrFail();
        $account = SuchakAccount::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertNull($user->mobile_verified_at);
        $this->assertNull($user->matrimonyProfile);
        $this->assertDatabaseMissing('matrimony_profiles', [
            'user_id' => $user->id,
        ]);
        $this->assertSame(SuchakAccount::VERIFICATION_PENDING, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->public_status);
        $this->assertSame('9876543210', $account->mobile_number);
        $this->assertSame('9876543210', $account->whatsapp_number);
        $this->assertSame((int) $city->id, (int) $account->city_id);
        $this->assertSame((int) $taluka->id, (int) $account->taluka_id);
        $this->assertSame((int) $district->id, (int) $account->district_id);
        $this->assertSame((int) $state->id, (int) $account->state_id);

        $this->assertDatabaseMissing('suchak_verification_records', [
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_IDENTITY,
        ]);

        $otpPayload = Cache::get('suchak_registration_otp:'.$user->id);
        $this->assertIsArray($otpPayload);
        $this->assertArrayHasKey('hash', $otpPayload);
        $this->assertNotSame(session('suchak_registration_otp_display'), $otpPayload['hash']);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $user->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_SUCHAK_ONBOARDING_REQUESTED,
        ]);

        $this->actingAs($user)
            ->get(route('suchak.register.verify'))
            ->assertOk()
            ->assertSee('data-suchak-progress-current="otp"', false)
            ->assertSee('WhatsApp OTP', false)
            ->assertSee('Suchak photo', false)
            ->assertSee('KYC documents', false)
            ->assertSee('Ready to work', false)
            ->assertDontSee('Suchak card', false)
            ->assertDontSee('Work area', false)
            ->assertDontSee('Start Suchak work', false);
    }

    public function test_bureau_suchak_registration_requires_office_name_before_otp(): void
    {
        Storage::fake('local');

        $this->post(route('suchak.register.store'), [
            'suchak_name' => 'Missing Office Proof',
            'business_type' => SuchakAccount::BUSINESS_TYPE_BUREAU,
            'whatsapp_number' => '9876543209',
            'address_line' => 'Pune office',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
            ->assertSessionHasErrors('office_name');

        $this->assertDatabaseCount('suchak_accounts', 0);
    }

    public function test_suchak_registration_rejects_unselected_location_text_without_profile_write(): void
    {
        Storage::fake('local');

        $this->post(route('suchak.register.store'), [
            'suchak_name' => 'Unselected Location Suchak',
            'business_type' => SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
            'whatsapp_number' => '9876543205',
            'address_line' => 'Office address line',
            'location_input' => 'Typed but not selected place',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
            ->assertSessionHasErrors('location_id');

        $this->assertDatabaseCount('suchak_accounts', 0);
        $this->assertDatabaseCount('matrimony_profiles', 0);
    }

    public function test_individual_suchak_registration_uses_whatsapp_as_primary_and_does_not_require_office_document(): void
    {
        Storage::fake('local');

        $this->post(route('suchak.register.store'), [
            'suchak_name' => 'Individual Suchak',
            'business_type' => SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
            'whatsapp_number' => '9876543208',
            'email' => 'individual-suchak@example.test',
            'address_line' => 'Sangli work area',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
            ->assertRedirect(route('suchak.register.verify'))
            ->assertSessionHasNoErrors();

        $user = User::query()->where('mobile', '9876543208')->firstOrFail();
        $account = SuchakAccount::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame('9876543208', $account->mobile_number);
        $this->assertSame('9876543208', $account->whatsapp_number);
        $this->assertNull($account->office_name);

        $this->assertDatabaseMissing('suchak_verification_records', [
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_IDENTITY,
        ]);
        $this->assertDatabaseMissing('suchak_verification_records', [
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_OFFICE,
        ]);
    }

    public function test_suchak_can_upload_kyc_documents_after_otp_registration(): void
    {
        Storage::fake('local');

        $user = User::factory()->create([
            'mobile' => '9876543215',
            'mobile_verified_at' => now(),
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'mobile_number' => '9876543215',
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
        ]);

        $this->actingAs($user)
            ->post(route('suchak.register.documents.store'), [
                'verification_type' => SuchakVerificationRecord::TYPE_IDENTITY,
                'document' => UploadedFile::fake()->create('identity-proof.pdf', 128, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $record = SuchakVerificationRecord::query()
            ->where('suchak_account_id', $account->id)
            ->where('verification_type', SuchakVerificationRecord::TYPE_IDENTITY)
            ->firstOrFail();

        $this->assertSame(SuchakVerificationRecord::STATUS_PENDING, $record->admin_status);
        $this->assertNotEmpty($record->document_path);
        Storage::disk('local')->assertExists($record->document_path);
    }

    public function test_suchak_work_area_is_earned_from_valid_consented_customers(): void
    {
        $this->seed(MinimalLocationSeeder::class);

        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_WORK_AREA_MIN_CONSENTED_CUSTOMERS],
            [
                'policy_value' => '2',
                'value_type' => SuchakPolicy::TYPE_INTEGER,
                'description' => 'Test work area threshold.',
                'is_active' => true,
            ],
        );

        $city = City::query()->where('name', 'Pune City')->firstOrFail();
        $account = SuchakAccount::factory()->create();

        for ($i = 0; $i < 2; $i++) {
            $profile = MatrimonyProfile::factory()->create([
                'location_id' => $city->id,
            ]);

            SuchakProfileRepresentation::factory()->create([
                'suchak_account_id' => $account->id,
                'matrimony_profile_id' => $profile->id,
                'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
                'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
                'consent_verified_at' => now(),
                'consent_valid_until' => now()->addYear(),
            ]);
        }

        $summary = app(SuchakWorkAreaService::class)->summary($account);

        $this->assertSame(2, $summary['minimum']);
        $this->assertSame(2, $summary['total_valid_consent_customers']);
        $this->assertCount(1, $summary['earned_areas']);
        $this->assertTrue($summary['earned_areas'][0]['eligible']);
        $this->assertSame(2, $summary['earned_areas'][0]['customer_count']);
    }

    public function test_suchak_profile_photo_ai_safe_auto_approves_and_publishes_card_photo(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->mock(\App\Services\Image\ImageModerationService::class, function ($mock): void {
            $mock->shouldReceive('moderateProfilePhoto')
                ->once()
                ->andReturn([
                    'status' => 'approved',
                    'reason' => null,
                    'meta' => [],
                ]);
        });

        $user = User::factory()->create([
            'mobile' => '9876543219',
            'mobile_verified_at' => now(),
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'mobile_number' => '9876543219',
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
        ]);

        $this->actingAs($user)
            ->get(route('suchak.register.photo'))
            ->assertOk()
            ->assertSee('cropper.min.js', false)
            ->assertSee(route('suchak.register.photo.store'), false)
            ->assertDontSee('<input type="hidden" name="profile_id"', false);

        $this->actingAs($user)
            ->post(route('suchak.register.photo.store'), [
                'profile_photo' => UploadedFile::fake()->image('suchak-card.jpg', 320, 320),
            ])
            ->assertRedirect(route('suchak.register.status'))
            ->assertSessionHas('success');

        $account->refresh();
        $record = SuchakVerificationRecord::query()
            ->where('suchak_account_id', $account->id)
            ->where('verification_type', SuchakVerificationRecord::TYPE_PROFILE_PHOTO)
            ->firstOrFail();

        $this->assertSame(SuchakVerificationRecord::STATUS_APPROVED, $record->admin_status);
        $this->assertSame(SuchakVerificationRecord::MODERATION_SAFE, $record->moderation_decision);
        $this->assertNotEmpty($record->document_path);
        $this->assertStringEndsWith('.webp', $record->document_path);
        Storage::disk('local')->assertExists($record->document_path);
        $this->assertNotEmpty($account->profile_photo_path);
        Storage::disk('public')->assertExists($account->profile_photo_path);
        $this->assertDatabaseMissing('matrimony_profiles', [
            'user_id' => $user->id,
        ]);
    }

    public function test_suchak_profile_photo_ai_review_stays_pending_until_admin_approve(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->mock(\App\Services\Image\ImageModerationService::class, function ($mock): void {
            $mock->shouldReceive('moderateProfilePhoto')
                ->once()
                ->andReturn([
                    'status' => 'pending_manual',
                    'reason' => 'Uncertain',
                    'meta' => [],
                ]);
        });

        $user = User::factory()->create([
            'mobile' => '9876543220',
            'mobile_verified_at' => now(),
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'mobile_number' => '9876543220',
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
        ]);

        $this->actingAs($user)
            ->post(route('suchak.register.photo.store'), [
                'profile_photo' => UploadedFile::fake()->image('suchak-card.jpg', 320, 320),
            ])
            ->assertRedirect(route('suchak.register.status'))
            ->assertSessionHas('success');

        $account->refresh();
        $record = SuchakVerificationRecord::query()
            ->where('suchak_account_id', $account->id)
            ->where('verification_type', SuchakVerificationRecord::TYPE_PROFILE_PHOTO)
            ->firstOrFail();

        $this->assertNull($account->profile_photo_path);
        $this->assertSame(SuchakVerificationRecord::STATUS_PENDING, $record->admin_status);
        $this->assertSame(SuchakVerificationRecord::MODERATION_REVIEW, $record->moderation_decision);

        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.verification-records.approve', [$account, $record]), [
                'reason' => 'Suchak photo is clear and acceptable.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $account->refresh();
        $record->refresh();

        $this->assertSame(SuchakVerificationRecord::STATUS_APPROVED, $record->admin_status);
        $this->assertNotEmpty($account->profile_photo_path);
        Storage::disk('public')->assertExists($account->profile_photo_path);
    }

    public function test_suchak_registration_password_fields_use_native_password_visibility_only(): void
    {
        $response = $this->get(route('suchak.register.info'));

        $response->assertOk()
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('type="password"', false)
            ->assertDontSee('data-password-field', false)
            ->assertDontSee('data-password-toggle', false)
            ->assertDontSee('name="password" type="text"', false)
            ->assertSeeInOrder([
                'Address',
                'Password',
                'Confirm password',
                'Register Free',
            ], false);
    }

    public function test_suchak_registration_otp_verification_marks_mobile_verified_without_profile(): void
    {
        $user = User::factory()->create([
            'mobile' => '9876543211',
            'mobile_verified_at' => null,
        ]);
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'mobile_number' => '9876543211',
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
        ]);

        Cache::put('suchak_registration_otp:'.$user->id, [
            'hash' => Hash::make('123456'),
            'attempts' => 0,
            'mobile' => '9876543211',
        ], 600);

        $this->actingAs($user)
            ->post(route('suchak.register.verify.submit'), [
                'otp' => '123456',
            ])
            ->assertRedirect(route('suchak.register.status'));

        $this->assertNotNull($user->fresh()->mobile_verified_at);
        $this->assertNull($user->fresh()->matrimonyProfile);
        $this->assertNull(Cache::get('suchak_registration_otp:'.$user->id));
    }

    public function test_otp_verification_keeps_suchak_pending_but_allows_work_when_policy_enabled(): void
    {
        $user = User::factory()->create([
            'mobile' => '9876543216',
            'mobile_verified_at' => null,
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'mobile_number' => '9876543216',
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
        ]);

        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_AUTO_APPROVE_ON_OTP],
            [
                'policy_value' => 'true',
                'value_type' => SuchakPolicy::TYPE_BOOLEAN,
                'description' => 'Test auto approve after OTP.',
                'is_active' => true,
            ],
        );
        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_ALLOW_WORK_BEFORE_ADMIN_APPROVAL],
            [
                'policy_value' => 'true',
                'value_type' => SuchakPolicy::TYPE_BOOLEAN,
                'description' => 'Test pending review work access after OTP.',
                'is_active' => true,
            ],
        );
        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_AUTO_PUBLISH_ON_APPROVAL],
            [
                'policy_value' => 'true',
                'value_type' => SuchakPolicy::TYPE_BOOLEAN,
                'description' => 'Test auto publish after approval.',
                'is_active' => true,
            ],
        );

        Cache::put('suchak_registration_otp:'.$user->id, [
            'hash' => Hash::make('123456'),
            'attempts' => 0,
            'mobile' => '9876543216',
        ], 600);

        $this->actingAs($user)
            ->post(route('suchak.register.verify.submit'), [
                'otp' => '123456',
            ])
            ->assertRedirect(route('suchak.register.status'))
            ->assertSessionHas('success', 'OTP verified. You can continue Suchak setup and work while admin review remains pending.');

        $account->refresh();

        $this->assertNotNull($user->fresh()->mobile_verified_at);
        $this->assertSame(SuchakAccount::VERIFICATION_PENDING, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->public_status);
        $this->assertNull($account->verified_at);

        $this->assertDatabaseMissing('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_type' => SuchakActivityLog::ACTOR_SYSTEM,
            'action_type' => SuchakActivityLog::ACTION_SUCHAK_AUTO_APPROVED,
            'admin_audit_log_id' => null,
        ]);

        $this->assertDatabaseMissing('suchak_verification_records', [
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_OTHER,
            'admin_status' => SuchakVerificationRecord::STATUS_APPROVED,
            'admin_user_id' => null,
        ]);

        $this->assertDatabaseMissing('admin_audit_logs', [
            'entity_type' => 'SuchakAccount',
            'entity_id' => $account->id,
        ]);

        $this->actingAs($user->fresh())
            ->get(route('suchak.register.status'))
            ->assertOk()
            ->assertSee('Suchak photo is pending', false)
            ->assertSee('data-suchak-progress-current="profile_photo"', false)
            ->assertSee('data-suchak-active-step-panel="profile_photo"', false)
            ->assertSee('Suchak photo', false)
            ->assertSee('Upload photo', false)
            ->assertSee('Admin review: Upcoming', false)
            ->assertSee('Upcoming', false)
            ->assertSee(route('suchak.dashboard', ['dashboard_tab' => 'profile']), false)
            ->assertDontSee('Documents and details checked.', false)
            ->assertDontSee('Suchak card', false)
            ->assertDontSee('Work area', false)
            ->assertDontSee('Start Suchak work', false)
            ->assertSee('Open Dashboard', false);

        $this->actingAs($user->fresh())
            ->get(route('suchak.dashboard'))
            ->assertOk()
            ->assertSee('Work enabled. Admin review is still pending.', false);

        $this->actingAs($user->fresh())
            ->get(route('suchak.search.index'))
            ->assertOk()
            ->assertSee('Find Matches', false);

        $this->actingAs($user->fresh())
            ->get(route('suchak.intakes.create'))
            ->assertOk();

        $this->actingAs($user->fresh())
            ->get(route('suchak.manual-profiles.create'))
            ->assertOk();
    }

    public function test_suchak_applicant_can_view_registration_status_with_kyc_records(): void
    {
        $user = User::factory()->create([
            'mobile' => '9876543214',
            'mobile_verified_at' => now(),
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'mobile_number' => '9876543214',
            'whatsapp_number' => '9876543214',
            'profile_photo_path' => 'suchak/profile-photos/1/photo.jpg',
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
        ]);
        SuchakVerificationRecord::factory()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_IDENTITY,
            'document_path' => 'suchak/verification-documents/'.$account->id.'/identity-proof.pdf',
            'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
        ]);

        $this->actingAs($user)
            ->get(route('suchak.register.status'))
            ->assertOk()
            ->assertDontSee('Suchak Request Status', false)
            ->assertDontSee('Track what is complete, where your request is now, and what will happen next.', false)
            ->assertSee('Suchak pipeline', false)
            ->assertSee('lg:grid-cols-[minmax(0,3fr)_minmax(0,7fr)]', false)
            ->assertSee('data-suchak-progress-current="ready_work"', false)
            ->assertSee('data-suchak-active-step-panel="ready_work"', false)
            ->assertSee('Ready to work', false)
            ->assertSee('Your Suchak profile is ready. Admin review will continue in the background.', false)
            ->assertSee('Add customer profile', false)
            ->assertSee('Open Suchak Dashboard', false)
            ->assertSee('Admin review: In progress', false)
            ->assertSee('Submitted for review', false)
            ->assertSee('WhatsApp OTP', false)
            ->assertSee('Suchak photo', false)
            ->assertSee('KYC documents', false)
            ->assertDontSee('data-suchak-active-step-panel="admin_review"', false)
            ->assertDontSee('You are here', false)
            ->assertDontSee('Next:', false)
            ->assertDontSee('Automatic work area', false)
            ->assertDontSee('You do not select a work area manually', false)
            ->assertDontSee('Suchak card', false)
            ->assertDontSee('Work area', false)
            ->assertDontSee('Start Suchak work', false)
            ->assertDontSee('Other verification', false)
            ->assertDontSee('suchak/verification-documents/'.$account->id.'/identity-proof.pdf', false);
    }

    public function test_suchak_dashboard_profile_setup_tab_guides_next_steps_and_uploads(): void
    {
        $user = User::factory()->create([
            'mobile' => '9876543217',
            'mobile_verified_at' => now(),
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'suchak_name' => 'Profile Setup Suchak',
            'office_name' => 'Setup Bureau',
            'business_type' => SuchakAccount::BUSINESS_TYPE_ORGANIZATION,
            'mobile_number' => '9876543217',
            'whatsapp_number' => '9876543217',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
        ]);
        SuchakVerificationRecord::factory()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_OTHER,
            'admin_status' => SuchakVerificationRecord::STATUS_APPROVED,
            'admin_user_id' => null,
        ]);

        $this->actingAs($user)
            ->get(route('suchak.dashboard', ['dashboard_tab' => 'profile']))
            ->assertOk()
            ->assertSee('Profile setup', false)
            ->assertSee('Complete your Suchak profile first', false)
            ->assertSee('Your photo', false)
            ->assertSee('Identity proof', false)
            ->assertSee('Visiting card / office proof', false)
            ->assertSee('Organization logo / document', false)
            ->assertSee('Add customer entry', false)
            ->assertSee(route('suchak.register.photo'), false)
            ->assertSee(route('suchak.register.documents.store'), false)
            ->assertSee(route('suchak.intakes.create'), false)
            ->assertSee(route('suchak.manual-profiles.create'), false)
            ->assertSee('Work unlocked. Admin/KYC review is still pending.', false)
            ->assertDontSee('Approved. You can start Suchak work.', false);
    }

    public function test_pending_suchak_can_create_customer_profile_and_request_consent_without_public_visibility(): void
    {
        MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true],
        );
        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_ALLOW_WORK_BEFORE_ADMIN_APPROVAL],
            [
                'policy_value' => 'false',
                'value_type' => SuchakPolicy::TYPE_BOOLEAN,
                'is_active' => true,
            ],
        );

        $user = User::factory()->create([
            'mobile' => '9876543230',
            'mobile_verified_at' => now(),
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'mobile_number' => '9876543230',
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
        ]);

        $this->actingAs($user)
            ->get(route('suchak.manual-profiles.create'))
            ->assertOk()
            ->assertDontSee('Only verified Suchak accounts can create a manual candidate profile.', false);

        $this->actingAs($user)
            ->post(route('suchak.manual-profiles.store'), [
                'candidate_name' => 'Pending Suchak Candidate',
                'candidate_mobile' => '9876543231',
                'candidate_email' => 'pending-suchak-candidate@example.test',
                'candidate_gender' => 'female',
                'registering_for' => 'self',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $representation = SuchakProfileRepresentation::query()
            ->where('suchak_account_id', $account->id)
            ->with('matrimonyProfile')
            ->firstOrFail();

        $this->assertSame(SuchakProfileRepresentation::STATUS_PENDING, $representation->representation_status);
        $this->assertFalse($representation->isPubliclyVisible());
        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->fresh()->public_status);

        $this->actingAs($user)
            ->post(route('suchak.representations.consents.request', $representation), [
                'consent_method' => SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_given_by_name' => 'Pending Suchak Candidate',
                'consent_giver_relation' => 'candidate_self',
                'intended_mobile' => '9876543231',
                'consent_mobile_number' => '9876543231',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('suchak_consents', [
            'suchak_account_id' => $account->id,
            'representation_id' => $representation->id,
            'consent_status' => SuchakConsent::STATUS_REQUESTED,
            'consent_channel' => SuchakConsent::CHANNEL_SUCHAK_RELAYED_LINK,
        ]);
    }

    public function test_suchak_can_manage_extra_contact_numbers_from_account_settings(): void
    {
        $user = User::factory()->create([
            'mobile' => '9876543207',
            'mobile_verified_at' => now(),
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'mobile_number' => '9876543207',
            'whatsapp_number' => '9876543207',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('suchak.account-settings.edit'))
            ->assertOk()
            ->assertSee('Account contacts', false)
            ->assertSee('Primary number', false);

        $this->actingAs($user)
            ->post(route('suchak.account-settings.contact-numbers.store'), [
                'phone_number' => '9876543206',
                'label' => 'Assistant',
                'is_whatsapp' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $contactNumber = SuchakContactNumber::query()
            ->where('suchak_account_id', $account->id)
            ->firstOrFail();

        $this->assertSame('9876543206', $contactNumber->phone_number);
        $this->assertSame('Assistant', $contactNumber->label);
        $this->assertTrue($contactNumber->is_whatsapp);

        $this->actingAs($user)
            ->delete(route('suchak.account-settings.contact-numbers.destroy', $contactNumber))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('suchak_contact_numbers', [
            'id' => $contactNumber->id,
        ]);
    }

    public function test_verified_suchak_can_start_manual_profile_and_continue_in_centralized_wizard(): void
    {
        MasterGender::query()->firstOrCreate(
            ['key' => 'male'],
            ['label' => 'Male', 'is_active' => true],
        );
        MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true],
        );

        $user = User::factory()->create([
            'mobile' => '9876543217',
            'mobile_verified_at' => now(),
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'mobile_number' => '9876543217',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('suchak.manual-profiles.create'))
            ->assertOk()
            ->assertSee('Manual candidate profile', false)
            ->assertSee('Continue to profile form', false);

        $this->actingAs($user)
            ->post(route('suchak.manual-profiles.store'), [
                'candidate_name' => 'Manual Suchak Candidate',
                'candidate_mobile' => '9876543218',
                'candidate_email' => 'manual-suchak-candidate@example.test',
                'candidate_gender' => 'female',
                'registering_for' => 'parent_guardian',
            ])
            ->assertRedirect();

        $member = User::query()->where('mobile', '9876543218')->firstOrFail();
        $profile = MatrimonyProfile::query()->where('user_id', $member->id)->firstOrFail();
        $representation = SuchakProfileRepresentation::query()
            ->where('suchak_account_id', $account->id)
            ->where('matrimony_profile_id', $profile->id)
            ->firstOrFail();

        $this->assertSame('Manual Suchak Candidate', $profile->full_name);
        $this->assertSame(SuchakProfileRepresentation::MODE_MANUAL_FORM_BY_SUCHAK, $representation->representation_mode);
        $this->assertSame(SuchakProfileRepresentation::STATUS_PENDING, $representation->representation_status);
        $this->assertNull($representation->biodata_intake_id);

        $this->assertDatabaseHas('suchak_customer_contexts', [
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'source_owner' => SuchakCustomerContext::SOURCE_OWNER_SUCHAK,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
        ]);

        $this->actingAs($user)
            ->withSession([
                'suchak_registration_account_id' => $account->id,
                'suchak_registration_profile_id' => $profile->id,
                'suchak_registration_representation_id' => $representation->id,
                'suchak_edit_profile_id' => $profile->id,
            ])
            ->get(route('matrimony.profile.wizard.section', [
                'section' => 'full',
                'all' => 1,
                'profile_id' => $profile->id,
            ]))
            ->assertOk()
            ->assertSee('Manual Suchak Candidate', false);

        $this->actingAs($user)
            ->get(route('suchak.dashboard', [
                'dashboard_tab' => 'profiles',
                'manage_representation' => $representation->id,
            ]))
            ->assertOk()
            ->assertSee('Matrimony ID', false)
            ->assertSee('#'.$profile->id, false)
            ->assertSee('Manual Suchak Candidate', false)
            ->assertSee('Gender', false)
            ->assertSee('Height', false)
            ->assertSee('Village / residence', false)
            ->assertSee(route('suchak.representations.profile-form', $representation), false);

        $this->actingAs($user)
            ->get(route('suchak.representations.profile-form', $representation))
            ->assertRedirect(route('matrimony.profile.wizard.section', [
                'section' => 'full',
                'all' => 1,
                'profile_id' => $profile->id,
            ]));
    }

    public function test_verified_suchak_can_upload_photo_for_manual_profile_target_without_own_member_profile(): void
    {
        Queue::fake();

        $suchakUser = User::factory()->create([
            'mobile' => '9876543222',
            'mobile_verified_at' => now(),
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'mobile_number' => '9876543222',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
        ]);
        $candidateUser = User::factory()->create([
            'name' => 'Suchak Photo Candidate',
            'mobile' => '9876543223',
        ]);
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $candidateUser->id,
            'full_name' => 'Suchak Photo Candidate',
            'lifecycle_state' => 'draft',
        ]);
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
            'representation_mode' => SuchakProfileRepresentation::MODE_MANUAL_FORM_BY_SUCHAK,
            'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
        ]);

        $session = [
            'suchak_registration_account_id' => $account->id,
            'suchak_registration_profile_id' => $profile->id,
            'suchak_registration_representation_id' => $representation->id,
            'suchak_edit_profile_id' => $profile->id,
        ];

        $this->assertNull($suchakUser->matrimonyProfile);

        $this->actingAs($suchakUser)
            ->withSession($session)
            ->get(route('matrimony.profile.wizard.section', [
                'section' => 'photo',
                'profile_id' => $profile->id,
            ]))
            ->assertOk()
            ->assertSee(route('matrimony.profile.upload-photo', ['profile_id' => $profile->id]), false);

        $this->actingAs($suchakUser)
            ->withSession($session)
            ->get(route('matrimony.profile.upload-photo', ['profile_id' => $profile->id]))
            ->assertOk()
            ->assertDontSee(__('profile_actions.create_profile_first'), false)
            ->assertSee('name="profile_id" value="'.$profile->id.'"', false);

        $this->actingAs($suchakUser)
            ->withSession($session)
            ->post(route('matrimony.profile.store-photo'), [
                'profile_id' => $profile->id,
                'profile_photo' => UploadedFile::fake()->image('suchak-candidate.jpg', 640, 640),
            ])
            ->assertRedirect(route('matrimony.profile.upload-photo', ['profile_id' => $profile->id]))
            ->assertSessionHas('member_notice');

        Queue::assertPushed(ProcessProfilePhoto::class);

        $profile->refresh();
        $this->assertStringStartsWith('pending/', (string) $profile->profile_photo);
        $this->assertNull($suchakUser->fresh()->matrimonyProfile);
    }

    public function test_verified_suchak_can_use_existing_profile_when_manual_mobile_is_already_registered(): void
    {
        MasterGender::query()->firstOrCreate(
            ['key' => 'male'],
            ['label' => 'Male', 'is_active' => true],
        );
        MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true],
        );

        $suchakUser = User::factory()->create([
            'mobile' => '9876543221',
            'mobile_verified_at' => now(),
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'mobile_number' => '9876543221',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
        ]);
        $existingMember = User::factory()->create([
            'name' => 'Existing Registered Candidate',
            'mobile' => '9876543220',
            'email' => 'existing-registered-candidate@example.test',
        ]);
        $existingProfile = MatrimonyProfile::factory()->create([
            'user_id' => $existingMember->id,
            'full_name' => 'Existing Registered Candidate',
        ]);

        $userCount = User::query()->count();
        $profileCount = MatrimonyProfile::query()->count();

        $payload = [
            'candidate_name' => 'Duplicate Mobile Candidate',
            'candidate_mobile' => '9876543220',
            'candidate_email' => 'new-but-ignored-for-existing@example.test',
            'candidate_gender' => 'female',
            'registering_for' => 'parent_guardian',
        ];

        $this->actingAs($suchakUser)
            ->post(route('suchak.manual-profiles.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('suchak_existing_profile_match', function (array $match): bool {
                return ($match['mobile_mask'] ?? null) === '******3220';
            });

        $this->assertDatabaseCount('users', $userCount);
        $this->assertDatabaseCount('matrimony_profiles', $profileCount);
        $this->assertDatabaseMissing('suchak_profile_representations', [
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $existingProfile->id,
        ]);

        $this->actingAs($suchakUser)
            ->withSession([
                'suchak_existing_profile_match' => [
                    'mobile_mask' => '******3220',
                ],
            ])
            ->get(route('suchak.manual-profiles.create'))
            ->assertOk()
            ->assertSee('Existing profile found for this mobile number.', false)
            ->assertSee('Use existing profile and request consent', false);

        $this->actingAs($suchakUser)
            ->post(route('suchak.manual-profiles.store'), array_merge($payload, [
                'use_existing_profile' => '1',
            ]))
            ->assertRedirect(route('suchak.dashboard', ['dashboard_tab' => 'profiles']))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('users', $userCount);
        $this->assertDatabaseCount('matrimony_profiles', $profileCount);

        $representation = SuchakProfileRepresentation::query()
            ->where('suchak_account_id', $account->id)
            ->where('matrimony_profile_id', $existingProfile->id)
            ->firstOrFail();

        $this->assertSame(SuchakProfileRepresentation::MODE_MATCHED_EXISTING_PROFILE, $representation->representation_mode);
        $this->assertSame(SuchakProfileRepresentation::STATUS_CONSENT_PENDING, $representation->representation_status);
        $this->assertSame(SuchakProfileRepresentation::CONSENT_REQUESTED, $representation->consent_status);
        $this->assertNull($representation->biodata_intake_id);

        $consent = SuchakConsent::query()
            ->where('representation_id', $representation->id)
            ->firstOrFail();

        $this->assertSame(SuchakConsent::STATUS_REQUESTED, $consent->consent_status);
        $this->assertSame(SuchakConsent::CHANNEL_SUCHAK_RELAYED_LINK, $consent->consent_channel);
        $this->assertSame('9876543220', $consent->consent_mobile_number);

        $this->assertDatabaseHas('suchak_customer_contexts', [
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $existingProfile->id,
            'representation_id' => $representation->id,
            'consent_id' => $consent->id,
            'source_owner' => SuchakCustomerContext::SOURCE_OWNER_SUCHAK,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_EXISTING_PROFILE_MATCH,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_CONSENT_PENDING,
        ]);
    }

    public function test_authenticated_regular_user_cannot_post_separate_suchak_registration(): void
    {
        $user = User::factory()->create([
            'mobile' => '9876543212',
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->post(route('suchak.register.store'), [
                'suchak_name' => 'Wrong Suchak Upgrade',
                'business_type' => SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
                'whatsapp_number' => '9876543213',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])
            ->assertRedirect(route('suchak.register.info'));

        $this->assertDatabaseCount('suchak_accounts', 0);
    }

    public function test_authenticated_regular_user_cannot_apply_to_become_suchak(): void
    {
        $user = User::factory()->create([
            'name' => 'Regular Member',
            'email' => 'regular-member@example.test',
            'mobile' => '9999999999',
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->get(route('suchak.apply.create'))
            ->assertRedirect(route('suchak.register.info'));

        $this->actingAs($user)
            ->post(route('suchak.apply.store'), [
                'suchak_name' => 'Wrong Upgrade Attempt',
                'office_name' => 'Test Bureau',
                'business_type' => SuchakAccount::BUSINESS_TYPE_BUREAU,
                'whatsapp_number' => '9999999999',
                'email' => 'business@example.test',
                'address_line' => 'Test address',
            ])
            ->assertRedirect(route('suchak.register.info'));

        $this->assertFalse($user->fresh()->is_admin);
        $this->assertNull($user->fresh()->suchakAccount);
        $this->assertDatabaseCount('suchak_accounts', 0);
        $this->assertDatabaseMissing('suchak_activity_logs', [
            'actor_user_id' => $user->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_SUCHAK_ONBOARDING_REQUESTED,
        ]);
    }

    public function test_guest_apply_route_is_still_auth_protected(): void
    {
        $this->get(route('suchak.apply.create'))
            ->assertRedirect(route('login'));
    }

    public function test_apply_route_does_not_attach_existing_suchak_account_to_regular_upgrade_flow(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('suchak.apply.store'), [
                'suchak_name' => 'Blocked Suchak',
                'business_type' => SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
            ])
            ->assertRedirect(route('suchak.register.info'));

        $this->assertSame(0, SuchakAccount::query()->where('user_id', $user->id)->count());
    }

    public function test_admin_can_review_and_approve_pending_suchak_account_with_audit_links(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        $account = SuchakAccount::factory()->create([
            'suchak_name' => 'Pending Review Suchak',
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.suchak.accounts.index'))
            ->assertOk()
            ->assertSee($account->suchak_name, false);

        $this->actingAs($admin)
            ->get(route('admin.suchak.accounts.show', $account))
            ->assertOk()
            ->assertSee($account->suchak_name, false)
            ->assertSee('Admin Actions', false);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.approve', $account), [
                'reason' => 'Verified account details for Day-4.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $account->refresh();

        $this->assertSame(SuchakAccount::VERIFICATION_VERIFIED, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->public_status);
        $this->assertNotNull($account->verified_at);

        $adminAuditLog = AdminAuditLog::query()
            ->where('action_type', 'suchak_account_approved')
            ->where('entity_type', 'SuchakAccount')
            ->where('entity_id', $account->id)
            ->first();

        $this->assertNotNull($adminAuditLog);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'action_type' => SuchakActivityLog::ACTION_ADMIN_AUDIT_LINKED,
            'admin_audit_log_id' => $adminAuditLog->id,
        ]);

        $this->assertDatabaseHas('suchak_verification_records', [
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_OTHER,
            'admin_status' => SuchakVerificationRecord::STATUS_APPROVED,
            'admin_user_id' => $admin->id,
        ]);
    }

    public function test_admin_auto_publish_policy_can_make_approved_suchak_public_active(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
        ]);

        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_AUTO_PUBLISH_ON_APPROVAL],
            [
                'policy_value' => 'true',
                'value_type' => SuchakPolicy::TYPE_BOOLEAN,
                'description' => 'Test auto publish approved Suchak publicly.',
                'is_active' => true,
            ],
        );

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.approve', $account), [
                'reason' => 'Verified account details and auto-publish policy is enabled.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $account->refresh();

        $this->assertSame(SuchakAccount::VERIFICATION_VERIFIED, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_ACTIVE, $account->public_status);
    }

    public function test_admin_can_reject_pending_suchak_account_with_audit_links(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.reject', $account), [
                'reason' => 'Business identity could not be verified.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $account->refresh();

        $this->assertSame(SuchakAccount::VERIFICATION_REJECTED, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->public_status);
        $this->assertNotNull($account->rejected_at);

        $adminAuditLog = AdminAuditLog::query()
            ->where('action_type', 'suchak_account_rejected')
            ->where('entity_id', $account->id)
            ->first();

        $this->assertNotNull($adminAuditLog);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'admin_audit_log_id' => $adminAuditLog->id,
        ]);

        $this->assertDatabaseHas('suchak_verification_records', [
            'suchak_account_id' => $account->id,
            'admin_status' => SuchakVerificationRecord::STATUS_REJECTED,
            'admin_user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_suspend_verified_suchak_account_with_audit_links(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.suspend', $account), [
                'reason' => 'Suspending after admin verification review.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $account->refresh();

        $this->assertSame(SuchakAccount::VERIFICATION_SUSPENDED, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->public_status);
        $this->assertSame('Suspending after admin verification review.', $account->suspension_reason);
        $this->assertNotNull($account->suspended_at);

        $adminAuditLog = AdminAuditLog::query()
            ->where('action_type', 'suchak_account_suspended')
            ->where('entity_id', $account->id)
            ->first();

        $this->assertNotNull($adminAuditLog);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'admin_audit_log_id' => $adminAuditLog->id,
        ]);
    }

    public function test_admin_can_archive_verified_suchak_account_with_audit_links(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.archive', $account), [
                'reason' => 'Archiving after Suchak office closure.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $account->refresh();

        $this->assertSame(SuchakAccount::VERIFICATION_ARCHIVED, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->public_status);
        $this->assertNotNull($account->archived_at);

        $adminAuditLog = AdminAuditLog::query()
            ->where('action_type', 'suchak_account_archived')
            ->where('entity_id', $account->id)
            ->first();

        $this->assertNotNull($adminAuditLog);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'admin_audit_log_id' => $adminAuditLog->id,
        ]);
    }

    public function test_admin_can_reactivate_suspended_suchak_account_to_verified_hidden(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_SUSPENDED,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'verified_at' => now()->subDay(),
            'suspended_at' => now(),
            'suspension_reason' => 'Old suspension reason.',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.reactivate', $account), [
                'reason' => 'Reactivating after admin review cleared issue.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $account->refresh();

        $this->assertSame(SuchakAccount::VERIFICATION_VERIFIED, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->public_status);
        $this->assertNull($account->suspended_at);
        $this->assertNull($account->suspension_reason);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action_type' => 'suchak_account_reactivated',
            'entity_type' => 'SuchakAccount',
            'entity_id' => $account->id,
        ]);
    }

    public function test_admin_reactivation_from_archived_reopens_pending_review_without_direct_verified_jump(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_ARCHIVED,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'verified_at' => now()->subDays(3),
            'archived_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.reactivate', $account), [
                'reason' => 'Reopening archived Suchak for fresh verification.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $account->refresh();

        $this->assertSame(SuchakAccount::VERIFICATION_PENDING, $account->verification_status);
        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->public_status);
        $this->assertNull($account->verified_at);
        $this->assertNull($account->archived_at);

        $this->assertDatabaseHas('suchak_verification_records', [
            'suchak_account_id' => $account->id,
            'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
            'admin_user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_change_verified_suchak_public_status_with_audit(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.public-status.update', $account), [
                'public_status' => SuchakAccount::PUBLIC_ACTIVE,
                'reason' => 'Making verified Suchak publicly visible.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $this->assertSame(SuchakAccount::PUBLIC_ACTIVE, $account->fresh()->public_status);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.public-status.update', $account), [
                'public_status' => SuchakAccount::PUBLIC_INACTIVE,
                'reason' => 'Temporarily making public listing inactive.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $this->assertSame(SuchakAccount::PUBLIC_INACTIVE, $account->fresh()->public_status);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action_type' => 'suchak_public_status_changed',
            'entity_type' => 'SuchakAccount',
            'entity_id' => $account->id,
        ]);
    }

    public function test_admin_cannot_make_unverified_suchak_public_active(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.suchak.accounts.show', $account))
            ->post(route('admin.suchak.accounts.public-status.update', $account), [
                'public_status' => SuchakAccount::PUBLIC_ACTIVE,
                'reason' => 'Trying to expose unverified Suchak publicly.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $this->assertSame(SuchakAccount::PUBLIC_HIDDEN, $account->fresh()->public_status);
        $this->assertDatabaseMissing('admin_audit_logs', [
            'action_type' => 'suchak_public_status_changed',
            'entity_id' => $account->id,
        ]);
    }

    public function test_admin_can_review_document_verification_record_status_with_audit_links(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
        ]);
        $record = SuchakVerificationRecord::factory()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_OFFICE,
            'document_path' => 'suchak-documents/office-proof.pdf',
            'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.verification-records.approve', [$account, $record]), [
                'reason' => 'Office document looks valid.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $record->refresh();

        $this->assertSame(SuchakVerificationRecord::STATUS_APPROVED, $record->admin_status);
        $this->assertSame($admin->id, $record->admin_user_id);
        $this->assertNotNull($record->verified_at);
        $this->assertNull($record->rejected_at);

        $adminAuditLog = AdminAuditLog::query()
            ->where('action_type', 'suchak_verification_record_status_changed')
            ->where('entity_type', 'SuchakVerificationRecord')
            ->where('entity_id', $record->id)
            ->first();

        $this->assertNotNull($adminAuditLog);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'target_type' => 'suchak_verification_record',
            'target_id' => $record->id,
            'admin_audit_log_id' => $adminAuditLog->id,
        ]);
    }

    public function test_admin_can_view_uploaded_suchak_verification_document_privately(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
        ]);
        $path = 'suchak/verification-documents/'.$account->id.'/identity-proof.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 identity proof');

        $record = SuchakVerificationRecord::factory()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_IDENTITY,
            'document_path' => $path,
            'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.suchak.accounts.show', $account))
            ->assertOk()
            ->assertSee('View document', false)
            ->assertSee(route('admin.suchak.accounts.verification-records.document', [$account, $record]), false)
            ->assertDontSee($path, false);

        $this->actingAs($admin)
            ->get(route('admin.suchak.accounts.verification-records.document', [$account, $record]))
            ->assertOk();
    }

    public function test_admin_cannot_approve_non_pending_suchak_account_on_day_4(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        $account = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_REJECTED,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.suchak.accounts.show', $account))
            ->post(route('admin.suchak.accounts.approve', $account), [
                'reason' => 'Trying an invalid Day-4 transition.',
            ])
            ->assertRedirect(route('admin.suchak.accounts.show', $account));

        $this->assertSame(SuchakAccount::VERIFICATION_REJECTED, $account->fresh()->verification_status);
        $this->assertDatabaseMissing('admin_audit_logs', [
            'action_type' => 'suchak_account_approved',
            'entity_id' => $account->id,
        ]);
    }
}
