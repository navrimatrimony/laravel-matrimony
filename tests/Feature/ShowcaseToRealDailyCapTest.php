<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\City;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\ProfileView;
use App\Models\User;
use App\Services\ViewTrackingService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcaseToRealDailyCapTest extends TestCase
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

    /**
     * @return array{0: MatrimonyProfile, 1: MatrimonyProfile}
     */
    private function createShowcaseAndRealPair(): array
    {
        [$maleGid, $femaleGid] = $this->seedGenders();
        $this->seed(MinimalLocationSeeder::class);
        $leaf = (int) City::query()->where('name', 'Pune City')->value('id');

        $showcase = MatrimonyProfile::factory()->create([
            'user_id' => User::factory()->create(['is_admin' => false])->id,
            'gender_id' => $maleGid,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
            'is_showcase' => true,
            'date_of_birth' => now()->subYears(28),
            'location_id' => $leaf,
        ]);
        $showcase->lifecycle_state = 'active';
        $showcase->save();

        $real = MatrimonyProfile::factory()->create([
            'user_id' => User::factory()->create(['is_admin' => false])->id,
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
            'is_showcase' => false,
            'date_of_birth' => now()->subYears(27),
            'location_id' => $leaf,
        ]);
        $real->lifecycle_state = 'active';
        $real->save();

        return [$showcase, $real];
    }

    public function test_default_daily_cap_is_twenty(): void
    {
        $this->assertSame(20, ViewTrackingService::showcaseToRealMaxPerShowcasePerDay());
    }

    public function test_random_view_blocked_when_showcase_at_daily_cap(): void
    {
        [$showcase, $real] = $this->createShowcaseAndRealPair();

        AdminSetting::setValue('showcase_to_real_max_per_showcase_per_day', '1');
        AdminSetting::setValue('showcase_random_view_enabled', '1');
        AdminSetting::setValue('showcase_random_view_batch_per_run', '5');
        AdminSetting::setValue('showcase_random_view_candidate_pool', '80');

        ProfileView::query()->create([
            'viewer_profile_id' => $showcase->id,
            'viewed_profile_id' => $real->id,
        ]);

        $this->assertFalse(ViewTrackingService::canShowcaseCreateToRealViewToday($showcase));

        $this->artisan('showcase:random-views')->assertExitCode(0);

        $this->assertSame(1, ProfileView::query()->count());
    }

    public function test_zero_cap_means_unlimited(): void
    {
        [$showcase, $real] = $this->createShowcaseAndRealPair();

        AdminSetting::setValue('showcase_to_real_max_per_showcase_per_day', '0');

        for ($i = 0; $i < 25; $i++) {
            ProfileView::query()->create([
                'viewer_profile_id' => $showcase->id,
                'viewed_profile_id' => $real->id,
                'created_at' => now()->subMinutes($i),
            ]);
        }

        $this->assertTrue(ViewTrackingService::canShowcaseCreateToRealViewToday($showcase));
    }

    public function test_record_showcase_random_view_respects_cap(): void
    {
        [$showcase, $real] = $this->createShowcaseAndRealPair();
        AdminSetting::setValue('showcase_to_real_max_per_showcase_per_day', '1');

        ProfileView::query()->create([
            'viewer_profile_id' => $showcase->id,
            'viewed_profile_id' => $real->id,
        ]);

        ViewTrackingService::recordShowcaseRandomProfileView($showcase, $real);

        $this->assertSame(1, ProfileView::query()->count());
    }
}
