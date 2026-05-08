<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataEngineStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_fetch_engine_status_json(): void
    {
        $this->getJson(route('admin.data-engine.status'))
            ->assertUnauthorized();
    }

    public function test_admin_receives_monitor_json_payload(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->getJson(route('admin.data-engine.status'));

        $response->assertOk()
            ->assertJsonStructure([
                'powered',
                'running',
                'mode',
                'queue_mode',
                'last_run',
                'current_run',
                'health' => [
                    'state',
                ],
                'lock_active',
                'latest_runs',
            ]);
    }
}
