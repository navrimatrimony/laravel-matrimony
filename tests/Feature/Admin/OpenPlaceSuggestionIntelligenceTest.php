<?php

namespace Tests\Feature\Admin;

use App\Models\City;
use App\Models\LocationSuggestionApprovalPattern;
use App\Models\LocationOpenPlaceSuggestion;
use App\Models\User;
use App\Services\Admin\LocationOpenPlaceApprovalService;
use App\Services\Location\LocationNormalizationService;
use App\Services\Location\LocationOpenPlaceSuggestionService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenPlaceSuggestionIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_suggestion_gets_analysis_json_with_duplicate_candidates(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $user = User::factory()->create();

        app(LocationOpenPlaceSuggestionService::class)->recordOrBumpUsage(
            'Pune City near duplicate text xyzzy',
            $user->id,
        );

        $row = LocationOpenPlaceSuggestion::query()->firstOrFail();
        $this->assertIsArray($row->analysis_json);
        $this->assertArrayHasKey('duplicate_candidates', $row->analysis_json);
        $this->assertNotEmpty($row->analysis_json['duplicate_candidates']);
        $cityHit = collect($row->analysis_json['duplicate_candidates'])->firstWhere('kind', 'city');
        $this->assertNotNull($cityHit);
        $this->assertSame((int) City::query()->where('name', 'Pune City')->first()->id, (int) $cityHit['id']);
    }

    public function test_learned_pattern_boosts_second_suggestion(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $norm = app(LocationNormalizationService::class)->mergeKeyFromRaw('Learned Typo Place Z');

        LocationSuggestionApprovalPattern::query()->create([
            'normalized_input' => $norm,
            'resolved_city_id' => (int) City::query()->where('name', 'Pune City')->first()->id,
            'confirmation_count' => 4,
            'last_confirmed_at' => now(),
        ]);

        app(LocationOpenPlaceSuggestionService::class)->recordOrBumpUsage('Learned Typo Place Z', $user->id);

        $row = LocationOpenPlaceSuggestion::query()->where('normalized_input', $norm)->firstOrFail();
        $this->assertSame('map', $row->analysis_json['recommended_action']);
        $this->assertSame(
            (int) City::query()->where('name', 'Pune City')->first()->id,
            (int) $row->analysis_json['recommended_city_id']
        );
        $this->assertSame('learned_pattern', $row->analysis_json['confidence_basis']);
    }

    public function test_map_recommended_approves_using_analysis(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $norm = app(LocationNormalizationService::class)->mergeKeyFromRaw('Quick Map Typo Q');

        LocationSuggestionApprovalPattern::query()->create([
            'normalized_input' => $norm,
            'resolved_city_id' => (int) City::query()->where('name', 'Pune City')->first()->id,
            'confirmation_count' => 2,
            'last_confirmed_at' => now(),
        ]);

        app(LocationOpenPlaceSuggestionService::class)->recordOrBumpUsage('Quick Map Typo Q', $user->id);
        $row = LocationOpenPlaceSuggestion::query()->where('normalized_input', $norm)->firstOrFail();

        app(LocationOpenPlaceApprovalService::class)->mapUsingRecommendation((int) $row->id, (int) $admin->id);

        $row->refresh();
        $this->assertSame('approved', $row->status);
        $this->assertSame((int) City::query()->where('name', 'Pune City')->first()->id, (int) $row->resolved_city_id);

        $pattern = LocationSuggestionApprovalPattern::query()->where('normalized_input', $norm)->first();
        $this->assertSame(3, (int) $pattern->confirmation_count);
    }

    public function test_admin_internal_index_sorted_by_usage_then_created_at(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $user = User::factory()->create();
        $svc = app(LocationOpenPlaceSuggestionService::class);

        $svc->recordOrBumpUsage('Older Low Usage', $user->id);
        $svc->recordOrBumpUsage('High Usage A', $user->id);
        $svc->recordOrBumpUsage('High Usage A', $user->id);
        $svc->recordOrBumpUsage('High Usage A', $user->id);

        $normHigh = app(LocationNormalizationService::class)->mergeKeyFromRaw('High Usage A');

        $admin = User::factory()->create(['is_admin' => true]);
        $res = $this->actingAs($admin)->getJson('/admin/internal/open-place-suggestions?status=pending&per_page=10');
        $res->assertOk();
        $items = $res->json('data.data');
        $this->assertGreaterThanOrEqual(2, count($items));
        $this->assertSame($normHigh, $items[0]['normalized_input']);
    }
}
