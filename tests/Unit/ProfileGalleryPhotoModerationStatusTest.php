<?php

namespace Tests\Unit;

use App\Models\AdminSetting;
use App\Services\Image\ProfileGalleryPhotoModerationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileGalleryPhotoModerationStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_automated_approved_respects_stage_one_toggle(): void
    {
        AdminSetting::setValue('photo_approval_required', '0');
        $this->assertSame('approved', ProfileGalleryPhotoModerationStatus::fromModerationResult(['status' => 'approved']));

        AdminSetting::setValue('photo_approval_required', '1');
        $this->assertSame('pending', ProfileGalleryPhotoModerationStatus::fromModerationResult(['status' => 'approved']));
    }

    public function test_flagged_paths_never_yield_approved_status_even_when_stage_one_off(): void
    {
        AdminSetting::setValue('photo_approval_required', '0');
        $this->assertSame('pending', ProfileGalleryPhotoModerationStatus::fromModerationResult(['status' => 'pending_manual']));
        $this->assertSame('rejected', ProfileGalleryPhotoModerationStatus::fromModerationResult(['status' => 'rejected']));
    }
}
