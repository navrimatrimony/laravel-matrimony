<?php

namespace Tests\Unit\Intake;

use App\Services\Intake\ParentsBiodataAddressSplitter;
use Tests\TestCase;

class ParentsBiodataAddressSplitterTest extends TestCase
{
    public function test_splits_address_line_location_and_strips_mobile_label(): void
    {
        $raw = 'मु. पो. 2018 ए वॉर्ड, शिवाजी पेठ, कोल्हापूर, ता. करवीर, जि. कोल्हापूर - ■ मोबाईल नंबर';

        $split = ParentsBiodataAddressSplitter::split($raw);

        $this->assertStringContainsString('2018', $split['address_line']);
        $this->assertStringContainsString('शिवाजी पेठ', $split['address_line']);
        $this->assertStringNotContainsString('मोबाईल', $split['address_line']);
        $this->assertStringContainsString('ता. करवीर', $split['location_text']);
        $this->assertStringContainsString('जि. कोल्हापूर', $split['location_text']);
    }

    public function test_extracts_embedded_phone_digits(): void
    {
        $raw = 'मु. पो. गाव, ता. माण, जि. सातारा, मो. 9876543210';

        $split = ParentsBiodataAddressSplitter::split($raw);

        $this->assertSame(['9876543210'], $split['phones']);
        $this->assertStringNotContainsString('9876543210', $split['address_line']);
    }

    public function test_detects_parents_home_blob(): void
    {
        $this->assertTrue(ParentsBiodataAddressSplitter::looksLikeParentsHomeBlob(
            'मु. पो. 2018 ए वॉर्ड, शिवाजी पेठ, कोल्हापूर, ता. करवीर, जि. कोल्हापूर'
        ));
        $this->assertFalse(ParentsBiodataAddressSplitter::looksLikeParentsHomeBlob('Flat 12, Wagholi, Pune'));
    }
}
