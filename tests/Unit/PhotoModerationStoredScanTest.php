<?php

namespace Tests\Unit;

use App\Services\Admin\PhotoModerationScanPresenter;
use App\Services\Admin\PhotoModerationStoredScan;
use PHPUnit\Framework\TestCase;

class PhotoModerationStoredScanTest extends TestCase
{
    public function test_headline_reads_legacy_status_and_confidence_keys(): void
    {
        $scan = [
            'scanner' => 'nudenet',
            'status' => 'review',
            'confidence' => 0.61,
            'detections' => [['class' => 'BELLY_EXPOSED', 'score' => 0.55]],
        ];

        $this->assertSame('review', PhotoModerationStoredScan::apiStatus($scan));
        $this->assertSame(0.61, PhotoModerationStoredScan::pipelineConfidence($scan));

        $h = PhotoModerationScanPresenter::headline($scan);
        $this->assertSame('review', $h['api_status']);
        $this->assertEqualsWithDelta(61.0, (float) $h['confidence_pct'], 0.001);
        $this->assertSame(1, $h['detection_count']);
    }

    public function test_as_array_returns_null_for_null(): void
    {
        $this->assertNull(PhotoModerationStoredScan::asArray(null));
    }
}
