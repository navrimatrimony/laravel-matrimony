<?php

namespace Tests\Feature\Admin;

use App\Models\City;
use App\Models\CityAlias;
use App\Models\LocationOpenPlaceSuggestion;
use App\Models\Taluka;
use App\Models\User;
use App\Services\Admin\LocationOpenPlaceApprovalService;
use App\Services\Location\LocationNormalizationService;
use App\Services\Location\LocationOpenPlaceSuggestionService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationOpenPlaceApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_as_new_city_creates_city_alias_and_marks_approved(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $user = User::factory()->create();
        app(LocationOpenPlaceSuggestionService::class)->recordOrBumpUsage('Newville Step42 X', $user->id);
        $row = LocationOpenPlaceSuggestion::query()->firstOrFail();
        $taluka = Taluka::query()->where('name', 'Haveli')->firstOrFail();
        $admin = User::factory()->create();

        app(LocationOpenPlaceApprovalService::class)->approveAsNewCity(
            $row->id,
            $admin->id,
            (int) $taluka->id,
            (int) $taluka->district_id,
        );

        $row->refresh();
        $this->assertSame('approved', $row->status);
        $this->assertNotNull($row->resolved_city_id);
        $this->assertSame('manual', $row->match_type);
        $city = City::query()->find($row->resolved_city_id);
        $this->assertNotNull($city);
        $this->assertSame('Newville Step42 X', $city->name);
        $this->assertSame((int) $taluka->id, (int) $city->taluka_id);

        $norm = app(LocationNormalizationService::class)->mergeKeyFromRaw('Newville Step42 X');
        $this->assertDatabaseHas('city_aliases', [
            'city_id' => $city->id,
            'normalized_alias' => $norm,
            'is_active' => 1,
        ]);
    }

    public function test_map_to_existing_city_attaches_alias(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $user = User::factory()->create();
        app(LocationOpenPlaceSuggestionService::class)->recordOrBumpUsage('Punee Typo Unique Z', $user->id);
        $row = LocationOpenPlaceSuggestion::query()->firstOrFail();
        $city = City::query()->where('name', 'Pune City')->firstOrFail();
        $admin = User::factory()->create();

        app(LocationOpenPlaceApprovalService::class)->mapToExistingCity($row->id, $admin->id, (int) $city->id);

        $row->refresh();
        $this->assertSame('approved', $row->status);
        $this->assertSame((int) $city->id, (int) $row->resolved_city_id);
        $this->assertSame('alias', $row->match_type);

        $norm = app(LocationNormalizationService::class)->mergeKeyFromRaw('Punee Typo Unique Z');
        $this->assertDatabaseHas('city_aliases', [
            'city_id' => $city->id,
            'normalized_alias' => $norm,
        ]);
    }

    public function test_auto_candidate_row_can_be_approved(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $taluka = Taluka::query()->where('name', 'Haveli')->firstOrFail();
        $norm = app(LocationNormalizationService::class)->mergeKeyFromRaw('Auto Approve Town');

        $row = LocationOpenPlaceSuggestion::query()->create([
            'raw_input' => 'Auto Approve Town',
            'normalized_input' => $norm,
            'status' => 'auto_candidate',
            'usage_count' => 7,
            'district_id' => $taluka->district_id,
            'suggested_by' => $user->id,
        ]);

        app(LocationOpenPlaceApprovalService::class)->approveAsNewCity(
            (int) $row->id,
            (int) $admin->id,
            (int) $taluka->id,
            (int) $taluka->district_id,
        );

        $row->refresh();
        $this->assertSame('approved', $row->status);
        $this->assertNotNull($row->resolved_city_id);
    }

    public function test_map_throws_when_normalized_alias_active_on_another_city(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $norm = app(LocationNormalizationService::class)->mergeKeyFromRaw('ClashPlace Q');
        $pune = City::query()->where('name', 'Pune City')->firstOrFail();
        $ahm = City::query()->where('name', 'Ahmedabad City')->firstOrFail();

        CityAlias::query()->create([
            'city_id' => $ahm->id,
            'alias_name' => 'ClashPlace Q',
            'normalized_alias' => $norm,
            'is_active' => true,
        ]);

        $row = LocationOpenPlaceSuggestion::query()->create([
            'raw_input' => 'ClashPlace Q',
            'normalized_input' => $norm,
            'status' => 'pending',
            'usage_count' => 1,
            'suggested_by' => $user->id,
        ]);

        $this->expectException(\RuntimeException::class);
        app(LocationOpenPlaceApprovalService::class)->mapToExistingCity($row->id, $admin->id, (int) $pune->id);
    }

    public function test_reject_marks_rejected(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $user = User::factory()->create();
        app(LocationOpenPlaceSuggestionService::class)->recordOrBumpUsage('Reject Me R', $user->id);
        $row = LocationOpenPlaceSuggestion::query()->firstOrFail();
        $admin = User::factory()->create();

        app(LocationOpenPlaceApprovalService::class)->reject($row->id, $admin->id);

        $row->refresh();
        $this->assertSame('rejected', $row->status);
    }

    public function test_merge_adds_usage_and_marks_source_merged(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $user = User::factory()->create();
        $admin = User::factory()->create();

        $a = LocationOpenPlaceSuggestion::query()->create([
            'raw_input' => 'Merge A',
            'normalized_input' => 'merge a',
            'status' => 'pending',
            'usage_count' => 2,
            'suggested_by' => $user->id,
        ]);
        $b = LocationOpenPlaceSuggestion::query()->create([
            'raw_input' => 'Merge B',
            'normalized_input' => 'merge b',
            'status' => 'pending',
            'usage_count' => 3,
            'suggested_by' => $user->id,
        ]);

        app(LocationOpenPlaceApprovalService::class)->mergeInto((int) $a->id, (int) $b->id, $admin->id);

        $a->refresh();
        $b->refresh();
        $this->assertSame('merged', $a->status);
        $this->assertSame((int) $b->id, (int) $a->merged_into_suggestion_id);
        $this->assertSame('pending', $b->status);
        $this->assertSame(5, (int) $b->usage_count);
    }

    public function test_admin_internal_index_requires_admin(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->getJson('/admin/internal/open-place-suggestions')
            ->assertForbidden();
    }

    public function test_admin_internal_index_ok_for_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->getJson('/admin/internal/open-place-suggestions?status=pending')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_admin_internal_index_accepts_auto_candidate_status_filter(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->getJson('/admin/internal/open-place-suggestions?status=auto_candidate')
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
