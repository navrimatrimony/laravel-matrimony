<?php

namespace Tests\Unit\Support\Encoding;

use App\Support\Encoding\Utf8MojibakeRepair;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Utf8MojibakeRepairTest extends TestCase
{
    public function test_detects_devanagari_mojibake_markers(): void
    {
        $this->assertTrue(Utf8MojibakeRepair::looksLikeMojibake('à¤¨à¤µà¤°à¥€'));
        $this->assertTrue(Utf8MojibakeRepair::looksLikeMojibake('àª—à«€àª¤àª¾àªªà«àª°'));
        $this->assertFalse(Utf8MojibakeRepair::looksLikeMojibake('नवरी'));
        $this->assertFalse(Utf8MojibakeRepair::looksLikeMojibake('English only'));
        $this->assertFalse(Utf8MojibakeRepair::looksLikeMojibake(''));
    }

    #[DataProvider('repairProvider')]
    public function test_repairs_known_mojibake(string $broken, string $expected): void
    {
        $this->assertSame($expected, Utf8MojibakeRepair::repair($broken));
    }

    public function test_leaves_clean_utf8_unchanged(): void
    {
        $this->assertNull(Utf8MojibakeRepair::repair('कु. अविनाश प्रकाश कदम'));
        $this->assertNull(Utf8MojibakeRepair::repair('Hello 😀'));
        $this->assertNull(Utf8MojibakeRepair::repair('Plain English'));
    }

    public function test_rejects_mixed_clean_and_mojibake(): void
    {
        $this->assertNull(Utf8MojibakeRepair::repair('नवरी à¤®à¤¿à¤³à¥‡'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function repairProvider(): array
    {
        return [
            'brand' => ['à¤¨à¤µà¤°à¥€ à¤®à¤¿à¤³à¥‡ à¤¨à¤µà¤±à¥à¤¯à¤¾à¤²à¤¾', 'नवरी मिळे नवऱ्याला'],
            'name' => ['à¤•à¥. à¤…à¤µà¤¿à¤¨à¤¾à¤¶ à¤ªà¥à¤°à¤•à¤¾à¤¶ à¤•à¤¦à¤®', 'कु. अविनाश प्रकाश कदम'],
            'caste' => ['à¤¬à¤¹à¤¾à¤ˆ', 'बहाई'],
            'gujarati_place' => ['àª—à«€àª¤àª¾àªªà«àª°', 'ગીતાપુર'],
            'telugu_place' => ['à°•à°¾à°•à°°à°ªà°¾à°¡à±', 'కాకరపాడు'],
        ];
    }
}
