<?php

namespace Tests\Feature\Intake;

use App\Models\MatrimonyProfile;
use App\Services\Intake\IntakePipelineService;
use App\Services\MutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntakeParentAddressPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_addresses_are_canonicalized_and_persisted_in_profile_addresses(): void
    {
        $permanentTypeId = (int) DB::table('master_address_types')
            ->where('key', 'permanent')
            ->value('id');

        $profile = MatrimonyProfile::factory()->create(['lifecycle_state' => 'draft']);
        $snapshot = app(IntakePipelineService::class)->normalizeApprovedSnapshot([
            'core' => [],
            'addresses' => [
                [
                    'address_scope' => 'self',
                    'address_type' => 'current',
                    'address_line' => 'Existing self address',
                ],
            ],
            'parents_addresses' => [
                [
                    'type' => 'parents',
                    'address_type_key' => 'permanent',
                    'address_line' => 'मु. पो. भूड, ता. खानापूर, जि. सांगली',
                    'raw' => 'पत्ता: मु. पो. भूड, ता. खानापूर, जि. सांगली',
                ],
            ],
        ], $profile->user_id);

        $this->assertSame([], $snapshot['parents_addresses'] ?? []);
        $this->assertCount(2, $snapshot['addresses']);
        $this->assertSame('parents', $snapshot['addresses'][1]['address_scope']);
        $this->assertSame('permanent', $snapshot['addresses'][1]['address_type']);

        app(MutationService::class)->applyManualSnapshot(
            $profile,
            ['core' => [], 'addresses' => [$snapshot['addresses'][1]]],
            $profile->user_id,
            'admin',
        );

        $this->assertDatabaseHas('profile_addresses', [
            'profile_id' => $profile->id,
            'address_scope' => 'parents',
            'address_type_id' => $permanentTypeId,
            'address_line' => 'मु. पो. भूड, ता. खानापूर, जि. सांगली',
        ]);
    }
}
