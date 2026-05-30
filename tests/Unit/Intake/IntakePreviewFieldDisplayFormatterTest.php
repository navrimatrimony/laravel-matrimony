<?php

namespace Tests\Unit\Intake;

use App\Models\MasterBloodGroup;
use App\Services\Intake\IntakePreviewFieldDisplayFormatter;
use App\Support\HeightDisplay;
use Tests\TestCase;

class IntakePreviewFieldDisplayFormatterTest extends TestCase
{
    public function test_height_cm_formats_as_feet_inches_not_raw_decimal(): void
    {
        $formatter = app(IntakePreviewFieldDisplayFormatter::class);
        $display = $formatter->format('height_cm', 175.26);

        $this->assertStringContainsString("'", $display);
        $this->assertStringNotContainsString('175.26', $display);
        $this->assertSame(HeightDisplay::formatCm(175), $display);
    }

    public function test_blood_group_id_formats_as_label_not_numeric_id(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('master_blood_groups')) {
            $this->markTestSkipped('master_blood_groups table not available');
        }

        $row = MasterBloodGroup::query()->where('is_active', true)->first();
        if (! $row) {
            $this->markTestSkipped('No blood group seed data');
        }

        $formatter = app(IntakePreviewFieldDisplayFormatter::class);
        $display = $formatter->format('blood_group_id', (int) $row->id);

        $this->assertNotSame((string) $row->id, $display);
        $this->assertNotEmpty($display);
    }
}
