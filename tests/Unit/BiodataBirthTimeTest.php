<?php

namespace Tests\Unit;

use App\Services\BiodataParserService;
use PHPUnit\Framework\TestCase;

/**
 * `birth_time` holds a clock time. Every value entered by hand is already
 * HH:MM; what broke it was a biodata line arriving whole, weekday and all —
 * the labels the parser reads literally include "जन्म वेळ व वार".
 *
 * The parser has known how to read a Marathi time since the start. It only
 * recognised shapes carrying "मि."/"मी.", so the common ones fell through and
 * the whole sentence was stored: too long for the column where it failed
 * outright, and unsortable where it fit.
 */
class BiodataBirthTimeTest extends TestCase
{
    /**
     * @dataProvider examples
     */
    public function test_only_the_clock_time_survives(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, BiodataParserService::normalizeBirthTime($input));
    }

    public static function examples(): array
    {
        return [
            'the line that did not fit the column' => ['शुक्रवार सकाळी 7 वाजता', '07:00'],
            'dot instead of colon' => ['सकाळी 9.00 वाजता', '09:00'],
            'minutes are kept' => ['सकाळी 6.50 वाजता', '06:50'],
            'the shape that always worked' => ['सकाळी 7 वा. 30 मि.', '07:30'],
            'colon plus minutes marker' => ['दुपारी 1:40 मि.', '13:40'],
            'afternoon shifts to 24h' => ['दुपारी 2.30 वाजता', '14:30'],
            'evening shifts to 24h' => ['संध्याकाळी 6.15 वाजता', '18:15'],
            'night shifts to 24h' => ['रात्री 9 वाजता', '21:00'],
            'dawn stays morning' => ['पहाटे 4.05 वाजता', '04:05'],
            'noon hour with morning word' => ['सकाळी 12.10 वाजता', '00:10'],
            'no time in the line' => ['माहीत नाही', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    public function test_the_weekday_never_reaches_the_column(): void
    {
        $out = (string) BiodataParserService::normalizeBirthTime('शुक्रवार सकाळी 7 वाजता');

        $this->assertSame('07:00', $out);
        $this->assertStringNotContainsString('शुक्रवार', $out);
    }

    public function test_whatever_survives_fits_the_column(): void
    {
        // varchar(20). A value that does not fit is not stored at all, which is
        // how one profile lost its birth time and stayed stuck instead.
        foreach (self::examples() as $case) {
            $out = BiodataParserService::normalizeBirthTime($case[0]);
            if ($out !== null) {
                $this->assertLessThanOrEqual(20, mb_strlen($out));
            }
        }
    }
}
