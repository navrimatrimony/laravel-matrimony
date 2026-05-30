<?php

namespace Tests\Unit\Intake;

use App\Models\Caste;
use App\Models\Religion;
use App\Services\Intake\IntakePreviewMasterFieldEquivalence;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IntakePreviewMasterFieldEquivalenceTest extends TestCase
{
    public function test_religion_id_matches_marathi_label_via_label_mr(): void
    {
        if (! Schema::hasTable('religions')) {
            $this->markTestSkipped('religions table not available');
        }

        $key = 'equiv-hindu-'.uniqid('', true);
        $religion = Religion::create([
            'key' => $key,
            'label' => 'Hindu',
            'label_en' => 'Hindu',
            'label_mr' => 'हिंदू',
            'is_active' => true,
        ]);

        try {
            $eq = app(IntakePreviewMasterFieldEquivalence::class);
            $this->assertTrue($eq->valuesEqual(
                'religion_id',
                $religion->id,
                'हिंदू',
                ['religion_id' => $religion->id],
                ['religion' => 'हिंदू'],
                null
            ));
        } finally {
            $religion->delete();
        }
    }

    public function test_caste_id_matches_marathi_label_when_religion_context_set(): void
    {
        if (! Schema::hasTable('religions') || ! Schema::hasTable('castes')) {
            $this->markTestSkipped('religion/caste tables not available');
        }

        $relKey = 'equiv-rel-'.uniqid('', true);
        $casKey = 'equiv-cas-'.uniqid('', true);
        $religion = Religion::create([
            'key' => $relKey,
            'label' => 'Hindu',
            'label_en' => 'Hindu',
            'label_mr' => 'हिंदू',
            'is_active' => true,
        ]);
        $caste = Caste::create([
            'religion_id' => $religion->id,
            'key' => $casKey,
            'label' => 'Maratha',
            'label_en' => 'Maratha',
            'label_mr' => 'मराठा',
            'is_active' => true,
        ]);

        try {
            $eq = app(IntakePreviewMasterFieldEquivalence::class);
            $this->assertTrue($eq->valuesEqual(
                'caste_id',
                $caste->id,
                'मराठा',
                ['religion_id' => $religion->id, 'caste_id' => $caste->id],
                ['religion' => 'हिंदू', 'caste' => 'मराठा', 'religion_id' => $religion->id],
                null
            ));
        } finally {
            $caste->delete();
            $religion->delete();
        }
    }
}
