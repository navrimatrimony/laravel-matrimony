<?php

namespace Tests\Feature\Admin;

use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Models\UserModerationStat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class UserModerationStatsAndAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_photo_create_increments_user_moderation_stat_uploads(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        ProfilePhoto::query()->create([
            'profile_id' => $profile->id,
            'file_path' => 'x.webp',
            'is_primary' => true,
            'uploaded_via' => 'user_web',
            'approved_status' => 'pending',
            'watermark_detected' => false,
        ]);

        $stat = UserModerationStat::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($stat);
        $this->assertSame(1, (int) $stat->total_uploads);
    }

    public function test_moderation_index_shows_flagged_badge_when_user_flagged(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        UserModerationStat::query()->create([
            'user_id' => $user->id,
            'total_uploads' => 1,
            'total_approved' => 0,
            'total_rejected' => 5,
            'total_review' => 0,
            'last_upload_at' => now(),
            'risk_score' => 10.0,
            'is_flagged' => true,
        ]);

        ProfilePhoto::query()->create([
            'profile_id' => $profile->id,
            'file_path' => 'p.webp',
            'is_primary' => true,
            'uploaded_via' => 'user_web',
            'approved_status' => 'pending',
            'watermark_detected' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.photo-moderation.index'))
            ->assertOk()
            ->assertSee('FLAGGED USER', false);
    }

    public function test_flagged_users_filter_shows_only_flagged_owner_photos(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $uFlag = User::factory()->create();
        $pFlag = MatrimonyProfile::factory()->create(['user_id' => $uFlag->id]);
        UserModerationStat::query()->create([
            'user_id' => $uFlag->id,
            'total_uploads' => 1,
            'total_approved' => 0,
            'total_rejected' => 5,
            'total_review' => 0,
            'last_upload_at' => now(),
            'risk_score' => 10.0,
            'is_flagged' => true,
        ]);
        $photoFlagged = ProfilePhoto::query()->create([
            'profile_id' => $pFlag->id,
            'file_path' => 'a.webp',
            'is_primary' => true,
            'uploaded_via' => 'user_web',
            'approved_status' => 'pending',
            'watermark_detected' => false,
        ]);

        $uOk = User::factory()->create();
        $pOk = MatrimonyProfile::factory()->create(['user_id' => $uOk->id]);
        $photoOther = ProfilePhoto::query()->create([
            'profile_id' => $pOk->id,
            'file_path' => 'b.webp',
            'is_primary' => true,
            'uploaded_via' => 'user_web',
            'approved_status' => 'pending',
            'watermark_detected' => false,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.photo-moderation.index', ['flagged_users' => '1', 'include_approved' => '1']));

        $response->assertOk();
        $response->assertSee('name="photo_ids[]" value="'.$photoFlagged->id.'"', false);
        $response->assertDontSee('name="photo_ids[]" value="'.$photoOther->id.'"', false);
    }

    public function test_admin_suspend_uploads_sets_flag_on_user(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create(['photo_uploads_suspended' => false]);

        $this->actingAs($admin)
            ->post(route('admin.photo-moderation.suspend-user-uploads', $member))
            ->assertRedirect();

        $member->refresh();
        $this->assertTrue((bool) $member->photo_uploads_suspended);
    }

    public function test_suspended_user_cannot_upload_photo_via_web(): void
    {
        $user = User::factory()->create(['photo_uploads_suspended' => true]);
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        $file = UploadedFile::fake()->image('blocked.jpg', 400, 400);

        $this->actingAs($user)
            ->post(route('matrimony.profile.upload-photo'), [
                'profile_photo' => $file,
            ])
            ->assertSessionHas('error');
    }
}
