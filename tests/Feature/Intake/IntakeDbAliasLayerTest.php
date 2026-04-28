<?php

namespace Tests\Feature\Intake;

use App\Models\Religion;
use App\Models\ReligionAlias;
use App\Services\EducationService;
use App\Services\OccupationService;
use App\Services\Parsing\IntakeControlledFieldNormalizer;
use App\Support\MasterData\MasterDataAliasNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IntakeDbAliasLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_core_resolves_religion_via_religion_aliases_table(): void
    {
        $rel = Religion::query()->create([
            'key' => 'zzz_intake_alias_'.uniqid(),
            'label' => 'ZZZ Religion',
            'is_active' => true,
        ]);

        $aliasText = 'OCR Religion Blob '.uniqid();
        ReligionAlias::query()->create([
            'religion_id' => $rel->id,
            'alias' => $aliasText,
            'alias_type' => 'ocr',
            'normalized_alias' => MasterDataAliasNormalizer::normalizeForStoredAlias($aliasText),
        ]);

        $core = app(IntakeControlledFieldNormalizer::class)->normalizeCore([
            'religion' => $aliasText,
        ]);

        $this->assertSame($rel->id, (int) ($core['religion_id'] ?? 0));
    }

    public function test_find_degree_match_honors_education_degree_aliases(): void
    {
        if (! Schema::hasTable('education_degree_aliases') || ! Schema::hasTable('education_degrees')) {
            $this->markTestSkipped('education_degree_aliases not migrated');
        }

        $catId = DB::table('education_categories')->insertGetId([
            'name' => 'Test Cat',
            'slug' => 'test-cat-'.uniqid(),
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $degId = DB::table('education_degrees')->insertGetId([
            'category_id' => $catId,
            'code' => 'XYZ'.substr(uniqid(), 0, 6),
            'title' => 'Rare Degree Title',
            'full_form' => null,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $aliasRaw = 'biodata says weird degree '.uniqid();
        DB::table('education_degree_aliases')->insert([
            'education_degree_id' => $degId,
            'alias' => $aliasRaw,
            'normalized_alias' => MasterDataAliasNormalizer::normalizeForStoredAlias($aliasRaw),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $match = app(EducationService::class)->findDegreeMatch($aliasRaw);
        $this->assertNotNull($match);
        $this->assertSame($degId, $match->id);
    }

    public function test_find_occupation_master_for_intake_uses_alias_table(): void
    {
        if (! Schema::hasTable('occupation_master_aliases') || ! Schema::hasTable('occupation_master')) {
            $this->markTestSkipped('occupation_master_aliases not migrated');
        }

        $catId = DB::table('occupation_categories')->insertGetId([
            'name' => 'Test Occ Cat',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $occId = DB::table('occupation_master')->insertGetId([
            'name' => 'Canonical Occ Name',
            'normalized_name' => 'canonicaloccname',
            'category_id' => $catId,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $aliasRaw = 'OCR occ title '.uniqid();
        DB::table('occupation_master_aliases')->insert([
            'occupation_master_id' => $occId,
            'alias' => $aliasRaw,
            'normalized_alias' => MasterDataAliasNormalizer::normalizeForStoredAlias($aliasRaw),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $occ = app(OccupationService::class)->findOccupationMasterForIntake($aliasRaw);
        $this->assertNotNull($occ);
        $this->assertSame($occId, $occ->id);
    }

    public function test_normalize_career_rows_sets_occupation_master_id_from_alias(): void
    {
        if (! Schema::hasTable('occupation_master_aliases') || ! Schema::hasTable('occupation_master')) {
            $this->markTestSkipped('occupation_master_aliases not migrated');
        }

        $catId = DB::table('occupation_categories')->insertGetId([
            'name' => 'Test Occ Cat B',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $occId = DB::table('occupation_master')->insertGetId([
            'name' => 'Canonical B',
            'normalized_name' => 'canonicalb',
            'category_id' => $catId,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $aliasRaw = 'job ocr label '.uniqid();
        DB::table('occupation_master_aliases')->insert([
            'occupation_master_id' => $occId,
            'alias' => $aliasRaw,
            'normalized_alias' => MasterDataAliasNormalizer::normalizeForStoredAlias($aliasRaw),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = app(IntakeControlledFieldNormalizer::class)->normalizeCareerRows([
            ['occupation_title' => $aliasRaw],
        ]);

        $this->assertSame($occId, (int) ($rows[0]['occupation_master_id'] ?? 0));
    }
}
