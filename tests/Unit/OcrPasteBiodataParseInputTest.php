<?php

namespace Tests\Unit;

use App\Models\BiodataIntake;
use App\Models\User;
use App\Services\OcrService;
use App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OcrPasteBiodataParseInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_pasted_structured_biodata_skips_domain_enhancement_so_name_is_preserved(): void
    {
        $user = User::factory()->create();
        $text = <<<'TXT'
बायोडाटा
मुलीचे नाव :- कु. परीक्षण इंटेक वधू शिंदे
जन्म दिनांक :- 15/06/1998
जन्म स्थळ :- सांगली
TXT;

        $intake = BiodataIntake::query()->create([
            'uploaded_by' => $user->id,
            'raw_ocr_text' => $text,
            'file_path' => null,
            'intake_status' => 'uploaded',
            'parse_status' => 'pending',
        ]);

        $resolved = app(OcrService::class)->resolveParseInputText($intake);
        $name = app(IntakeNormalizedBiodataDraftBuilder::class)
            ->build($resolved['text'])['normalized']['core']['full_name'] ?? null;

        $this->assertSame('कु. परीक्षण इंटेक वधू शिंदे', $name);
        $this->assertSame('structured_paste_biodata', $resolved['ocr_debug']['domain_intelligence_skipped'] ?? null);
    }
}
