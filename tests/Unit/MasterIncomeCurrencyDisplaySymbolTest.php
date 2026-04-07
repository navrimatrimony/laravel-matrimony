<?php

namespace Tests\Unit;

use App\Models\MasterIncomeCurrency;
use PHPUnit\Framework\TestCase;

class MasterIncomeCurrencyDisplaySymbolTest extends TestCase
{
    public function test_display_symbol_uses_utf8_map_by_code_not_corrupt_db_column(): void
    {
        $m = new MasterIncomeCurrency;
        $m->code = 'INR';
        $m->setRawAttributes(array_merge($m->getAttributes(), ['symbol' => 'â‚¹']));

        $this->assertSame('₹', $m->displaySymbol());
    }

    public function test_symbol_for_code_returns_known_symbols(): void
    {
        $this->assertSame('€', MasterIncomeCurrency::symbolForCode('eur'));
        $this->assertSame('د.إ', MasterIncomeCurrency::symbolForCode('AED'));
    }
}
