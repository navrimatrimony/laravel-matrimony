<?php

namespace Tests\Unit\Parsing;

use App\Services\Ocr\OcrNormalize;
use App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntakeNormalizedBiodataDraftBuilderTest extends TestCase
{
    public function test_yuvraj_sample_normalizes_contacts_caste_mother_and_keeps_gender_review_safe(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
*प्रतिमा: decorative logo*
:■:
वैयक्तिक माहिती
नाव : कु. युवराज नामदेव घाटेगस्ती.
जात : हिंदू मराठा {96 कुळी}
आईचे नाव : सौ. सुनंदा नामदेव घाटेगस्ती. { गृहिणी }
मोबाईल नं : 73509 53384/ 96733 50078
TXT);

        $core = $draft['normalized']['core'];
        $phones = $this->phones($draft);
        $this->assertContains('7350953384', $phones);
        $this->assertContains('9673350078', $phones);
        $this->assertSame('मराठा', $core['caste']);
        $this->assertSame('96 कुळी', OcrNormalize::normalizeDigits((string) $core['sub_caste']));
        $this->assertSame('हिंदू', $core['religion']);
        $this->assertSame('गृहिणी', $core['mother_occupation']);
        $this->assertNull($core['gender']);
        $this->assertTrue($this->hasReviewFlag($draft, 'core.gender', 'ambiguous_gender'));
        $this->assertSame([], $queries);
    }

    public function test_swapnil_sample_counts_siblings_and_stops_relatives_before_boundaries(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
बायोडाटा
मुलाचे नांव :- चि. स्वप्नील सतिश शिंदे
भाऊ :- नाही
बहीण :- एक ( अविवाहित )
आत्या :- श्री. भाऊसो कृष्णाजी मोरे रा. इस्लामपूर
घरचा पत्ता :- मु. पो. समडोळी , ता. मिरज , जि. सांगली.
पाहुणे :- तातुगडे - देशमुख
मोबाइल नंबर :- 9860956022 / 8668270153
TXT);

        $core = $draft['normalized']['core'];
        $this->assertSame('male', $core['gender']);
        $this->assertSame(0, $core['brother_count']);
        $this->assertSame(1, $core['sister_count']);
        foreach ($draft['normalized']['siblings'] as $sibling) {
            $this->assertNotContains($sibling['name'] ?? '', ['नाही', 'एक']);
        }
        $phones = $this->phones($draft);
        $this->assertContains('9860956022', $phones);
        $this->assertContains('8668270153', $phones);

        $relativeBlob = implode(' ', array_map(
            static fn ($row) => implode(' ', array_map('strval', is_array($row) ? $row : [])),
            $draft['normalized']['relatives']
        ));
        $this->assertStringNotContainsString('घरचा पत्ता', $relativeBlob);
        $this->assertStringNotContainsString('पाहुणे', $relativeBlob);
        $this->assertStringNotContainsString('मोबाइल नंबर', $relativeBlob);
    }

    public function test_mahesh_sample_prefers_heading_name_and_keeps_contacts_clean(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
कास्ट :- ९६ कुळी मराठा
पित्याचे नाव :-मोहनराव गणपतराव जगताप
प्रोपर्टी :- 1BHK Flat (1) 2 BHK Flat (2)
गावचा पत्ता :- चंद्रेश बिल्डिंग, ठाणे

## महेशकुमार मोहन जगताप

मोबाईल नंबर :- महेश मोहन जगताप (९८७०८७९७२७)
:- मोहन जगताप (९१३७७९३३७१)
TXT);

        $core = $draft['normalized']['core'];
        $this->assertSame('महेशकुमार मोहन जगताप', $core['full_name']);
        $this->assertNotSame('मोहनराव गणपतराव जगताप', $core['full_name']);
        $this->assertSame('मोहनराव गणपतराव जगताप', $core['father_name']);
        $this->assertSame('मराठा', $core['caste']);
        $this->assertSame('96 कुळी', OcrNormalize::normalizeDigits((string) $core['sub_caste']));
        $this->assertTrue($this->hasReviewFlag($draft, 'core.full_name', 'candidate_name_from_heading_fallback'));

        $phones = $this->phones($draft);
        $this->assertContains('9870879727', $phones);
        $this->assertContains('9137793371', $phones);

        $addressBlob = implode(' ', array_map(
            static fn ($row) => implode(' ', array_map('strval', is_array($row) ? $row : [])),
            $draft['normalized']['addresses']
        ));
        $this->assertStringNotContainsString('मोबाईल', $addressBlob);
        $this->assertStringNotContainsString('संपर्क', $addressBlob);
        $this->assertStringNotContainsString('कौटुंबिक', $addressBlob);

        $relativeBlob = implode(' ', array_map(
            static fn ($row) => implode(' ', array_map('strval', is_array($row) ? $row : [])),
            $draft['normalized']['relatives']
        ));
        $this->assertStringNotContainsString('प्रोपर्टी', $relativeBlob);
        $this->assertStringNotContainsString('मोबाईल', $relativeBlob);
        $this->assertStringNotContainsString('गावचा पत्ता', $relativeBlob);
    }

    public function test_candidate_heading_rejects_parent_and_relative_names(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
पित्याचे नाव :- मोहनराव गणपतराव जगताप
आईचे नाव :- अनिता मोहनराव जगताप
मामा :- डॉ. वसंतराव मोरे पाटील

## महेशकुमार मोहन जगताप
TXT);

        $core = $draft['normalized']['core'];
        $this->assertSame('महेशकुमार मोहन जगताप', $core['full_name']);
        $this->assertNotSame($core['father_name'], $core['full_name']);
        $this->assertNotSame($core['mother_name'], $core['full_name']);
        $this->assertStringNotContainsString('वसंतराव', (string) $core['full_name']);
    }

    public function test_gharcha_patta_is_address_not_property(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
घरचा पत्ता :- मु. पो. समडोळी, ता. मिरज, जि. सांगली
TXT);

        $addressBlob = implode(' ', array_map(
            static fn ($row) => implode(' ', array_map('strval', is_array($row) ? $row : [])),
            $draft['normalized']['addresses']
        ));
        $this->assertStringContainsString('समडोळी', $addressBlob);

        $propertySummary = (string) ($draft['normalized']['property_summary']['summary_text'] ?? '');
        $this->assertStringNotContainsString('समडोळी', $propertySummary);
        $this->assertStringNotContainsString('घरचा पत्ता', $propertySummary);
    }

    public function test_svatache_ghar_is_property_with_address(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
सध्याचा पत्ता : स्वराज्य कॉलनी, संगमनगर.
(स्व:ताच्या मालकीचे घर.)
TXT);

        $addressBlob = implode(' ', array_map(
            static fn ($row) => implode(' ', array_map('strval', is_array($row) ? $row : [])),
            $draft['normalized']['addresses']
        ));
        $this->assertStringContainsString('स्वराज्य कॉलनी', $addressBlob);

        $propertySummary = (string) ($draft['normalized']['property_summary']['summary_text'] ?? '');
        $this->assertMatchesRegularExpression('/मालकीच(?:े|्या)\s*घर/u', $propertySummary);
    }

    public function test_relative_block_stops_before_address_pahune_and_mobile(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
मावशी :- कै. संजय बापुसो शिंदे ( समडोळी )
श्री. ऋषिकेश मोहन सावंत ( कुंडल )
श्री. नंदकुमार बाळासो जाधव ( शिरोळ )
घरचा पत्ता :- मु. पो. समडोळी , ता. मिरज , जि. सांगली.
पाहुणे :- तातुगडे - देशमुख
मोबाइल नंबर :- 9860956022 / 8668270153
TXT);

        $this->assertGreaterThanOrEqual(1, count($draft['normalized']['relatives']));

        foreach ($draft['normalized']['relatives'] as $relative) {
            $blob = implode(' ', array_map('strval', is_array($relative) ? $relative : []));
            $this->assertStringNotContainsString('घरचा पत्ता', $blob);
            $this->assertStringNotContainsString('पाहुणे', $blob);
            $this->assertStringNotContainsString('मोबाइल नंबर', $blob);
            $this->assertStringNotContainsString('मोबाइल', $blob);
        }

        $phones = $this->phones($draft);
        $this->assertContains('9860956022', $phones);
        $this->assertContains('8668270153', $phones);
    }

    public function test_caste_without_religion_remains_review_safe(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
कास्ट :- ९६ कुळी मराठा
TXT);

        $core = $draft['normalized']['core'];
        $this->assertSame('मराठा', $core['caste']);
        $this->assertSame('96 कुळी', OcrNormalize::normalizeDigits((string) $core['sub_caste']));
        $this->assertNull($core['religion']);
        $this->assertTrue($this->hasReviewFlag($draft, 'core.religion', 'missing_critical'));
    }

    public function test_clean_text_preserves_useful_symbols_in_real_biodata_lines(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
नोकरी :- सांगली जि. म. सह. बँक लि. (क्लर्क परमनंट)
शिक्षण :- M.com (G.D.C & A)
मोबाईल नं : 73509 53384/ 96733 50078
TXT);

        $this->assertStringContainsString('G.D.C & A', $draft['cleaned_text']);
        $this->assertStringContainsString('क्लर्क परमनंट', $draft['cleaned_text']);

        $phones = $this->phones($draft);
        $this->assertContains('7350953384', $phones);
        $this->assertContains('9673350078', $phones);
    }

    public function test_candidate_name_does_not_fall_back_to_section_heading(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
बायोडाटा
कौटुंबिक माहिती
वडिलांचे नाव :- श्री. नामदेव पाटील
आईचे नाव :- सौ. सुनंदा पाटील
TXT);

        $this->assertNull($draft['normalized']['core']['full_name'] ?? null);
    }

    public function test_mixed_birth_time_religion_caste_height_horoscope_and_relatives_are_normalized(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
मुलीचे नाव :- कु. अंजली शंकर पाटील
जन्म तारीख :- 01/01/1998
वार :- 3.45 A.M रात्री सोमवार
धर्म :- हिंदू
जात :- मराठा
उंची :- 5 फुट 4 इंच
राशी :- मेष
नक्षत्र :- अश्विनी
चरण :- २
नाडी :- आद्य
गण :- देव
देवक :- वड
कुलस्वामी :- जोतिबा
- मामा - श्री. मोहन कदम पुणे
- माऊशी - सौ. कविता जाधव
- इतर नातेवाईक - पाटील, कदम, जाधव
भाऊ :- 2
बहिणी :- 1
नोकरी :- Software Engineer - TCS, Pune
प्रॉपर्टी :- स्वतःचे घर, शेती 2 एकर
TXT);

        $core = $draft['normalized']['core'];
        $this->assertSame('कु. अंजली शंकर पाटील', $core['full_name']);
        $this->assertStringContainsString('3.45 A.M', (string) $core['birth_time']);
        $this->assertSame('हिंदू', $core['religion']);
        $this->assertSame('मराठा', $core['caste']);
        $this->assertEqualsWithDelta(162.56, (float) $core['height_cm'], 0.01);
        $this->assertSame(2, $core['brother_count']);
        $this->assertSame(1, $core['sister_count']);
        $this->assertSame('Software Engineer', $core['occupation_title']);
        $this->assertSame('TCS', $core['company_name']);
        $this->assertSame('Pune', $core['work_location_text']);
        $this->assertStringContainsString('पाटील', (string) ($core['other_relatives_text'] ?? ''));

        $horoscope = $draft['normalized']['horoscope'];
        $this->assertSame('मेष', $horoscope['rashi']);
        $this->assertSame('अश्विनी', $horoscope['nakshatra']);
        $this->assertSame('२', $horoscope['charan']);
        $this->assertSame('आद्य', $horoscope['nadi']);
        $this->assertSame('देव', $horoscope['gan']);
        $this->assertSame('वड', $horoscope['devak']);
        $this->assertSame('जोतिबा', $horoscope['kuldaivat']);

        $relativeBlob = implode(' ', array_map(
            static fn ($row) => implode(' ', array_map('strval', is_array($row) ? $row : [])),
            $draft['normalized']['relatives']
        ));
        $this->assertStringContainsString('मोहन कदम', $relativeBlob);
        $this->assertStringContainsString('कविता जाधव', $relativeBlob);
        $this->assertStringContainsString('स्वतःचे घर', (string) ($draft['normalized']['property_summary']['summary_text'] ?? ''));
    }

    public function test_unmapped_useful_lines_are_flagged_without_full_raw_dump(): void
    {
        $text = <<<TXT
बायोडाटा
अपेक्षा :- शिक्षित मुलगी हवी
संदिग्ध माहिती :- हा लांब मजकूर review मध्ये पूर्ण biodata dump म्हणून जाऊ नये.
नोकरी माहिती उपलब्ध पण format वेगळा आहे
TXT;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($text);

        $this->assertTrue($this->hasReviewFlag($draft, 'review.education-career', 'unmapped_career'));
        $reviewBlob = implode("\n", array_map(
            static fn ($flag) => (string) ($flag['raw'] ?? ''),
            $draft['review_flags']
        ));
        $this->assertStringContainsString('नोकरी माहिती', $reviewBlob);
        $this->assertStringNotContainsString($text, $reviewBlob);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<string>
     */
    private function phones(array $draft): array
    {
        return array_values(array_map(
            static fn ($row) => (string) ($row['phone_number'] ?? ''),
            $draft['normalized']['contacts']
        ));
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function hasReviewFlag(array $draft, string $field, string $reason): bool
    {
        foreach ($draft['review_flags'] as $flag) {
            if (($flag['field'] ?? null) === $field && ($flag['reason'] ?? null) === $reason) {
                return true;
            }
        }

        return false;
    }
}
