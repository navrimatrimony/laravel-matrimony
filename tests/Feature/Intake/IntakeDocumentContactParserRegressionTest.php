<?php

namespace Tests\Feature\Intake;

use App\Services\BiodataParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeDocumentContactParserRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_marathi_mobile_label_extracts_document_contact(): void
    {
        $parsed = $this->parse("नाव :- अनिल नमुना पाटील\nमोबाईल: 9876543210\nशिक्षण :- B Com");

        $this->assertSame(['9876543210'], $this->documentPhones($parsed));
        $this->assertNull($parsed['core']['primary_contact_number'] ?? null);
    }

    public function test_marathi_contact_number_extracts_multiple_document_contacts(): void
    {
        $parsed = $this->parse("नाव :- कविता नमुना पाटील\nसंपर्क क्रमांक: 9876543210 / 9123456789\nशिक्षण :- B A");

        $this->assertSame(['9876543210', '9123456789'], $this->documentPhones($parsed));
        $this->assertNull($parsed['core']['primary_contact_number'] ?? null);
    }

    public function test_english_contact_with_country_code_extracts_multiple_document_contacts(): void
    {
        $parsed = $this->parse("Name: Synthetic Contact Candidate\nContact: +91 9876543210, 9123456789\nEducation: B Com");

        $this->assertSame(['9876543210', '9123456789'], $this->documentPhones($parsed));
        $this->assertNull($parsed['core']['primary_contact_number'] ?? null);
    }

    public function test_devanagari_digits_mobile_label_extracts_normalized_document_contact(): void
    {
        $parsed = $this->parse("नाव :- सुनील नमुना पाटील\nमो.: ९८७६५४३२१०\nशिक्षण :- B Sc");

        $this->assertSame(['9876543210'], $this->documentPhones($parsed));
        $this->assertNull($parsed['core']['primary_contact_number'] ?? null);
    }

    public function test_pincode_is_not_extracted_as_document_contact(): void
    {
        $parsed = $this->parse("नाव :- पत्ता नमुना पाटील\nपत्ता: Sample Nagar 416416\nशिक्षण :- B Com");

        $this->assertSame([], $this->documentPhones($parsed));
        $this->assertNull($parsed['core']['primary_contact_number'] ?? null);
    }

    public function test_date_of_birth_is_not_extracted_as_document_contact(): void
    {
        $parsed = $this->parse("नाव :- दिनांक नमुना पाटील\nजन्म तारीख: 02/10/1998\nशिक्षण :- B Com");

        $this->assertSame([], $this->documentPhones($parsed));
        $this->assertNull($parsed['core']['primary_contact_number'] ?? null);
    }

    public function test_income_is_not_extracted_as_document_contact(): void
    {
        $parsed = $this->parse("Name: Synthetic Income Candidate\nIncome: 16,75,000 P/A\nEducation: B Com");

        $this->assertSame([], $this->documentPhones($parsed));
        $this->assertNull($parsed['core']['primary_contact_number'] ?? null);
    }

    public function test_primary_contact_is_not_auto_filled_from_document_contact(): void
    {
        $parsed = $this->parse("Name: Synthetic Primary Boundary\nMobile: 9876543210\nEducation: B Com");

        $this->assertSame(['9876543210'], $this->documentPhones($parsed));
        $this->assertNull($parsed['core']['primary_contact_number'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $text): array
    {
        return app(BiodataParserService::class)->parse($text);
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return list<string>
     */
    private function documentPhones(array $parsed): array
    {
        $phones = [];
        foreach (($parsed['contacts'] ?? []) as $contact) {
            if (($contact['type'] ?? null) !== 'document_contact') {
                continue;
            }
            $number = (string) ($contact['phone_number'] ?? $contact['number'] ?? '');
            if ($number !== '') {
                $phones[] = $number;
            }
        }

        return $phones;
    }
}
