<?php

namespace Tests\Unit;

use App\Models\AdminSetting;
use App\Services\Image\AiModerationService;
use App\Services\Image\ImageModerationService;
use App\Services\Image\NudeNetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ImageModerationSecondaryAiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_when_verify_off_nudenet_safe_returns_approved_without_calling_ai(): void
    {
        AdminSetting::setValue('photo_approval_required', '0');
        AdminSetting::setValue('photo_verify_safe_with_secondary_ai', '0');

        $nudenet = Mockery::mock(NudeNetService::class);
        $nudenet->shouldReceive('detect')->once()->andReturn([
            'safe' => true,
            'confidence' => 0.99,
            'raw' => ['safe' => true],
        ]);

        $ai = Mockery::mock(AiModerationService::class);
        $ai->shouldNotReceive('moderate');

        $path = storage_path('app/tmp/moderation_secondary_ai_unit.jpg');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, 'x');

        $svc = new ImageModerationService($nudenet, $ai);
        $out = $svc->moderateProfilePhoto($path);
        @unlink($path);

        $this->assertSame('approved', $out['status']);
    }

    public function test_when_verify_on_secondary_ai_rejects_and_stage_one_off_yields_rejected(): void
    {
        AdminSetting::setValue('photo_approval_required', '0');
        AdminSetting::setValue('photo_verify_safe_with_secondary_ai', '1');
        AdminSetting::setValue('photo_ai_provider', 'openai');

        $nudenet = Mockery::mock(NudeNetService::class);
        $nudenet->shouldReceive('detect')->once()->andReturn([
            'safe' => true,
            'confidence' => 0.99,
            'raw' => ['safe' => true],
        ]);

        $ai = Mockery::mock(AiModerationService::class);
        $ai->shouldReceive('moderate')->once()->andReturn([
            'approved' => false,
            'reason' => 'Flagged sexual content',
            'raw' => [],
        ]);

        $path = storage_path('app/tmp/moderation_secondary_ai_unit2.jpg');
        file_put_contents($path, 'x');

        $svc = new ImageModerationService($nudenet, $ai);
        $out = $svc->moderateProfilePhoto($path);
        @unlink($path);

        $this->assertSame('rejected', $out['status']);
    }

    public function test_when_verify_on_ai_unavailable_yields_pending_manual(): void
    {
        AdminSetting::setValue('photo_approval_required', '0');
        AdminSetting::setValue('photo_verify_safe_with_secondary_ai', '1');
        AdminSetting::setValue('photo_ai_provider', 'openai');

        $nudenet = Mockery::mock(NudeNetService::class);
        $nudenet->shouldReceive('detect')->once()->andReturn([
            'safe' => true,
            'confidence' => 0.99,
            'raw' => ['safe' => true],
        ]);

        $ai = Mockery::mock(AiModerationService::class);
        $ai->shouldReceive('moderate')->once()->andReturn([
            'approved' => false,
            'reason' => 'OpenAI API key not configured.',
            'raw' => [],
        ]);

        $path = storage_path('app/tmp/moderation_secondary_ai_unit3.jpg');
        file_put_contents($path, 'x');

        $svc = new ImageModerationService($nudenet, $ai);
        $out = $svc->moderateProfilePhoto($path);
        @unlink($path);

        $this->assertSame('pending_manual', $out['status']);
    }
}
