<?php

namespace Tests\Unit\Governance;

use App\Services\Governance\RecursiveRenderedFieldExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecursiveRenderedFieldExtractorTest extends TestCase
{
    #[Test]
    public function it_flattens_scalar_repeater_and_nested_paths(): void
    {
        $svc = new RecursiveRenderedFieldExtractor;
        $flat = $svc->flatten([
            'full_name' => 'A',
            'siblings' => [
                ['name' => 'B', 'marital_status' => 'single'],
            ],
            'partner_preferences' => ['preferred_age_min' => 28],
        ]);

        $this->assertSame('A', $flat['full_name']);
        $this->assertSame('B', $flat['siblings.0.name']);
        $this->assertSame('single', $flat['siblings.0.marital_status']);
        $this->assertSame(28, $flat['partner_preferences.preferred_age_min']);
    }

    #[Test]
    public function it_handles_empty_repeaters_and_mixed_values(): void
    {
        $svc = new RecursiveRenderedFieldExtractor;
        $flat = $svc->flatten([
            'children' => [],
            'contacts' => [
                ['phone' => null],
            ],
        ]);

        $this->assertArrayHasKey('children', $flat);
        $this->assertSame([], $flat['children']);
        $this->assertArrayHasKey('contacts.0.phone', $flat);
        $this->assertNull($flat['contacts.0.phone']);
    }

    #[Test]
    public function it_extracts_against_html_presence(): void
    {
        $svc = new RecursiveRenderedFieldExtractor;
        $res = $svc->extractAgainstHtml(
            ['full_name' => 'Anita', 'siblings' => [['name' => 'Raj']]],
            '<div>Anita</div><span>Raj</span>'
        );

        $this->assertSame('Anita', $res['full_name']);
        $this->assertSame('Raj', $res['siblings.0.name']);
    }
}

