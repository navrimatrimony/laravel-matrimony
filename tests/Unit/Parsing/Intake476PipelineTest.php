<?php

namespace Tests\Unit\Parsing;

use App\Services\Ocr\OcrNormalize;
use App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder;
use App\Services\Parsing\IntakeNormalizedDraftToParsedJsonMapper;
use App\Services\Parsing\MarathiSplitLabelValueRejoiner;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Intake476PipelineTest extends TestCase
{
    public function test_ashish_style_split_blocks_are_rejoined_to_inline_label_value_lines(): void
    {
        $raw = <<<'TXT'
## परिचय पञ्जक
मुलाचे नांव
जन्म तारीख
जन्म वेळ
शिक्षण
व्यवसाय
जात
: कु. आशिष बापूराव गायकवाड मो.८४६०७७७१४६
: ३१.०७.१९९७
: गुरूवार,पहाटे ०१ वा ५० मि.
: B.Com
: साईनाथ गोल्ड अँड सिल्व्हर टेस्टिंग व बुलियन,नवसारी,गुजरात
: ९६ कुळी हिंदू-मराठा
TXT;

        $rejoined = MarathiSplitLabelValueRejoiner::rejoin($raw);

        $this->assertNotSame($raw, $rejoined);
        $this->assertStringContainsString('मुलाचे नांव :- कु. आशिष बापूराव गायकवाड', $rejoined);
        $this->assertStringContainsString('शिक्षण :- B.Com', $rejoined);
        $this->assertStringContainsString('व्यवसाय :- साईनाथ गोल्ड', $rejoined);
        $this->assertStringContainsString('जात :- ९६ कुळी हिंदू-मराठा', $rejoined);
    }

    public function test_intake_476_parse_input_builds_normalized_draft_without_database_writes(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $text = $this->intake476ParseInputText();
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($text);

        $core = $draft['normalized']['core'] ?? [];
        $this->assertStringContainsString('आशिष बापूराव गायकवाड', (string) ($core['full_name'] ?? ''));
        $this->assertSame('B.Com', $core['highest_education'] ?? null);
        $this->assertSame('नोकरी', $core['occupation_title'] ?? null);
        $this->assertStringContainsString('साईनाथ', (string) ($core['company_name'] ?? ''));
        $this->assertSame('नवसारी,गुजरात', $core['work_location_text'] ?? null);
        $this->assertSame('96 कुळी', OcrNormalize::normalizeDigits((string) ($core['sub_caste'] ?? '')));
        $this->assertSame('मराठा', $core['caste'] ?? null);
        $this->assertStringContainsString('बापूराव', (string) ($core['father_name'] ?? ''));
        $this->assertStringContainsString('संगीता', (string) ($core['mother_name'] ?? ''));
        $this->assertStringContainsString('राजेश उत्तम थोरात', (string) ($core['other_relatives_text'] ?? ''));

        $siblings = $draft['normalized']['siblings'] ?? [];
        $this->assertCount(2, $siblings);
        $sisterNames = array_map(
            static fn (array $row): string => (string) ($row['name'] ?? ''),
            array_values(array_filter($siblings, 'is_array'))
        );
        $this->assertTrue(
            collect($sisterNames)->contains(fn (string $name): bool => str_contains($name, 'अंकिता'))
        );
        $this->assertTrue(
            collect($sisterNames)->contains(fn (string $name): bool => str_contains($name, 'दिपाली'))
        );

        $relatives = $draft['normalized']['relatives'] ?? [];
        $this->assertGreaterThanOrEqual(1, count($relatives));
        $relativeBlob = json_encode($relatives, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('मुरलीधर', (string) $relativeBlob);

        $horoscope = $draft['normalized']['horoscope'] ?? [];
        $this->assertSame('मिथुन', $horoscope['rashi'] ?? null);
        $this->assertSame('मृग', $horoscope['nakshatra'] ?? null);
        $this->assertSame('कीरण', $horoscope['navras_name'] ?? null);

        $this->assertSame([], $queries);
    }

    public function test_intake_476_mapper_carries_supported_draft_fields_into_parsed_json(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $text = $this->intake476ParseInputText();
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($text);
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map($draft);

        $this->assertDraftCoreFieldsPresentInParsedJson($draft, $parsed);
        $this->assertSame(count($draft['normalized']['siblings'] ?? []), count($parsed['siblings'] ?? []));
        $this->assertSame(count($draft['normalized']['relatives'] ?? []), count($parsed['relatives'] ?? []));

        $core = $parsed['core'] ?? [];
        $this->assertSame('8460777146', OcrNormalize::normalizePhone((string) ($core['primary_contact_number'] ?? '')));

        $horoscope = $parsed['horoscope'][0] ?? [];
        $this->assertSame('मिथुन', $horoscope['rashi'] ?? null);
        $this->assertSame('मृग', $horoscope['nakshatra'] ?? null);
        $this->assertSame('कीरण', $horoscope['navras_name'] ?? null);

        $this->assertStringContainsString(
            'राजेश उत्तम थोरात',
            (string) ($core['other_relatives_text'] ?? '')
        );

        $this->assertSame([], $queries);
    }

    public function test_intake_476_bullet_markdown_layout_pipeline_works_even_when_rejoin_is_no_op(): void
    {
        $text = $this->intake476ParseInputText();
        $rejoined = MarathiSplitLabelValueRejoiner::rejoin($text);

        $this->assertSame($text, $rejoined, 'Current #476 snapshot uses markdown bullets; builder still maps it.');

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($text);
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map($draft);

        $this->assertDraftCoreFieldsPresentInParsedJson($draft, $parsed);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $parsed
     */
    private function assertDraftCoreFieldsPresentInParsedJson(array $draft, array $parsed): void
    {
        $draftCore = is_array($draft['normalized']['core'] ?? null) ? $draft['normalized']['core'] : [];
        $parsedCore = is_array($parsed['core'] ?? null) ? $parsed['core'] : [];

        foreach ([
            'full_name',
            'highest_education',
            'occupation_title',
            'company_name',
            'work_location_text',
            'religion',
            'caste',
            'sub_caste',
            'father_name',
            'mother_name',
            'other_relatives_text',
            'primary_contact_number',
        ] as $field) {
            $draftValue = trim((string) ($draftCore[$field] ?? ''));
            if ($draftValue === '') {
                continue;
            }

            $parsedValue = trim((string) ($parsedCore[$field] ?? ''));
            $this->assertNotSame(
                '',
                $parsedValue,
                "Expected parsed_json core.{$field} to be populated because normalized draft has a value."
            );
        }

        $draftRelativesText = trim((string) ($draftCore['other_relatives_text'] ?? ''));
        if ($draftRelativesText !== '') {
            $this->assertSame($draftRelativesText, (string) ($parsedCore['other_relatives_text'] ?? ''));
        }
    }

    private function intake476ParseInputText(): string
    {
        $path = base_path('tests/fixtures/intake-476-parse-input.txt');
        $this->assertFileExists($path);

        $text = file_get_contents($path);
        $this->assertIsString($text);
        $this->assertNotSame('', trim($text));

        return $text;
    }
}
