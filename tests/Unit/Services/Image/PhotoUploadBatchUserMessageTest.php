<?php

namespace Tests\Unit\Services\Image;

use App\Services\Image\PhotoUploadBatchUserMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhotoUploadBatchUserMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_pending_is_danger_and_short(): void
    {
        app()->setLocale('en');

        $out = PhotoUploadBatchUserMessage::forUploadResponse(1, [[
            'approved_status' => 'pending',
            'moderation_scan_json' => [
                'api_status' => 'unsafe',
                'pipeline_safe' => false,
            ],
        ]]);

        $this->assertSame('danger', $out['tone']);
        $this->assertStringContainsString('Pending', $out['message']);
        $this->assertStringContainsString('sensitive', strtolower($out['message']));
        $this->assertLessThan(120, strlen($out['message']));
    }

    public function test_safe_pending_with_stage_one_is_danger_and_short(): void
    {
        app()->setLocale('en');
        \App\Models\AdminSetting::query()->updateOrCreate(
            ['key' => 'photo_approval_required'],
            ['value' => '1']
        );

        $out = PhotoUploadBatchUserMessage::forUploadResponse(1, [[
            'approved_status' => 'pending',
            'moderation_scan_json' => [
                'api_status' => 'safe',
                'pipeline_safe' => true,
            ],
        ]]);

        $this->assertSame('danger', $out['tone']);
        $this->assertStringContainsString('admin approval', strtolower($out['message']));
        $this->assertLessThan(100, strlen($out['message']));
    }

    public function test_all_approved_is_success_tone(): void
    {
        app()->setLocale('en');

        $out = PhotoUploadBatchUserMessage::forUploadResponse(2, [
            ['approved_status' => 'approved', 'moderation_scan_json' => null],
            ['approved_status' => 'approved', 'moderation_scan_json' => null],
        ]);

        $this->assertSame('success', $out['tone']);
        $this->assertStringContainsString('live', strtolower($out['message']));
    }

    public function test_marathi_sensitive_line(): void
    {
        app()->setLocale('mr');

        $out = PhotoUploadBatchUserMessage::forUploadResponse(1, [[
            'approved_status' => 'pending',
            'moderation_scan_json' => [
                'api_status' => 'unsafe',
                'pipeline_safe' => false,
            ],
        ]]);

        $this->assertSame('danger', $out['tone']);
        $this->assertStringContainsString('प्रलंबित', $out['message']);
    }
}
