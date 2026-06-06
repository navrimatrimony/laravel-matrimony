<?php

namespace Tests\Feature;

use App\Models\BiodataIntake;
use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Intake\IntakeApprovalService as PendingIntakeApprovalService;
use App\Services\MutationService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IntakeLegalCasesApprovalApplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        $this->seed(MasterLookupSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_approval_route_keeps_legal_cases_and_diagnostics_remain_non_profile(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => 'Legal Approval Candidate',
            'lifecycle_state' => 'draft',
        ]);
        $this->attachResidence($profile);

        $legalCase = [
            'case_type' => 'civil',
            'court_name' => 'District Court',
            'case_number' => 'RCS-42',
            'case_stage' => 'hearing',
            'next_hearing_date' => '2026-07-15',
            'notes' => 'Pending hearing',
            'active_status' => true,
        ];
        $parsed = [
            'section_order' => ['basic-info', 'legal-cases'],
            'sectioned' => ['legal-cases' => [$legalCase]],
            'missing_map' => ['basic-info.caste' => ['reason' => 'not_present_in_biodata']],
            'core' => ['full_name' => 'Legal Approval Candidate'],
            'contacts' => [],
            'legal_cases' => [$legalCase],
        ];
        $intake = $this->createParsedIntake($user, $profile, $parsed);
        $rawOcrText = $intake->raw_ocr_text;

        $response = $this->actingAs($user)
            ->withSession(['preview_seen_'.$intake->id => true])
            ->post(route('intake.approve', $intake), [
                'snapshot' => [
                    'core' => ['full_name' => 'Legal Approval Candidate'],
                ],
            ]);

        $response->assertRedirect(route('intake.status', $intake));

        $intake->refresh();
        $approval = $intake->approval_snapshot_json;
        $this->assertSame($rawOcrText, $intake->raw_ocr_text);
        $this->assertSame([$legalCase], $approval['legal_cases'] ?? null);
        $this->assertArrayHasKey('section_order', $approval);
        $this->assertArrayHasKey('sectioned', $approval);
        $this->assertArrayHasKey('missing_map', $approval);
        $this->assertFalse(Schema::hasColumn('matrimony_profiles', 'sectioned'));
        $this->assertFalse(Schema::hasColumn('matrimony_profiles', 'missing_map'));
        $this->assertFalse(Schema::hasColumn('matrimony_profiles', 'section_order'));
        $this->assertTrue((bool) DB::table('profile_legal_cases')
            ->where('profile_id', $profile->id)
            ->where('case_number', 'RCS-42')
            ->where('active_status', true)
            ->exists());
    }

    public function test_mutation_service_applies_legal_cases_and_ignores_diagnostics(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => 'Legal Apply Candidate',
            'lifecycle_state' => 'draft',
        ]);
        $this->attachResidence($profile);

        $snapshot = [
            'snapshot_schema_version' => 1,
            'section_order' => ['legal-cases'],
            'sectioned' => ['legal-cases' => [['diagnostic_only' => true]]],
            'missing_map' => ['legal-cases.0.notes' => ['reason' => 'not_present_in_biodata']],
            'core' => ['full_name' => 'Legal Apply Candidate'],
            'contacts' => [],
            'legal_cases' => [[
                'court_name' => 'Family Court',
                'case_number' => 'FC-7',
                'case_stage' => 'closed',
                'next_hearing_date' => null,
                'notes' => null,
                'active_status' => false,
            ]],
        ];
        $intake = $this->createApprovedIntake($user, $profile, $snapshot);

        $result = app(MutationService::class)->applyApprovedIntake($intake->id);

        $this->assertTrue($result['mutation_success'] ?? false);
        $row = DB::table('profile_legal_cases')
            ->where('profile_id', $profile->id)
            ->where('case_number', 'FC-7')
            ->first();
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->active_status);
        $this->assertFalse(Schema::hasColumn('matrimony_profiles', 'sectioned'));
        $this->assertFalse(Schema::hasColumn('matrimony_profiles', 'missing_map'));
        $this->assertFalse(Schema::hasColumn('matrimony_profiles', 'section_order'));
    }

    public function test_existing_legal_cases_require_governed_pending_review_before_sync(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => 'Legal Review Candidate',
            'lifecycle_state' => 'draft',
        ]);
        $this->attachResidence($profile);
        DB::table('profile_legal_cases')->insert([
            'profile_id' => $profile->id,
            'court_name' => 'Existing Court',
            'case_number' => 'OLD-1',
            'active_status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $snapshot = [
            'snapshot_schema_version' => 1,
            'core' => ['full_name' => 'Legal Review Candidate'],
            'contacts' => [],
            'legal_cases' => [[
                'court_name' => 'New Court',
                'case_number' => 'NEW-2',
                'case_stage' => 'hearing',
                'active_status' => true,
            ]],
        ];
        $intake = $this->createApprovedIntake($user, $profile, $snapshot);

        app(MutationService::class)->applyApprovedIntake($intake->id);

        $profile->refresh();
        $this->assertSame(
            $snapshot['legal_cases'],
            $profile->pending_intake_suggestions_json['entities']['legal_cases'] ?? null
        );
        $this->assertDatabaseHas('profile_legal_cases', [
            'profile_id' => $profile->id,
            'case_number' => 'OLD-1',
        ]);
        $this->assertDatabaseMissing('profile_legal_cases', [
            'profile_id' => $profile->id,
            'case_number' => 'NEW-2',
        ]);

        $review = app(PendingIntakeApprovalService::class)
            ->applyApprovedFields($profile, ['entity::legal_cases'], (int) $user->id);

        $this->assertSame(1, $review['applied']);
        $this->assertSame([], $review['errors']);
        $this->assertDatabaseHas('profile_legal_cases', [
            'profile_id' => $profile->id,
            'case_number' => 'NEW-2',
            'active_status' => true,
        ]);
    }

    private function createParsedIntake(User $user, MatrimonyProfile $profile, array $parsed): BiodataIntake
    {
        return BiodataIntake::create([
            'raw_ocr_text' => 'test legal case',
            'uploaded_by' => $user->id,
            'matrimony_profile_id' => $profile->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'parsed_json' => $parsed,
            'approved_by_user' => false,
            'snapshot_schema_version' => 1,
            'intake_locked' => false,
        ]);
    }

    private function createApprovedIntake(User $user, MatrimonyProfile $profile, array $snapshot): BiodataIntake
    {
        return BiodataIntake::create([
            'raw_ocr_text' => 'test legal case',
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
    }

    private function attachResidence(MatrimonyProfile $profile): void
    {
        $leafId = (int) City::query()->where('name', 'Pune City')->firstOrFail()->id;
        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $leafId]);
            $profile->refresh();

            return;
        }

        ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $leafId, null, true, false);
    }
}
