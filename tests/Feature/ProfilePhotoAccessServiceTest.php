<?php

namespace Tests\Feature;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\ProfilePhotoAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfilePhotoAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedGenders(): array
    {
        $male = MasterGender::query()->firstOrCreate(
            ['key' => 'male'],
            ['label' => 'Male', 'is_active' => true]
        );
        $female = MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true]
        );

        return [(int) $male->id, (int) $female->id];
    }

    private function makeSubject(int $femaleGid, string $name, string $photoPath = 's1.jpg'): MatrimonyProfile
    {
        $u = User::factory()->create();

        return MatrimonyProfile::factory()->for($u)->create([
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'full_name' => $name,
            'profile_photo' => $photoPath,
            'photo_approved' => true,
            'visibility_override' => true,
        ]);
    }

    #[Test]
    public function free_user_without_own_photo_gets_sixth_new_profile_fully_blurred(): void
    {
        config(['photo_access.max_profiles_per_day_without_own_photo' => 5]);
        [$maleGid, $femaleGid] = $this->seedGenders();

        $viewer = User::factory()->create();
        MatrimonyProfile::factory()->for($viewer)->create([
            'gender_id' => $maleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'full_name' => 'Viewer No Photo',
            'profile_photo' => '',
            'photo_approved' => true,
            'visibility_override' => true,
        ]);

        $svc = app(ProfilePhotoAccessService::class);

        for ($i = 1; $i <= 5; $i++) {
            $subject = $this->makeSubject($femaleGid, "Subject {$i}", "p{$i}.jpg");
            $gallery = collect();
            $pres = $svc->buildAlbumPresentation($viewer->fresh(), $subject, false, $gallery);
            $this->assertNotEmpty($pres['slots'], "subject {$i}");
            $this->assertFalse($pres['slots'][0]['blur'], "first slot clear for subject {$i}");
            if (count($pres['slots']) > 1) {
                $this->assertTrue($pres['slots'][1]['blur']);
            }
        }

        $sixth = $this->makeSubject($femaleGid, 'Subject Six', 'p6.jpg');
        $presSix = $svc->buildAlbumPresentation($viewer->fresh(), $sixth, false, collect());
        $this->assertNotEmpty($presSix['slots']);
        foreach ($presSix['slots'] as $slot) {
            $this->assertTrue($slot['blur'], 'sixth profile all blurred');
        }
        $this->assertSame('profile.photos_upload_to_unlock_more', $presSix['message_key']);
    }

    #[Test]
    public function free_user_with_own_photo_sees_first_clear_and_upgrade_message_when_album_has_multiple(): void
    {
        [$maleGid, $femaleGid] = $this->seedGenders();

        $viewer = User::factory()->create();
        MatrimonyProfile::factory()->for($viewer)->create([
            'gender_id' => $maleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'full_name' => 'Viewer With Photo',
            'profile_photo' => 'me.jpg',
            'photo_approved' => true,
            'visibility_override' => true,
        ]);

        $subject = $this->makeSubject($femaleGid, 'Target', 't1.jpg');
        \App\Models\ProfilePhoto::query()->create([
            'profile_id' => $subject->id,
            'file_path' => 't2.jpg',
            'is_primary' => false,
            'sort_order' => 1,
            'uploaded_via' => 'web',
            'approved_status' => 'approved',
            'watermark_detected' => false,
        ]);

        $gallery = \App\Models\ProfilePhoto::query()
            ->where('profile_id', $subject->id)
            ->where('is_primary', false)
            ->where('approved_status', 'approved')
            ->get();

        $pres = app(ProfilePhotoAccessService::class)->buildAlbumPresentation(
            $viewer->fresh(),
            $subject->fresh(),
            false,
            $gallery
        );

        $this->assertFalse($pres['slots'][0]['blur']);
        $this->assertTrue($pres['slots'][1]['blur']);
        $this->assertSame('profile.photos_upgrade_to_view_all', $pres['message_key']);
    }
}
