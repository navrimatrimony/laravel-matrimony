<?php

namespace Tests\Unit;

use App\Support\Utf8MojibakeRepair;
use PHPUnit\Framework\TestCase;

class Utf8MojibakeRepairTest extends TestCase
{
    public function test_repairs_devanagari_mojibake(): void
    {
        $mojibake = 'à¤°à¤¾à¤§à¤¾';
        $this->assertSame('राधा', Utf8MojibakeRepair::repair($mojibake));
    }

    /** Last UTF-8 byte 0x80 shows as € (U+20AC) when misread as Windows-1252. */
    public function test_repairs_devanagari_mojibake_with_euro_stand_in_for_0x80(): void
    {
        $mojibake = 'à¤°à¤¾à¤£à¥€';
        $this->assertSame('राणी', Utf8MojibakeRepair::repair($mojibake));
    }

    public function test_leaves_plain_ascii_unchanged(): void
    {
        $this->assertSame('Ramesh', Utf8MojibakeRepair::repair('Ramesh'));
    }

    public function test_leaves_correct_devanagari_unchanged(): void
    {
        $ok = 'श्रीमती. सुनीता';
        $this->assertSame($ok, Utf8MojibakeRepair::repair($ok));
    }

    /** UTF-8 en dash (E2 80 93) misread as CP1252 → â (U+00E2) + € + “ (U+201C). */
    public function test_repairs_en_dash_mojibake_with_curly_quote(): void
    {
        $mojibake = 'Bachelor '."\xC3\xA2\xE2\x82\xAC\xE2\x80\x9C".' Arts';
        $expected = 'Bachelor '."\xE2\x80\x93".' Arts';
        $this->assertSame($expected, Utf8MojibakeRepair::repair($mojibake));
    }

    public function test_repairs_en_dash_mojibake_with_ascii_quote(): void
    {
        $mojibake = 'Bachelor '."\xC3\xA2\xE2\x82\xAC".'"'.' Arts';
        $expected = 'Bachelor '."\xE2\x80\x93".' Arts';
        $this->assertSame($expected, Utf8MojibakeRepair::repair($mojibake));
    }

    /** UTF-8 em dash (E2 80 94) → â + € + ” (U+201D). */
    public function test_repairs_em_dash_mojibake(): void
    {
        $mojibake = 'A '."\xC3\xA2\xE2\x82\xAC\xE2\x80\x9D".' B';
        $expected = 'A '."\xE2\x80\x94".' B';
        $this->assertSame($expected, Utf8MojibakeRepair::repair($mojibake));
    }

    public function test_leaves_correct_utf8_en_dash_unchanged(): void
    {
        $ok = 'Bachelor '."\xE2\x80\x93".' Arts';
        $this->assertSame($ok, Utf8MojibakeRepair::repair($ok));
    }
}
