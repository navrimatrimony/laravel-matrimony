<?php

namespace Tests\Feature;

use App\Models\BiodataIntake;
use App\Models\ConflictRecord;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\MutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntakeContactApplyStrictTest extends TestCase
{
    use RefreshDatabase;

    public function test_intake_does_not_replace_existing_primary_contact_and_inserts_alternate_self(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => 'Test User',
            'lifecycle_state' => 'draft',
        ]);

        DB::table('profile_contacts')->insert([
            'profile_id' => $profile->id,
            'contact_name' => 'Self',
            'phone_number' => '9876543210',
            'is_primary' => true,
            'visibility_rule' => 'unlock_only',
            'verified_status' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $snapshot = [
            'snapshot_schema_version' => 1,
            'core' => [
                'full_name' => 'Test User',
                'primary_contact_number' => '9123456789',
            ],
            'contacts' => [
                [
                    'phone_number' => '9123456789',
                    'number' => '9123456789',
                    'is_primary' => true,
                    'label' => 'self',
                    'type' => 'primary',
                ],
            ],
            'children' => [],
        ];

        $intake = BiodataIntake::create([
            'raw_ocr_text' => 'test',
            'uploaded_by' => $user->id,
            'matrimony_profile_id' => $profile->id,
            'parse_status' => 'parsed',
            'intake_status' => 'approved',
            'approved_by_user' => true,
            'approved_at' => now(),
            'approval_snapshot_json' => $snapshot,
            'snapshot_schema_version' => 1,
            'intake_locked' => false,
        ]);

        app(MutationService::class)->applyApprovedIntake($intake->id);

        $primary = DB::table('profile_contacts')
            ->where('profile_id', $profile->id)
            ->where('is_primary', true)
            ->value('phone_number');
        $this->assertSame('9876543210', $primary);

        $this->assertTrue(
            DB::table('profile_contacts')
                ->where('profile_id', $profile->id)
                ->where('phone_number', '9123456789')
                ->where('is_primary', false)
                ->exists()
        );

        $this->assertTrue(
            ConflictRecord::where('profile_id', $profile->id)
                ->where('field_name', 'primary_contact_number')
                ->where('resolution_status', 'PENDING')
                ->exists()
        );
    }

    public function test_intake_father_second_number_becomes_additional_contact_when_first_fills_core_slot(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => 'Child User',
            'father_contact_1' => null,
            'lifecycle_state' => 'draft',
        ]);

        $snapshot = [
            'snapshot_schema_version' => 1,
            'core' => [
                'full_name' => 'Child User',
                'father_contact_1' => '9111111111',
                'father_contact_2' => '9222222222',
            ],
            'contacts' => [],
            'children' => [],
        ];

        $intake = BiodataIntake::create([
            'raw_ocr_text' => 'test',
            'uploaded_by' => $user->id,
            'matrimony_profile_id' => $profile->id,
            'parse_status' => 'parsed',
            'intake_status' => 'approved',
            'approved_by_user' => true,
            'approved_at' => now(),
            'approval_snapshot_json' => $snapshot,
            'snapshot_schema_version' => 1,
            'intake_locked' => false,
        ]);

        app(MutationService::class)->applyApprovedIntake($intake->id);

        $profile->refresh();
        $this->assertSame('9111111111', preg_replace('/\D/', '', (string) $profile->father_contact_1));

        $fatherRows = DB::table('profile_contacts')
            ->where('profile_id', $profile->id)
            ->where('phone_number', '9222222222')
            ->count();
        $this->assertSame(1, $fatherRows);
    }
}
