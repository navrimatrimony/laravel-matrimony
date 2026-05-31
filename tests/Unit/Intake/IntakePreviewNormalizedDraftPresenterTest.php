<?php

namespace Tests\Unit\Intake;

use App\Services\Intake\IntakePreviewNormalizedDraftPresenter;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntakePreviewNormalizedDraftPresenterTest extends TestCase
{
    private function yuvrajText(): string
    {
        return <<<'TXT'
*प्रतिमा: decorative logo*
:■:
वैयक्तिक माहिती
नाव : कु. युवराज नामदेव घाटेगस्ती.
जात : हिंदू मराठा {96 कुळी}
आईचे नाव : सौ. सुनंदा नामदेव घाटेगस्ती. { गृहिणी }
मोबाईल नं : 73509 53384/ 96733 50078
TXT;
    }

    private function swapnilText(): string
    {
        return <<<'TXT'
बायोडाटा
मुलाचे नांव :- चि. स्वप्नील सतिश शिंदे
भाऊ :- नाही
बहीण :- एक ( अविवाहित )
आत्या :- श्री. भाऊसो कृष्णाजी मोरे रा. इस्लामपूर
घरचा पत्ता :- मु. पो. समडोळी , ता. मिरज , जि. सांगली.
पाहुणे :- तातुगडे - देशमुख
मोबाइल नंबर :- 9860956022 / 8668270153
TXT;
    }

    private function maheshText(): string
    {
        return <<<'TXT'
कास्ट :- ९६ कुळी मराठा
पित्याचे नाव :-मोहनराव गणपतराव जगताप
प्रोपर्टी :- 1BHK Flat (1) 2 BHK Flat (2)
गावचा पत्ता :- चंद्रेश बिल्डिंग, ठाणे

## महेशकुमार मोहन जगताप

मोबाईल नंबर :- महेश मोहन जगताप (९८७०८७९७२७)
:- मोहन जगताप (९१३७७९३३७१)
TXT;
    }

    public function test_yuvraj_text_returns_available_personal_and_contacts_sections(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($this->yuvrajText(), true);

        $this->assertTrue($out['available']);
        $this->assertNull($out['skipped_reason']);
        $this->assertNull($out['build_error']);
        $this->assertNotEmpty($out['sections']['personal']);
        $this->assertNotEmpty($out['sections']['contacts']);
        $this->assertNotEmpty($out['sections']['review_needed']);
        $this->assertSame([], $queries);
    }

    public function test_swapnil_text_returns_available_family_and_contacts(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($this->swapnilText(), true);

        $this->assertTrue($out['available']);
        $personalBlob = $this->sectionBlob($out['sections']['personal']);
        $this->assertStringContainsString('male', $personalBlob);
        $familyBlob = $this->sectionBlob($out['sections']['family']);
        $this->assertStringContainsString('0', $familyBlob);
        $this->assertStringContainsString('1', $familyBlob);
        $contactsBlob = $this->sectionBlob($out['sections']['contacts']);
        $this->assertStringContainsString('9860956022', $contactsBlob);
    }

    public function test_mahesh_text_returns_available_personal_family_contacts(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($this->maheshText(), true);

        $this->assertTrue($out['available']);
        $personalBlob = $this->sectionBlob($out['sections']['personal']);
        $this->assertStringContainsString('महेशकुमार मोहन जगताप', $personalBlob);
        $familyBlob = $this->sectionBlob($out['sections']['family']);
        $this->assertStringContainsString('मोहनराव गणपतराव जगताप', $familyBlob);
        $contactsBlob = $this->sectionBlob($out['sections']['contacts']);
        $this->assertStringContainsString('9870879727', $contactsBlob);
    }

    public function test_mahesh_full_name_row_marked_needs_review_for_heading_fallback(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($this->maheshText(), true);

        $fullNameRow = $this->findRowByField($out['sections']['personal'], 'core.full_name');
        $this->assertNotNull($fullNameRow);
        $this->assertTrue($fullNameRow['needs_review']);
        $this->assertSame('candidate_name_from_heading_fallback', $fullNameRow['review_reason']);
        $this->assertSame(
            __('intake.normalized_draft_full_name_fallback_hint'),
            $fullNameRow['review_hint']
        );
        $this->assertArrayHasKey('core.full_name', $out['review_flags_by_field']);
    }

    public function test_display_rows_use_clean_unicode_separators(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($this->swapnilText(), true);

        $blob = $this->sectionBlob($out['sections']['family'])
            .' '.$this->sectionBlob($out['sections']['relatives'])
            .' '.$this->sectionBlob($out['sections']['review_needed']);

        $this->assertStringNotContainsString('Â·', $blob);
        $this->assertStringNotContainsString('â€”', $blob);
        $this->assertStringContainsString(' · ', $blob);
    }

    public function test_property_boolean_rows_display_marathi_yes(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'HTML'
<table>
<tr><td>इतर प्रॉपर्टी</td><td>:-</td><td>स्वतःचे घर, फ्लॅट, शेती 01 एकर</td></tr>
</table>
HTML, true);
        $propertyBlob = $this->sectionBlob($out['sections']['property']);

        $this->assertStringContainsString('होय', $propertyBlob);
        $this->assertStringNotContainsString('yes', $propertyBlob);
        $this->assertStringNotContainsString('true', $propertyBlob);
        $this->assertStringNotContainsString('false', $propertyBlob);
    }

    public function test_unavailable_when_not_biodata_text(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present('AI unavailable message', false);

        $this->assertFalse($out['available']);
        $this->assertSame('not_biodata_text', $out['skipped_reason']);
        $this->assertSame([], $out['sections']['personal']);
    }

    public function test_unavailable_when_text_empty(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present('   ', true);

        $this->assertFalse($out['available']);
        $this->assertSame('empty_text', $out['skipped_reason']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function findRowByField(array $rows, string $field): ?array
    {
        foreach ($rows as $row) {
            if (($row['field'] ?? null) === $field) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  list<array{label: string, value: string}>  $rows
     */
    private function sectionBlob(array $rows): string
    {
        return implode(' ', array_map(
            static fn (array $row): string => ($row['label'] ?? '').' '.($row['value'] ?? ''),
            $rows
        ));
    }
}
