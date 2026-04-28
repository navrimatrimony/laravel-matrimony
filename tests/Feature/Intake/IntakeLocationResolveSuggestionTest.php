<?php

namespace Tests\Feature\Intake;

use App\Http\Middleware\EnforceCardOnboarding;
use App\Models\BiodataIntake;
use App\Models\City;
use App\Models\User;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeLocationResolveSuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_resolve_birth_place_into_approval_snapshot(): void
    {
        $this->withoutMiddleware(EnforceCardOnboarding::class);
        $this->seed(MinimalLocationSeeder::class);
        $user = User::factory()->create();
        $city = City::query()->where('name', 'Pune City')->firstOrFail();

        $intake = BiodataIntake::query()->create([
            'uploaded_by' => $user->id,
            'file_path' => 'intakes/test.txt',
            'original_filename' => 'test.txt',
            'file_type' => 'txt',
            'raw_ocr_text' => 'Birth place Shirur',
            'intake_status' => 'uploaded',
            'parse_status' => 'parsed',
            'parsed_json' => [
                'core' => ['birth_place' => 'Shirur'],
                'addresses' => [],
            ],
            'approved_by_user' => false,
            'intake_locked' => false,
        ]);

        $this->actingAs($user)
            ->patchJson(route('intake.resolve-location', $intake), [
                'field' => 'birth_place',
                'city_id' => $city->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $intake->refresh();
        $snapshot = $intake->approval_snapshot_json;
        $this->assertIsArray($snapshot);
        $this->assertSame($city->id, (int) ($snapshot['core']['birth_city_id'] ?? 0));
    }

    public function test_admin_can_resolve_address_row_from_admin_endpoint(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $city = City::query()->where('name', 'Pune City')->firstOrFail();

        $intake = BiodataIntake::query()->create([
            'uploaded_by' => $owner->id,
            'file_path' => 'intakes/test-admin.txt',
            'original_filename' => 'test-admin.txt',
            'file_type' => 'txt',
            'raw_ocr_text' => 'Address Shirur',
            'intake_status' => 'uploaded',
            'parse_status' => 'parsed',
            'parsed_json' => [
                'core' => [],
                'addresses' => [
                    ['address_line' => 'Shirur'],
                ],
            ],
            'approved_by_user' => false,
            'intake_locked' => false,
        ]);

        $this->actingAs($admin)
            ->patchJson(route('admin.biodata-intakes.resolve-location', $intake), [
                'field' => 'addresses.0',
                'city_id' => $city->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $intake->refresh();
        $snapshot = $intake->approval_snapshot_json;
        $this->assertIsArray($snapshot);
        $this->assertSame($city->id, (int) ($snapshot['addresses'][0]['city_id'] ?? 0));
    }

    public function test_resolve_rejects_when_field_already_resolved(): void
    {
        $this->withoutMiddleware(EnforceCardOnboarding::class);
        $this->seed(MinimalLocationSeeder::class);
        $user = User::factory()->create();
        $city = City::query()->where('name', 'Pune City')->firstOrFail();

        $intake = BiodataIntake::query()->create([
            'uploaded_by' => $user->id,
            'file_path' => 'intakes/test-already-resolved.txt',
            'original_filename' => 'test-already-resolved.txt',
            'file_type' => 'txt',
            'raw_ocr_text' => 'Birth place Shirur',
            'intake_status' => 'uploaded',
            'parse_status' => 'parsed',
            'approval_snapshot_json' => [
                'core' => [
                    'birth_place' => 'Shirur',
                    'birth_city_id' => $city->id,
                ],
            ],
            'approved_by_user' => false,
            'intake_locked' => false,
        ]);

        $this->actingAs($user)
            ->patchJson(route('intake.resolve-location', $intake), [
                'field' => 'birth_place',
                'city_id' => $city->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
