<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\ProfileView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcaseRandomViewEngineTest extends TestCase
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

    public function test_command_creates_profile_view_and_notifications_path(): void
    {
        [$maleGid, $femaleGid] = $this->seedGenders();

        $showcaseUser = User::factory()->create(['is_admin' => false]);
        $realUser = User::factory()->create(['is_admin' => false]);

        $showcase = MatrimonyProfile::factory()->create([
            'user_id' => $showcaseUser->id,
            'gender_id' => $maleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'is_showcase' => true,
            'date_of_birth' => now()->subYears(28),
        ]);

        MatrimonyProfile::factory()->create([
            'user_id' => $realUser->id,
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'is_showcase' => false,
            'date_of_birth' => now()->subYears(27),
        ]);

        AdminSetting::setValue('showcase_random_view_enabled', '1');
        AdminSetting::setValue('showcase_random_view_batch_per_run', '10');
        AdminSetting::setValue('showcase_random_view_candidate_pool', '80');

        $this->artisan('showcase:random-views')->assertExitCode(0);

        $this->assertSame(1, ProfileView::query()->count());
        $row = ProfileView::query()->first();
        $this->assertSame((int) $showcase->id, (int) $row->viewer_profile_id);
    }
}
