<?php

namespace Tests\Unit\Support;

use App\Support\MoneyFormat;
use PHPUnit\Framework\TestCase;

/**
 * Two frozen workspace rules are asserted here: Latin digits, and Indian comma
 * grouping. The second is the one that kept slipping through, because
 * `number_format()` agrees with Indian grouping on every figure below a lakh —
 * so the ₹15,000 and ₹500 on a review screen look right while the ₹1,00,000
 * success fee beside them does not.
 */
class MoneyFormatTest extends TestCase
{
    public function test_a_lakh_groups_the_indian_way_not_the_western_way(): void
    {
        $this->assertSame('₹1,00,000', MoneyFormat::amount(100000));
        $this->assertSame('₹1,51,000', MoneyFormat::amount('151000'));
        $this->assertSame('₹12,34,567', MoneyFormat::amount(1234567));
    }

    public function test_a_crore_keeps_pairing_upward(): void
    {
        $this->assertSame('₹1,00,00,000', MoneyFormat::amount(10000000));
    }

    public function test_below_a_lakh_the_two_conventions_agree(): void
    {
        $this->assertSame('₹500', MoneyFormat::amount(500));
        $this->assertSame('₹15,000', MoneyFormat::amount('15000'));
        $this->assertSame('₹99,999', MoneyFormat::amount(99999));
    }

    public function test_paise_appear_only_when_there_are_paise(): void
    {
        $this->assertSame('₹15,000', MoneyFormat::amount(15000.0));
        $this->assertSame('₹1,00,000.50', MoneyFormat::amount(100000.5));
    }

    public function test_an_unquoted_amount_stays_unquoted_rather_than_becoming_zero(): void
    {
        $this->assertNull(MoneyFormat::amount(null));
        $this->assertNull(MoneyFormat::amount(''));
        // Zero is a real quoted figure and must survive.
        $this->assertSame('₹0', MoneyFormat::amount(0));
    }

    public function test_a_non_rupee_currency_carries_its_code_instead_of_a_symbol(): void
    {
        $this->assertSame('USD 1,00,000', MoneyFormat::amount(100000, 'usd'));
    }

    public function test_no_devanagari_numeral_can_come_out_of_here(): void
    {
        $rendered = MoneyFormat::amount(1234567.89);

        $this->assertSame('₹12,34,567.89', $rendered);
        foreach (['०', '१', '२', '३', '४', '५', '६', '७', '८', '९'] as $devanagari) {
            $this->assertStringNotContainsString($devanagari, (string) $rendered);
        }
    }
}
