<?php

namespace Tests\Feature\Admin;

use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminIntakeEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MasterGender::query()->firstOrCreate(
            ['key' => 'male'],
            ['label' => 'Male', 'is_active' => true],
        );
        MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true],
        );
    }

    public function test_admin_can_open_intake_entry_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create([
            'is_admin' => false,
            'admin_role' => null,
            'name' => 'Selectable Member',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.biodata-intakes.create'))
            ->assertOk()
            ->assertSee('Create Profile')
            ->assertSee('Biodata Intake')
            ->assertSee('Manual Form')
            ->assertSee('Gender')
            ->assertSee((string) $member->id);
    }

    public function test_admin_can_open_manual_profile_registration_tab(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.biodata-intakes.create-profile'))
            ->assertOk()
            ->assertSee('Continue to Edit all')
            ->assertSee('Registering for')
            ->assertSee('Gender');
    }

    public function test_admin_intake_show_reuses_normalized_biodata_draft_preview(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create(['is_admin' => false, 'admin_role' => null]);
        $intake = BiodataIntake::create([
            'uploaded_by' => $member->id,
            'raw_ocr_text' => "Name: Admin Preview Candidate\nGender: Male",
            'intake_status' => 'uploaded',
            'parse_status' => 'parsed',
            'last_parse_input_text' => "Name: Admin Preview Candidate\nGender: Male",
            'parsed_json' => [
                'core' => [
                    'full_name' => 'Admin Preview Candidate',
                    'gender' => 'male',
                ],
            ],
            'approved_by_user' => false,
            'intake_locked' => false,
            'snapshot_schema_version' => 1,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.biodata-intakes.show', $intake))
            ->assertOk()
            ->assertSee('Normalized Biodata Draft')
            ->assertSee('Admin Preview Candidate');
    }

    public function test_admin_can_create_intake_for_existing_user_without_creating_profile(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create(['is_admin' => false, 'admin_role' => null]);

        $response = $this->actingAs($admin)->post(route('admin.biodata-intakes.store'), [
            'user_mode' => 'existing',
            'existing_user_id' => $member->id,
            'raw_text' => 'Name: Existing Member Candidate',
        ]);

        $intake = BiodataIntake::query()->sole();

        $response->assertRedirect(route('admin.biodata-intakes.show', $intake));
        $this->assertSame((int) $member->id, (int) $intake->uploaded_by);
        $this->assertSame('uploaded', $intake->intake_status);
        $this->assertSame('pending', $intake->parse_status);
        $this->assertSame(0, MatrimonyProfile::query()->count());
        Queue::assertPushed(ParseIntakeJob::class);
    }

    public function test_admin_can_create_new_user_and_intake_atomically_without_profile(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post(route('admin.biodata-intakes.store'), [
            'user_mode' => 'new',
            'new_name' => 'Admin Registered Member',
            'new_mobile' => '+91 98765 43210',
            'new_gender' => 'female',
            'registering_for' => 'self',
            'raw_text' => 'Name: New Intake Candidate',
        ]);

        $member = User::query()->where('mobile', '9876543210')->sole();
        $intake = BiodataIntake::query()->sole();

        $response->assertRedirect(route('admin.biodata-intakes.show', $intake));
        $this->assertSame('Admin Registered Member', $member->name);
        $this->assertSame('female', $member->gender);
        $this->assertSame((int) $member->id, (int) $intake->uploaded_by);
        $this->assertSame(0, MatrimonyProfile::query()->count());
        Queue::assertPushed(ParseIntakeJob::class);
    }

    public function test_admin_new_registration_rejects_duplicate_mobile_without_creating_intake(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        User::factory()->create(['mobile' => '9876543210']);

        $response = $this->actingAs($admin)
            ->from(route('admin.biodata-intakes.create'))
            ->post(route('admin.biodata-intakes.store'), [
                'user_mode' => 'new',
                'new_name' => 'Duplicate Mobile Member',
                'new_mobile' => '9876543210',
                'new_gender' => 'male',
                'registering_for' => 'self',
                'raw_text' => 'Name: Duplicate Mobile Candidate',
            ]);

        $response->assertRedirect(route('admin.biodata-intakes.create'));
        $response->assertSessionHasErrors('new_mobile');
        $this->assertSame(0, BiodataIntake::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_non_admin_cannot_open_admin_intake_entry_page(): void
    {
        $member = User::factory()->create(['is_admin' => false, 'admin_role' => null]);

        $this->actingAs($member)
            ->get(route('admin.biodata-intakes.create'))
            ->assertForbidden();
    }

    public function test_existing_member_upload_route_uses_same_intake_creation_pipeline(): void
    {
        Queue::fake();
        $member = User::factory()->create(['is_admin' => false, 'admin_role' => null]);

        $response = $this->actingAs($member)->post(route('intake.store'), [
            'raw_text' => 'Name: Member Upload Candidate',
        ]);

        $intake = BiodataIntake::query()->sole();

        $response->assertRedirect(route('intake.status', $intake));
        $this->assertSame((int) $member->id, (int) $intake->uploaded_by);
        $this->assertSame(hash('sha256', 'Name: Member Upload Candidate'), $intake->content_hash);
        Queue::assertPushed(ParseIntakeJob::class);
    }

    public function test_admin_manual_registration_opens_existing_full_wizard_for_new_profile(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post(route('admin.biodata-intakes.store-profile'), [
            'new_name' => 'Manual Form Member',
            'new_mobile' => '9123456789',
            'new_gender' => 'male',
            'registering_for' => 'self',
        ]);

        $member = User::query()->where('mobile', '9123456789')->sole();
        $profile = MatrimonyProfile::query()->where('user_id', $member->id)->sole();

        $response->assertRedirect(route('matrimony.profile.wizard.section', [
            'section' => 'full',
            'all' => 1,
            'profile_id' => $profile->id,
        ]));
        $this->assertSame('male', $member->gender);
        $this->assertSame('draft', $profile->lifecycle_state);
        $this->assertSame((int) $profile->id, (int) session('admin_registration_profile_id'));
    }

    public function test_admin_manual_registration_rejects_duplicate_mobile(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        User::factory()->create(['mobile' => '9234567890']);

        $response = $this->actingAs($admin)
            ->from(route('admin.biodata-intakes.create-profile'))
            ->post(route('admin.biodata-intakes.store-profile'), [
                'new_name' => 'Duplicate Manual Member',
                'new_mobile' => '9234567890',
                'new_gender' => 'female',
                'registering_for' => 'self',
            ]);

        $response->assertRedirect(route('admin.biodata-intakes.create-profile'));
        $response->assertSessionHasErrors('new_mobile');
        $this->assertSame(0, MatrimonyProfile::query()->count());
    }

    public function test_admin_registration_session_cannot_target_another_regular_profile_in_wizard(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $allowedProfile = MatrimonyProfile::factory()->create(['lifecycle_state' => 'draft', 'is_showcase' => false]);
        $otherProfile = MatrimonyProfile::factory()->create(['lifecycle_state' => 'draft', 'is_showcase' => false]);

        $this->actingAs($admin)
            ->withSession([
                'admin_registration_profile_id' => $allowedProfile->id,
                'admin_edit_profile_id' => $allowedProfile->id,
            ])
            ->get(route('matrimony.profile.wizard.section', [
                'section' => 'full',
                'all' => 1,
                'profile_id' => $otherProfile->id,
            ]))
            ->assertForbidden();
    }
}
