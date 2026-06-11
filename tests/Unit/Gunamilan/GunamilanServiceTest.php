<?php

namespace Tests\Unit\Gunamilan;

use App\Models\MatrimonyProfile;
use App\Models\ProfileHoroscopeData;
use App\Models\User;
use App\Services\Gunamilan\GunamilanService;
use Database\Seeders\AshtakootaMasterSeeder;
use Database\Seeders\MasterLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GunamilanServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterLookupSeeder::class);
        $this->seed(AshtakootaMasterSeeder::class);
    }

    public function test_gunamilan_service_returns_eight_read_only_sections_from_saved_horoscope_data(): void
    {
        $maleGenderId = DB::table('master_genders')->where('key', 'male')->value('id');
        $femaleGenderId = DB::table('master_genders')->where('key', 'female')->value('id');

        $male = MatrimonyProfile::factory()->create([
            'user_id' => User::factory()->create()->id,
            'gender_id' => $maleGenderId,
            'lifecycle_state' => 'draft',
        ]);
        $female = MatrimonyProfile::factory()->create([
            'user_id' => User::factory()->create()->id,
            'gender_id' => $femaleGenderId,
            'lifecycle_state' => 'draft',
        ]);

        ProfileHoroscopeData::create([
            'profile_id' => $male->id,
            'rashi_id' => DB::table('master_rashis')->where('key', 'mesha')->value('id'),
            'nakshatra_id' => DB::table('master_nakshatras')->where('key', 'ashwini')->value('id'),
            'gan_id' => DB::table('master_gans')->where('key', 'deva')->value('id'),
            'nadi_id' => DB::table('master_nadis')->where('key', 'adya')->value('id'),
            'yoni_id' => DB::table('master_yonis')->where('key', 'horse')->value('id'),
        ]);
        ProfileHoroscopeData::create([
            'profile_id' => $female->id,
            'rashi_id' => DB::table('master_rashis')->where('key', 'mesha')->value('id'),
            'nakshatra_id' => DB::table('master_nakshatras')->where('key', 'ashwini')->value('id'),
            'gan_id' => DB::table('master_gans')->where('key', 'deva')->value('id'),
            'nadi_id' => DB::table('master_nadis')->where('key', 'adya')->value('id'),
            'yoni_id' => DB::table('master_yonis')->where('key', 'horse')->value('id'),
        ]);

        $result = app(GunamilanService::class)->calculate($male, $female);

        $this->assertSame(36.0, $result['max_points']);
        $this->assertCount(8, $result['sections']);
        $this->assertSame([
            'varna',
            'vashya',
            'tara',
            'yoni',
            'graha_maitri',
            'gana',
            'bhakoot',
            'nadi',
        ], collect($result['sections'])->pluck('key')->all());
        $this->assertSame(
            round(collect($result['sections'])->sum(fn (array $section): float => (float) $section['points']), 1),
            $result['total_points']
        );
    }

    public function test_gunamilan_service_reports_missing_horoscope_fields_without_storing_a_result(): void
    {
        $maleGenderId = DB::table('master_genders')->where('key', 'male')->value('id');
        $femaleGenderId = DB::table('master_genders')->where('key', 'female')->value('id');

        $male = MatrimonyProfile::factory()->create([
            'user_id' => User::factory()->create()->id,
            'gender_id' => $maleGenderId,
            'lifecycle_state' => 'draft',
        ]);
        $female = MatrimonyProfile::factory()->create([
            'user_id' => User::factory()->create()->id,
            'gender_id' => $femaleGenderId,
            'lifecycle_state' => 'draft',
        ]);

        ProfileHoroscopeData::create([
            'profile_id' => $male->id,
            'rashi_id' => DB::table('master_rashis')->where('key', 'mesha')->value('id'),
        ]);

        $before = DB::table('profile_horoscope_data')->count();
        $result = app(GunamilanService::class)->calculate($male, $female);

        $this->assertNotEmpty($result['missing_fields']);
        $this->assertSame($before, DB::table('profile_horoscope_data')->count());
    }
}
