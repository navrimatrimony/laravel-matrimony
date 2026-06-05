<?php

namespace Tests\Unit\Intake;

use App\Models\Caste;
use App\Models\MasterGender;
use App\Models\MasterMaritalStatus;
use App\Models\Religion;
use App\Models\SubCaste;
use App\Services\Intake\IntakePipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeParsedSnapshotCanonicalIdResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_finalize_parsed_snapshot_resolves_core_ids_from_labels_before_save(): void
    {
        $religion = Religion::create([
            'key' => 'hindu',
            'label' => 'Hindu',
            'label_en' => 'Hindu',
            'label_mr' => 'हिंदू',
            'is_active' => true,
        ]);
        $duplicateReligion = Religion::create([
            'key' => 'rel-sc-hindu-dup',
            'label' => 'Hindu',
            'label_en' => 'Hindu',
            'label_mr' => 'हिंदू',
            'is_active' => true,
        ]);

        $caste = Caste::create([
            'religion_id' => $religion->id,
            'key' => 'maratha',
            'label' => 'Maratha',
            'label_en' => 'Maratha',
            'label_mr' => 'मराठा',
            'is_active' => true,
        ]);
        $duplicateCaste = Caste::create([
            'religion_id' => $duplicateReligion->id,
            'key' => 'cas-sc-maratha-dup',
            'label' => 'Maratha',
            'label_en' => 'Maratha',
            'label_mr' => 'मराठा',
            'is_active' => true,
        ]);

        $subCaste = SubCaste::create([
            'caste_id' => $caste->id,
            'key' => '96_kuli',
            'label' => '96 Kuli',
            'label_en' => '96 Kuli',
            'label_mr' => '96 कुळी',
            'is_active' => true,
            'status' => 'approved',
        ]);
        $duplicateSubCaste = SubCaste::create([
            'caste_id' => $duplicateCaste->id,
            'key' => 'kuli-sc-96-kuli-dup',
            'label' => '96 Kuli',
            'label_en' => '96 Kuli',
            'label_mr' => '96 कुळी',
            'is_active' => true,
            'status' => 'approved',
        ]);

        $gender = MasterGender::query()->firstOrCreate([
            'key' => 'male',
        ], [
            'label' => 'Male',
            'is_active' => true,
        ]);
        $maritalStatus = MasterMaritalStatus::query()->firstOrCreate([
            'key' => 'never_married',
        ], [
            'label' => 'Never Married',
            'is_active' => true,
        ]);

        $religionCount = Religion::count();
        $casteCount = Caste::count();
        $subCasteCount = SubCaste::count();

        $parsed = [
            'core' => [
                'religion' => 'हिंदु',
                'caste' => 'मराठा',
                'sub_caste' => '९६ कुळी',
                'gender' => 'male',
                'marital_status' => 'unmarried',
            ],
        ];

        $final = app(IntakePipelineService::class)->finalizeParsedSnapshotForStorage($parsed);
        $core = is_array($final['core'] ?? null) ? $final['core'] : [];

        $this->assertSame($religion->id, $core['religion_id'] ?? null);
        $this->assertSame($caste->id, $core['caste_id'] ?? null);
        $this->assertSame($subCaste->id, $core['sub_caste_id'] ?? null);
        $this->assertSame($gender->id, $core['gender_id'] ?? null);
        $this->assertSame($maritalStatus->id, $core['marital_status_id'] ?? null);

        $this->assertSame($religionCount, Religion::count());
        $this->assertSame($casteCount, Caste::count());
        $this->assertSame($subCasteCount, SubCaste::count());

        $this->assertSame($duplicateReligion->id, Religion::where('key', 'rel-sc-hindu-dup')->value('id'));
        $this->assertSame($duplicateCaste->id, Caste::where('key', 'cas-sc-maratha-dup')->value('id'));
        $this->assertSame($duplicateSubCaste->id, SubCaste::where('key', 'kuli-sc-96-kuli-dup')->value('id'));
    }

    public function test_finalize_parsed_snapshot_backfills_religion_from_resolved_caste_and_matches_kuli_spelling_variants(): void
    {
        $religion = Religion::create([
            'key' => 'hindu',
            'label' => 'Hindu',
            'label_en' => 'Hindu',
            'label_mr' => 'हिंदू',
            'is_active' => true,
        ]);

        $caste = Caste::create([
            'religion_id' => $religion->id,
            'key' => 'maratha',
            'label' => 'Maratha',
            'label_en' => 'Maratha',
            'label_mr' => 'मराठा',
            'is_active' => true,
        ]);

        $subCaste = SubCaste::create([
            'caste_id' => $caste->id,
            'key' => '96-kuli',
            'label' => '96 Kuli',
            'label_en' => '96 Kuli',
            'label_mr' => '९६ कुली',
            'is_active' => true,
            'status' => 'approved',
        ]);

        $parsed = [
            'core' => [
                'religion' => null,
                'caste' => 'मराठा',
                'sub_caste' => '९६ कुळी',
            ],
        ];

        $final = app(IntakePipelineService::class)->finalizeParsedSnapshotForStorage($parsed);
        $core = is_array($final['core'] ?? null) ? $final['core'] : [];

        $this->assertSame($religion->id, $core['religion_id'] ?? null);
        $this->assertSame($caste->id, $core['caste_id'] ?? null);
        $this->assertSame($subCaste->id, $core['sub_caste_id'] ?? null);
        $this->assertContains($core['religion'] ?? null, ['Hindu', 'हिंदू']);
    }

    public function test_finalize_parsed_snapshot_normalizes_all_saved_digit_characters_to_ascii(): void
    {
        $parsed = [
            'core' => [
                'date_of_birth' => '१४ नोव्हेंबर १९९४',
                'sub_caste' => '९६ कुळी',
                'address_line' => 'फ्लॅट नं.१०३',
            ],
            'birth_place' => [
                'raw' => 'पुणे ४११०३०',
                'address_line' => 'पुणे ४११०३०',
            ],
            'addresses' => [
                [
                    'type' => 'current',
                    'address_line' => '१. ईशा बेला विस्टा, फ्लॅट नं.१०३',
                    'raw' => '१. ईशा बेला विस्टा, फ्लॅट नं.१०३',
                ],
            ],
        ];

        $final = app(IntakePipelineService::class)->finalizeParsedSnapshotForStorage($parsed);
        $core = is_array($final['core'] ?? null) ? $final['core'] : [];
        $birthPlace = is_array($final['birth_place'] ?? null) ? $final['birth_place'] : [];
        $addresses = is_array($final['addresses'] ?? null) ? $final['addresses'] : [];

        $this->assertSame('14 नोव्हेंबर 1994', $core['date_of_birth'] ?? null);
        $this->assertSame('96 कुळी', $core['sub_caste'] ?? null);
        $this->assertSame('फ्लॅट नं.103', $core['address_line'] ?? null);
        $this->assertSame('पुणे 411030', $birthPlace['raw'] ?? null);
        $this->assertSame('पुणे 411030', $birthPlace['address_line'] ?? null);
        $this->assertSame('1. ईशा बेला विस्टा, फ्लॅट नं.103', $addresses[0]['address_line'] ?? null);
        $this->assertSame('1. ईशा बेला विस्टा, फ्लॅट नं.103', $addresses[0]['raw'] ?? null);
    }
}
