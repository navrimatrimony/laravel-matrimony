<?php

namespace Tests\Unit\Location;

use App\Services\Location\LocationCompoundAddressParser;
use Tests\TestCase;

class LocationCompoundAddressParserTest extends TestCase
{
    public function test_parses_varkute_malavdi_man_satara_components(): void
    {
        $parser = new LocationCompoundAddressParser;
        $components = $parser->parseComponents('वरकुटे-मलवडी, ता. माण, जि. सातारा');

        $this->assertSame('वरकुटे मलवडी', $components['village']);
        $this->assertSame('माण', $components['taluka']);
        $this->assertSame('सातारा', $components['district']);
    }

    public function test_search_queries_prioritize_village_and_taluka(): void
    {
        $parser = new LocationCompoundAddressParser;
        $queries = $parser->searchQueries('वरकुटे-मलवडी, ता. माण, जि. सातारा');

        $this->assertNotEmpty($queries);
        $this->assertSame('वरकुटे मलवडी माण', $queries[0]);
        $this->assertContains('वरकुटे मलवडी', $queries);
        $this->assertContains('वरकुटे', $queries);
    }

    public function test_alias_keys_include_normalized_village(): void
    {
        $parser = new LocationCompoundAddressParser;
        $keys = $parser->aliasLookupKeys('वरकुटे-मलवडी, ता. माण, जि. सातारा');

        $this->assertContains('वरकुटे मलवडी', $keys);
        $this->assertContains('वरकुटे मलवडी माण', $keys);
    }

    public function test_parses_tasgaon_dash_admin_format(): void
    {
        $parser = new LocationCompoundAddressParser;
        $components = $parser->parseComponents('तासगाव ता. - तासगाव, जि. - सांगली');

        $this->assertSame('तासगाव', $components['village']);
        $this->assertSame('तासगाव', $components['taluka']);
        $this->assertSame('सांगली', $components['district']);
        $this->assertContains('तासगाव', $parser->searchQueries('तासगाव ता. - तासगाव, जि. - सांगली'));
    }
}
