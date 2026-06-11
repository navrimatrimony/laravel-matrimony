<?php

namespace Tests\Feature;

use App\Models\BiodataIntake;
use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\MutationService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\MinimalLocationSeeder;
use Database\Seeders\ReligionCasteSubCasteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IntakeMergePreservesFilledFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        $this->seed(MasterLookupSeeder::class);
        $this->seed(ReligionCasteSubCasteSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_intake_apply_does_not_overwrite_existing_core_full_name_and_stores_suggestion(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => 'Profile Original Name',
            'height_cm' => null,
        ]);
        $this->attachResidence($profile);

        $snapshot = [
            'snapshot_schema_version' => 1,
            'core' => [
                'full_name' => 'Intake Parsed Name',
                'height_cm' => 172,
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

        $result = app(MutationService::class)->applyApprovedIntake($intake->id);

        $this->assertTrue($result['mutation_success'] ?? false, 'mutation should succeed');
        $this->assertSame($profile->id, $result['profile_id']);

        $profile->refresh();
        $this->assertSame('Profile Original Name', $profile->full_name);
        $this->assertSame(172, (int) $profile->height_cm);

        $pending = $profile->pending_intake_suggestions_json;
        $this->assertIsArray($pending);
        $this->assertArrayHasKey('core', $pending);
        $this->assertSame('Intake Parsed Name', $pending['core']['full_name'] ?? null);
        $this->assertIsArray($pending['core_field_suggestions'] ?? null);
        $diff = collect($pending['core_field_suggestions'])->firstWhere('field', 'full_name');
        $this->assertNotNull($diff);
        $this->assertStringContainsString('Profile Original Name', (string) ($diff['old_value'] ?? ''));
        $this->assertSame('Intake Parsed Name', $diff['new_value'] ?? null);

        $intake->refresh();
        $this->assertTrue((bool) $intake->intake_locked);
        $this->assertSame('applied', $intake->intake_status);
    }

    public function test_intake_apply_ignores_legacy_religion_caste_text_keys_after_master_id_resolution(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => 'Community Candidate',
        ]);
        $this->attachResidence($profile);

        $religionId = (int) DB::table('master_religions')->where('key', 'hindu')->value('id');
        $casteId = (int) DB::table('master_castes')->where('key', 'maratha')->value('id');
        $subCasteId = (int) DB::table('master_sub_castes')->where('key', '96_kuli')->value('id');

        $snapshot = [
            'snapshot_schema_version' => 1,
            'core' => [
                'full_name' => 'Community Candidate',
                'religion' => 'Hindu',
                'caste' => 'Maratha',
                'sub_caste' => '96 Kuli',
                'religion_id' => $religionId,
                'caste_id' => $casteId,
                'sub_caste_id' => $subCasteId,
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

        $result = app(MutationService::class)->applyApprovedIntake($intake->id);

        $this->assertTrue($result['mutation_success'] ?? false, 'mutation should succeed');
        $profile->refresh();
        $this->assertSame($religionId, (int) $profile->religion_id);
        $this->assertSame($casteId, (int) $profile->caste_id);
        $this->assertSame($subCasteId, (int) $profile->sub_caste_id);
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
