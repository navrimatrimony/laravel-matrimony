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

    public function test_sections_follow_wizard_order_and_old_sections_are_absent(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($this->yuvrajText(), true);

        $this->assertSame([
            'review_needed',
            'basic-info',
            'physical',
            'education-career',
            'family-details',
            'siblings',
            'relatives',
            'alliance',
            'property',
            'horoscope',
            'about-me',
            'about-preferences',
            'photo',
        ], array_keys($out['sections']));

        foreach (['personal', 'family', 'contacts', 'addresses'] as $oldSection) {
            $this->assertArrayNotHasKey($oldSection, $out['sections']);
        }
    }

    public function test_yuvraj_text_returns_available_basic_info_and_db_free_sections(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($this->yuvrajText(), true);

        $this->assertTrue($out['available']);
        $this->assertNull($out['skipped_reason']);
        $this->assertNull($out['build_error']);
        $this->assertNotEmpty($out['sections']['basic-info']);
        $this->assertNotEmpty($out['sections']['review_needed']);
        $this->assertSame([], $queries);

        $basicBlob = $this->sectionBlob($out['sections']['basic-info']);
        $this->assertStringContainsString('7350953384', $basicBlob);
        $this->assertStringContainsString('9673350078', $basicBlob);
    }

    public function test_swapnil_text_places_gender_contacts_family_counts_and_relatives_in_wizard_sections(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($this->swapnilText(), true);

        $this->assertTrue($out['available']);
        $basicBlob = $this->sectionBlob($out['sections']['basic-info']);
        $this->assertStringContainsString('male', $basicBlob);
        $this->assertStringContainsString('9860956022', $basicBlob);

        $familyBlob = $this->sectionBlob($out['sections']['family-details']);
        $this->assertStringContainsString('0', $familyBlob);
        $this->assertStringContainsString('1', $familyBlob);

        $relativesBlob = $this->sectionBlob($out['sections']['relatives']);
        $this->assertStringContainsString('इस्लामपूर', $relativesBlob);
        $this->assertSame('', $this->sectionBlob($out['sections']['siblings']));
    }

    public function test_mahesh_text_returns_available_basic_family_and_contact_rows(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($this->maheshText(), true);

        $this->assertTrue($out['available']);
        $basicBlob = $this->sectionBlob($out['sections']['basic-info']);
        $this->assertStringContainsString('महेशकुमार मोहन जगताप', $basicBlob);
        $this->assertStringContainsString('9870879727', $basicBlob);

        $familyBlob = $this->sectionBlob($out['sections']['family-details']);
        $this->assertStringContainsString('मोहनराव गणपतराव जगताप', $familyBlob);
    }

    public function test_mahesh_full_name_row_marked_needs_review_for_heading_fallback(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($this->maheshText(), true);

        $fullNameRow = $this->findRowByField($out['sections']['basic-info'], 'core.full_name');
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
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलाचे नांव :- चि. रोहित शिंदे
भाऊ :- चि. संकेत शिंदे ( अविवाहित ) ( इंजिनियर )
आत्या :- श्री. भाऊसो कृष्णाजी मोरे रा. इस्लामपूर
मोबाइल नंबर :- 9860956022 / 8668270153
TXT, true);

        $blob = $this->sectionBlob($out['sections']['siblings'])
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

    public function test_values_are_not_duplicated_across_wizard_sections(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'HTML'
<table>
<tr><td>मुलाचे नाव</td><td>चि. रोहित शिंदे</td></tr>
<tr><td>उंची</td><td>१७२ सेमी</td></tr>
<tr><td>शिक्षण</td><td>B.Com</td></tr>
<tr><td>भाऊ</td><td>चि. संकेत शिंदे ( अविवाहित ) ( इंजिनियर )</td></tr>
<tr><td>मोबाईल नंबर</td><td>9876543210</td></tr>
</table>
HTML, true);

        $basicBlob = $this->sectionBlob($out['sections']['basic-info']);
        $physicalBlob = $this->sectionBlob($out['sections']['physical']);
        $educationBlob = $this->sectionBlob($out['sections']['education-career']);
        $familyBlob = $this->sectionBlob($out['sections']['family-details']);
        $siblingsBlob = $this->sectionBlob($out['sections']['siblings']);

        $this->assertStringContainsString('cm', $physicalBlob);
        $this->assertStringNotContainsString('cm', $basicBlob);
        $this->assertStringContainsString('B.Com', $educationBlob);
        $this->assertStringNotContainsString('B.Com', $basicBlob);
        $this->assertStringContainsString('9876543210', $basicBlob);
        $this->assertStringContainsString('संकेत', $siblingsBlob);
        $this->assertStringNotContainsString('संकेत', $familyBlob);
        $this->assertArrayNotHasKey('contacts', $out['sections']);
    }

    public function test_unavailable_when_not_biodata_text(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present('AI unavailable message', false);

        $this->assertFalse($out['available']);
        $this->assertSame('not_biodata_text', $out['skipped_reason']);
        $this->assertSame([], $out['sections']['basic-info']);
        $this->assertArrayNotHasKey('personal', $out['sections']);
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
