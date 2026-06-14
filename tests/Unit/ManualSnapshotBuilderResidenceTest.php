<?php

namespace Tests\Unit;

use App\Models\MatrimonyProfile;
use App\Services\ManualSnapshotBuilderService;
use App\Services\MutationService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class ManualSnapshotBuilderResidenceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function raw_core_input_reads_snapshot_core_bracket_names(): void
    {
        $req = Request::create('/', 'POST', [
            'snapshot' => [
                'core' => [
                    'address_line' => 'सोसायटी, रस्ता',
                    'location_id' => '15',
                ],
            ],
        ]);

        $svc = app(ManualSnapshotBuilderService::class);
        $m = new ReflectionMethod(ManualSnapshotBuilderService::class, 'rawCoreInput');
        $m->setAccessible(true);

        $this->assertSame('सोसायटी, रस्ता', $m->invoke($svc, $req, 'address_line'));
        $this->assertSame('15', $m->invoke($svc, $req, 'location_id'));
    }

    #[Test]
    public function raw_core_input_prefers_snapshot_core_over_flat(): void
    {
        $req = Request::create('/', 'POST', [
            'address_line' => 'flat old',
            'snapshot' => [
                'core' => [
                    'address_line' => 'from snapshot',
                ],
            ],
        ]);

        $svc = app(ManualSnapshotBuilderService::class);
        $m = new ReflectionMethod(ManualSnapshotBuilderService::class, 'rawCoreInput');
        $m->setAccessible(true);

        $this->assertSame('from snapshot', $m->invoke($svc, $req, 'address_line'));
    }

    #[Test]
    public function full_snapshot_maps_and_persists_self_address_rows(): void
    {
        $this->seed(MinimalLocationSeeder::class);

        $profile = MatrimonyProfile::factory()->create(['lifecycle_state' => 'draft']);
        $locationId = DB::table('addresses')->where('hierarchy', 'village')->where('tag', 'city')->value('id');
        $currentTypeId = DB::table('master_address_types')->where('key', 'current')->value('id');
        $request = Request::create('/', 'POST', [
            'full_name' => 'Ashwini',
            'gender_id' => $profile->gender_id,
            'self_addresses' => [
                [
                    'address_type_key' => 'current',
                    'address_line' => '',
                    'location_id' => (string) $locationId,
                    'location_input' => '',
                ],
            ],
        ]);

        $snapshot = app(ManualSnapshotBuilderService::class)->buildFullManualSnapshot($request, $profile);

        $this->assertSame('self', $snapshot['addresses'][0]['address_scope'] ?? null);
        $this->assertSame('current', $snapshot['addresses'][0]['address_type'] ?? null);
        $this->assertSame((int) $locationId, $snapshot['addresses'][0]['location_id'] ?? null);

        app(MutationService::class)->applyManualSnapshot(
            $profile,
            ['core' => [], 'addresses' => $snapshot['addresses']],
            $profile->user_id,
            'admin',
        );

        $this->assertDatabaseHas('profile_addresses', [
            'profile_id' => $profile->id,
            'address_scope' => 'self',
            'address_type_id' => $currentTypeId,
            'location_id' => $locationId,
        ]);
    }

    #[Test]
    public function full_snapshot_persists_selected_birth_place(): void
    {
        $this->seed(MinimalLocationSeeder::class);

        $profile = MatrimonyProfile::factory()->create(['lifecycle_state' => 'draft']);
        $birthCityId = DB::table('addresses')->where('hierarchy', 'village')->where('tag', 'city')->value('id');
        $request = Request::create('/', 'POST', [
            'full_name' => 'Ashwini',
            'gender_id' => $profile->gender_id,
            'birth_city_id' => (string) $birthCityId,
        ]);

        $snapshot = app(ManualSnapshotBuilderService::class)->buildFullManualSnapshot($request, $profile);

        $this->assertSame((int) $birthCityId, $snapshot['birth_place']['city_id'] ?? null);

        app(MutationService::class)->applyManualSnapshot(
            $profile,
            ['core' => [], 'birth_place' => $snapshot['birth_place']],
            $profile->user_id,
            'admin',
        );

        $this->assertDatabaseHas('matrimony_profiles', [
            'id' => $profile->id,
            'birth_city_id' => $birthCityId,
        ]);
    }
}
