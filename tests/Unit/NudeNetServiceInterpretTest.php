<?php

namespace Tests\Unit;

use App\Services\Image\NudeNetService;
use Tests\TestCase;

class NudeNetServiceInterpretTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['photo_processing.nudenet_unsafe_score_min' => 0.25]);
    }

    public function test_safe_true_overridden_when_detection_score_high(): void
    {
        $out = NudeNetService::interpretDetectorJson([
            'safe' => true,
            'detections' => [
                ['class' => 'FEMALE_BREAST_EXPOSED', 'score' => 0.92],
            ],
        ]);
        $this->assertFalse($out['safe']);
    }

    public function test_unsafe_flag_forces_unsafe(): void
    {
        $out = NudeNetService::interpretDetectorJson([
            'safe' => true,
            'unsafe' => true,
        ]);
        $this->assertFalse($out['safe']);
    }

    public function test_safe_true_kept_when_no_detections(): void
    {
        $out = NudeNetService::interpretDetectorJson([
            'safe' => true,
            'detections' => [],
        ]);
        $this->assertTrue($out['safe']);
    }

    public function test_hybrid_status_safe_authoritative_over_high_detection_scores(): void
    {
        $out = NudeNetService::interpretDetectorJson([
            'status' => 'safe',
            'confidence' => 0.95,
            'detections' => [
                ['class' => 'FACE_FEMALE', 'score' => 0.99],
            ],
        ]);
        $this->assertTrue($out['safe']);
        $this->assertSame(0.95, $out['confidence']);
    }

    public function test_hybrid_status_review_is_not_safe(): void
    {
        $out = NudeNetService::interpretDetectorJson([
            'status' => 'review',
            'confidence' => 0.72,
            'detections' => [
                ['class' => 'FEMALE_BREAST_COVERED', 'score' => 0.72],
            ],
        ]);
        $this->assertFalse($out['safe']);
        $this->assertSame(0.72, $out['confidence']);
    }

    public function test_hybrid_status_unsafe_is_not_safe(): void
    {
        $out = NudeNetService::interpretDetectorJson([
            'status' => 'unsafe',
            'confidence' => 0.88,
            'detections' => [
                ['class' => 'FEMALE_BREAST_EXPOSED', 'score' => 0.88],
            ],
        ]);
        $this->assertFalse($out['safe']);
        $this->assertSame(0.88, $out['confidence']);
    }

    public function test_legacy_api_face_only_high_score_is_safe_even_if_safe_false(): void
    {
        $out = NudeNetService::interpretDetectorJson([
            'safe' => false,
            'confidence' => 0.8494,
            'detections' => [
                ['class' => 'FACE_FEMALE', 'score' => 0.8494],
            ],
            'threshold' => 0.4,
        ]);
        $this->assertTrue($out['safe']);
    }

    public function test_legacy_belly_exposed_moderate_score_queues_review(): void
    {
        $out = NudeNetService::interpretDetectorJson([
            'safe' => true,
            'confidence' => 0.9,
            'detections' => [
                ['class' => 'FACE_FEMALE', 'score' => 0.88],
                ['class' => 'BELLY_EXPOSED', 'score' => 0.42],
            ],
        ]);
        $this->assertFalse($out['safe']);
    }

    public function test_legacy_high_body_below_old_explicit_threshold_still_review(): void
    {
        $out = NudeNetService::interpretDetectorJson([
            'safe' => true,
            'detections' => [
                ['class' => 'FACE_FEMALE', 'score' => 0.9],
                ['class' => 'FEET_EXPOSED', 'score' => 0.54],
            ],
        ]);
        $this->assertFalse($out['safe']);
    }
}
