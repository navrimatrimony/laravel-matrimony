<?php

namespace Tests\Unit\Intake;

use App\Services\Intake\IntakeLocationFieldRegistry;
use Tests\TestCase;

class IntakeLocationFieldRegistryTest extends TestCase
{
    public function test_birth_place_dom_anchor(): void
    {
        $this->assertSame(
            ['type' => 'location_context', 'value' => 'birth'],
            IntakeLocationFieldRegistry::domAnchor('birth_place')
        );
    }

    public function test_parents_address_row_dom_anchor(): void
    {
        $this->assertSame(
            ['type' => 'parents_address_row', 'index' => 0],
            IntakeLocationFieldRegistry::domAnchor('addresses.0')
        );
    }

    public function test_relative_row_dom_anchor(): void
    {
        $this->assertSame(
            ['type' => 'relatives_row', 'container' => 'relatives_parents_family', 'index' => 2],
            IntakeLocationFieldRegistry::domAnchor('relatives_parents_family.2')
        );
    }

    public function test_ssot_doc_path_is_declared(): void
    {
        $this->assertFileExists(base_path(IntakeLocationFieldRegistry::DOC_PATH));
        $this->assertNotEmpty(IntakeLocationFieldRegistry::LOCKED_INVARIANTS);
    }
}
