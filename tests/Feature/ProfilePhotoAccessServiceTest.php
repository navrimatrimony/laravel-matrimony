<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Profile\ProfileCanonicalResidenceService;
use App\Services\ProfilePhotoAccessService;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfilePhotoAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        $this->seed(MasterLookupSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

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

        return $this->createActiveProfileWithResidence($u, [
            'gender_id' => $femaleGid,
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
        $this->createActiveProfileWithResidence($viewer, [
            'gender_id' => $maleGid,
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
        $this->createActiveProfileWithResidence($viewer, [
            'gender_id' => $maleGid,
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

    /**
     * @param  array<string, mixed>  $factoryAttributes
     */
    private function createActiveProfileWithResidence(User $user, array $factoryAttributes = []): MatrimonyProfile
    {
        $profile = MatrimonyProfile::factory()->for($user)->create(array_merge([
            'lifecycle_state' => 'draft',
        ], $factoryAttributes));

        $leafId = (int) City::query()->where('name', 'Pune City')->firstOrFail()->id;
        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $leafId]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $leafId, null, true, false);
        }

        $profile->update(['lifecycle_state' => 'active']);

        return $profile->fresh();
    }
}
