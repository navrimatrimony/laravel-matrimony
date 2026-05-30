<?php

namespace Tests\Unit\Intake;

use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Services\Intake\IntakePreviewExistingProfileOverlay;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IntakePreviewReligionLocaleEquivalenceTest extends TestCase
{
    public function test_no_religion_suggestion_when_en_form_label_matches_mr_intake_same_master_id(): void
    {
        if (! Schema::hasTable('master_religions')) {
            $this->markTestSkipped('master_religions table not available');
        }

        app()->setLocale('en');

        $key = 'overlay-hindu-'.uniqid('', true);
        $religion = Religion::create([
            'key' => $key,
            'label' => 'Hindu',
            'label_en' => 'Hindu',
            'label_mr' => 'हिंदू',
            'is_active' => true,
        ]);

        try {
            $profile = new MatrimonyProfile;
            $profile->religion_id = $religion->id;
            $profile->setRelation('religion', $religion);

            $coreData = [
                'religion_id' => $religion->id,
                'religion' => 'Hindu',
            ];
            $suggestionMap = [];
            $intakeParsed = ['core' => [
                'religion' => 'हिंदू',
                'religion_id' => $religion->id,
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

            $religionSuggestions = array_filter(
                $result['field_suggestions'],
                fn ($r) => ($r['key'] ?? '') === 'religion'
            );
            $this->assertSame([], array_values($religionSuggestions));
        } finally {
            $religion->delete();
            app()->setLocale(config('app.locale'));
        }
    }
}
