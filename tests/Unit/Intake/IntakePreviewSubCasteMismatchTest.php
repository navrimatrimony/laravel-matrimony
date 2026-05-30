<?php

namespace Tests\Unit\Intake;

use App\Models\Caste;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\SubCaste;
use App\Services\Intake\IntakePreviewExistingProfileOverlay;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IntakePreviewSubCasteMismatchTest extends TestCase
{
    public function test_surfaces_sub_caste_biodata_suggestion_when_profile_label_differs(): void
    {
        if (! Schema::hasTable('master_religions') || ! Schema::hasTable('master_castes') || ! Schema::hasTable('master_sub_castes')) {
            $this->markTestSkipped('master religion/caste/sub_caste tables not available');
        }

        $uid = uniqid('sc-', true);
        $religion = Religion::create([
            'key' => 'rel-'.$uid,
            'label' => 'Hindu',
            'label_en' => 'Hindu',
            'label_mr' => 'हिंदू',
            'is_active' => true,
        ]);
        $caste = Caste::create([
            'religion_id' => $religion->id,
            'key' => 'cas-'.$uid,
            'label' => 'Maratha',
            'label_en' => 'Maratha',
            'label_mr' => 'मराठा',
            'is_active' => true,
        ]);
        $deshmukh = SubCaste::create([
            'caste_id' => $caste->id,
            'key' => 'desh-'.$uid,
            'label' => 'Deshmukh',
            'label_en' => 'Deshmukh',
            'label_mr' => 'देशमुख',
            'is_active' => true,
        ]);
        $kuli = SubCaste::create([
            'caste_id' => $caste->id,
            'key' => 'kuli-'.$uid,
            'label' => '96 Kuli',
            'label_en' => '96 Kuli',
            'label_mr' => '96 कुळी',
            'is_active' => true,
        ]);

        try {
            $profile = new MatrimonyProfile;
            $profile->forceFill([
                'religion_id' => $religion->id,
                'caste_id' => $caste->id,
                'sub_caste_id' => $deshmukh->id,
            ]);
            $profile->setRelation('religion', $religion);
            $profile->setRelation('caste', $caste);
            $profile->setRelation('subCaste', $deshmukh);

            $eq = app(\App\Services\Intake\IntakePreviewMasterFieldEquivalence::class);
            $this->assertFalse($eq->valuesEqual(
                'sub_caste_id',
                $deshmukh->id,
                '96 कुळी',
                ['sub_caste_id' => $deshmukh->id, 'caste_id' => $caste->id],
                ['sub_caste' => '96 कुळी', 'caste_id' => $caste->id],
                $profile
            ));

            $coreData = [
                'religion_id' => $religion->id,
                'caste_id' => $caste->id,
                'sub_caste_id' => $deshmukh->id,
            ];
            $suggestionMap = [];
            $intakeParsed = ['core' => [
                'religion' => 'हिंदू',
                'caste' => 'मराठा 96 कुळी',
                'sub_caste' => '96 कुळी',
            ]];
            $snapshot = [];
            $sections = ['core' => ['data' => &$coreData]];

            $result = app(IntakePreviewExistingProfileOverlay::class)->apply(
                $profile,
                $coreData,
                $suggestionMap,
                $intakeParsed,
                $snapshot,
                $sections
            );

            $subRows = array_values(array_filter(
                $result['field_suggestions'],
                fn ($r) => ($r['key'] ?? '') === 'sub_caste'
            ));
            $this->assertNotEmpty(
                $subRows,
                'Expected sub_caste biodata row. protected='.json_encode($result['protected_core_keys'])
                .' map='.json_encode($suggestionMap['sub_caste'] ?? null)
            );
            $this->assertStringContainsString('96', (string) ($subRows[0]['intake_display'] ?? ''));
            $this->assertSame($deshmukh->id, (int) ($coreData['sub_caste_id'] ?? 0));
        } finally {
            $kuli->delete();
            $deshmukh->delete();
            $caste->delete();
            $religion->delete();
        }
    }
}
