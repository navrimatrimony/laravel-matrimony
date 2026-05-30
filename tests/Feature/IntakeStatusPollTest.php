<?php

namespace Tests\Feature;

use App\Models\BiodataIntake;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeStatusPollTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_page_renders_poll_url_when_processing(): void
    {
        $user = User::factory()->create();
        $intake = BiodataIntake::create([
            'raw_ocr_text' => 'sample',
            'uploaded_by' => $user->id,
            'parse_status' => 'pending',
            'intake_status' => 'uploaded',
        ]);

        $pollUrl = route('api.intake-status', $intake);

        $this->actingAs($user)
            ->get(route('intake.status', $intake))
            ->assertOk()
            ->assertSee($pollUrl, false);
    }

    public function test_poll_route_returns_current_intake_fields(): void
    {
        $user = User::factory()->create();
        $intake = BiodataIntake::create([
            'raw_ocr_text' => 'sample',
            'uploaded_by' => $user->id,
            'parse_status' => 'pending',
            'intake_status' => 'uploaded',
            'approved_by_user' => false,
        ]);

        $this->actingAs($user)
            ->getJson(route('api.intake-status', $intake))
            ->assertOk()
            ->assertJson([
                'parse_status' => 'pending',
                'approved_by_user' => false,
                'intake_status' => 'uploaded',
            ])
            ->assertJsonStructure(['last_error', 'queue_async']);
    }

    public function test_poll_route_forbidden_for_other_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $intake = BiodataIntake::create([
            'raw_ocr_text' => 'sample',
            'uploaded_by' => $owner->id,
            'parse_status' => 'pending',
        ]);

        $this->actingAs($other)
            ->getJson(route('api.intake-status', $intake))
            ->assertForbidden();
    }
}
