<?php

namespace Tests\Feature\Admin;

use App\Models\Location;
use App\Models\Pincode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanonicalLocationAdminTest extends TestCase
{
    use RefreshDatabase;

    private function seedTwoLocations(): array
    {
        $slugA = 'loc-a-'.Str::random(6);
        $slugB = 'loc-b-'.Str::random(6);

        $state = Location::query()->create([
            'name' => 'Admin Test State '.Str::random(4),
            'slug' => 'adm-st-'.Str::random(6),
            'type' => 'state',
            'parent_id' => null,
            'is_active' => true,
        ]);
        $district = Location::query()->create([
            'name' => 'Admin Test Dist '.Str::random(4),
            'slug' => 'adm-di-'.Str::random(6),
            'type' => 'district',
            'parent_id' => $state->id,
            'is_active' => true,
        ]);

        $a = Location::query()->create([
            'name' => 'Dup Test A',
            'slug' => $slugA,
            'type' => 'city',
            'parent_id' => $district->id,
            'is_active' => true,
        ]);
        $b = Location::query()->create([
            'name' => 'Dup Test B',
            'slug' => $slugB,
            'type' => 'city',
            'parent_id' => $district->id,
            'is_active' => true,
        ]);

        return [$a, $b];
    }

    public function test_admin_can_patch_location(): void
    {
        [$a] = $this->seedTwoLocations();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patchJson('/admin/internal/locations/'.$a->id, [
            'name' => 'Renamed City',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Renamed City');

        $this->assertSame('Renamed City', Location::query()->find($a->id)?->name);
    }

    public function test_merge_rewrites_pincode_place_and_deletes_source(): void
    {
        [$source, $target] = $this->seedTwoLocations();

        $pin = Pincode::query()->create([
            'pincode' => '4110'.random_int(10, 99),
            'place_id' => $source->id,
            'is_primary' => false,
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->postJson('/admin/internal/locations/'.$source->id.'/merge', [
            'target_location_id' => $target->id,
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertNull(Location::query()->find($source->id));
        $this->assertSame((int) $target->id, (int) $pin->fresh()->place_id);
    }

    public function test_possible_duplicates_returns_json(): void
    {
        [$a] = $this->seedTwoLocations();
        $district = $a->parent_id ? Location::query()->whereKey($a->parent_id)->first() : null;
        if ($district !== null) {
            Location::query()->create([
                'name' => 'Dup Test A Variant',
                'slug' => 'loc-v-'.Str::random(6),
                'type' => 'city',
                'parent_id' => $district->id,
                'is_active' => true,
            ]);
        }

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->getJson('/admin/internal/locations/'.$a->id.'/possible-duplicates')
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
