<?php

namespace Tests\Feature\Location;

use App\Models\User;
use App\Services\Location\LocationOpenPlaceSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationOpenPlaceSuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_or_bump_increments_usage_for_same_normalized_key(): void
    {
        $user = User::factory()->create();
        $svc = app(LocationOpenPlaceSuggestionService::class);

        $a = $svc->recordOrBumpUsage('  Wakad  ', $user->id);
        $b = $svc->recordOrBumpUsage('wakad', $user->id);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(2, $b->fresh()->usage_count);
        $this->assertSame('wakad', $b->normalized_input);
    }

    public function test_eligible_for_auto_promotion_when_usage_threshold_met(): void
    {
        $user = User::factory()->create();
        $svc = app(LocationOpenPlaceSuggestionService::class);

        $svc->recordOrBumpUsage('Karvenagar', $user->id);
        for ($i = 0; $i < 4; $i++) {
            $svc->recordOrBumpUsage('Karvenagar', $user->id);
        }

        $row = \App\Models\LocationOpenPlaceSuggestion::query()->where('normalized_input', 'karvenagar')->firstOrFail();
        $this->assertSame(5, $row->usage_count);
        $this->assertTrue($row->eligibleForAutoPromotion(LocationOpenPlaceSuggestionService::AUTO_APPROVE_USAGE_THRESHOLD));
    }

    public function test_punctuation_and_whitespace_variants_merge_to_one_row(): void
    {
        $user = User::factory()->create();
        $svc = app(LocationOpenPlaceSuggestionService::class);

        $a = $svc->recordOrBumpUsage('Wakad, Pune', $user->id);
        $b = $svc->recordOrBumpUsage('wakad  pune', $user->id);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(2, $b->fresh()->usage_count);
    }
}
