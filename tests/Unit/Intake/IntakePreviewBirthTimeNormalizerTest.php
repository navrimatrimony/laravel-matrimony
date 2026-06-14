<?php

namespace Tests\Unit\Intake;

use App\Http\Controllers\IntakeController;
use ReflectionMethod;
use Tests\TestCase;

class IntakePreviewBirthTimeNormalizerTest extends TestCase
{
    public function test_explicit_am_beats_marathi_night_word_for_preview_time_picker(): void
    {
        $this->assertSame(
            '03:45',
            $this->normalize('वार :- 3.45 A.M रात्री सोमवार उजडता मंगळवर')
        );
    }

    public function test_marathi_period_words_still_fill_time_picker_when_no_explicit_am_pm_exists(): void
    {
        $this->assertSame('13:20', $this->normalize('दुपारी १.२० मि.'));
    }

    private function normalize(string $value): string
    {
        $method = new ReflectionMethod(IntakeController::class, 'normalizePreviewBirthTimeForForm');
        $method->setAccessible(true);

        return (string) $method->invoke(new IntakeController, $value);
    }
}
