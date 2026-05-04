<?php

namespace Tests\Feature;

use App\Models\MatrimonyProfile;
use App\Models\ProfileAddress;
use App\Models\Taluka;
use App\Models\User;
use App\Models\Village;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FillVillagesMarathiSarvamCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_updates_village_name_mr_from_sarvam_batch(): void
    {
        $geo = (new Village)->getTable();
        if (! Schema::hasTable($geo) || ! Schema::hasColumn($geo, 'name_mr')) {
            $this->markTestSkipped('Geographic SSOT name_mr not available.');
        }

        $this->seed(MasterLookupSeeder::class);
        $this->seed(MinimalLocationSeeder::class);

        Config::set('services.sarvam.subscription_key', 'test-key');
        Config::set('intake.sarvam_structured.chat_completions_url', 'https://api.sarvam.ai/v1/chat/completions');

        $taluka = Taluka::query()->where('name', 'Haveli')->firstOrFail();

        $village = Village::query()->create([
            'taluka_id' => $taluka->id,
            'name' => 'Vita',
            'name_en' => 'Vita',
            'name_mr' => null,
            'is_active' => true,
        ]);

        $addressTypeId = (int) DB::table('master_address_types')->value('id');
        $this->assertGreaterThan(0, $addressTypeId);

        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        ProfileAddress::query()->create([
            'profile_id' => $profile->id,
            'address_type_id' => $addressTypeId,
            'village_id' => $village->id,
            'country_id' => null,
            'state_id' => null,
            'district_id' => null,
            'taluka_id' => null,
            'city_id' => null,
            'postal_code' => null,
        ]);

        $vid = (int) $village->id;
        Http::fake([
            'https://api.sarvam.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([(string) $vid => 'विटा'], JSON_UNESCAPED_UNICODE)]],
                ],
            ], 200),
        ]);

        $this->artisan('villages:fill-marathi-sarvam', ['--batch-size' => 5])
            ->assertSuccessful();

        $village->refresh();
        $this->assertSame('विटा', $village->name_mr);
    }
}
