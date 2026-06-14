<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrHtmlTableFlattener;
use App\Services\Ocr\OcrNormalize;
use Tests\TestCase;

class OcrHtmlTableFlattenerTest extends TestCase
{
    public function test_table_row_becomes_label_colon_value_line(): void
    {
        $html = '<table><tr><td>जन्म तारीख</td><td>:-</td><td>०८ ऑगस्ट १९९७</td></tr></table>';
        $flat = OcrHtmlTableFlattener::flatten($html);
        $this->assertSame('जन्म तारीख :- ०८ ऑगस्ट १९९७', $flat);
        $this->assertSame('1997-08-08', OcrNormalize::normalizeDate('०८ ऑगस्ट १९९७'));
    }

    public function test_dual_day_biodata_birth_date_uses_second_calendar_day(): void
    {
        $this->assertSame('1994-10-25', OcrNormalize::normalizeDate('२४-२५/१० / १९९४'));
    }
}
