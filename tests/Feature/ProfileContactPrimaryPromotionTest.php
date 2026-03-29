<?php

namespace Tests\Feature;

use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\MutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProfileContactPrimaryPromotionTest extends TestCase
{
    use RefreshDatabase;

    private function ensureSelfRelationId(): int
    {
        $id = DB::table('master_contact_relations')->where('key', 'self')->value('id');
        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table('master_contact_relations')->insertGetId([
            'key' => 'self',
            'label' => 'Self',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureFatherRelationId(): int
    {
        $id = DB::table('master_contact_relations')->where('key', 'father')->value('id');
        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table('master_contact_relations')->insertGetId([
            'key' => 'father',
            'label' => 'Father',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_promote_fails_when_contact_not_verified(): void
    {
        $selfId = $this->ensureSelfRelationId();
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
        $cid = (int) DB::table('profile_contacts')->insertGetId([
            'profile_id' => $profile->id,
            'contact_relation_id' => $selfId,
            'contact_name' => 'Self',
            'phone_number' => '9111111111',
            'is_primary' => false,
            'visibility_rule' => 'unlock_only',
            'verified_status' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(MutationService::class)->promoteVerifiedSelfContactToPrimary($profile, $cid, (int) $user->id);
    }

    public function test_verify_then_promote_updates_primary(): void
    {
        $selfId = $this->ensureSelfRelationId();
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
        $primaryId = (int) DB::table('profile_contacts')->insertGetId([
            'profile_id' => $profile->id,
            'contact_relation_id' => $selfId,
            'contact_name' => 'Self',
            'phone_number' => '9222222222',
            'is_primary' => true,
            'visibility_rule' => 'unlock_only',
            'verified_status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $newId = (int) DB::table('profile_contacts')->insertGetId([
            'profile_id' => $profile->id,
            'contact_relation_id' => $selfId,
            'contact_name' => 'Self',
            'phone_number' => '9333333333',
            'is_primary' => false,
            'visibility_rule' => 'unlock_only',
            'verified_status' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(MutationService::class)->markSelfContactVerified($profile, $newId, (int) $user->id);
        $this->assertSame(1, (int) DB::table('profile_contacts')->where('id', $newId)->value('verified_status'));

        app(MutationService::class)->promoteVerifiedSelfContactToPrimary($profile, $newId, (int) $user->id);

        $this->assertSame(0, (int) DB::table('profile_contacts')->where('id', $primaryId)->value('is_primary'));
        $this->assertSame(1, (int) DB::table('profile_contacts')->where('id', $newId)->value('is_primary'));
    }

    public function test_manual_contact_sync_dedupes_duplicate_phone_same_relation(): void
    {
        $selfId = $this->ensureSelfRelationId();
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        $snapshot = [
            'core' => [],
            'contacts' => [
                [
                    'relation_type' => 'self',
                    'contact_name' => 'Self',
                    'phone_number' => '9444444444',
                    'is_primary' => true,
                ],
                [
                    'relation_type' => 'self',
                    'contact_name' => 'Self',
                    'phone_number' => '9444444444',
                    'is_primary' => false,
                ],
            ],
        ];

        app(MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');

        $count = DB::table('profile_contacts')->where('profile_id', $profile->id)->where('phone_number', '9444444444')->count();
        $this->assertSame(1, $count);
        $this->assertSame(0, (int) DB::table('profile_contacts')->where('profile_id', $profile->id)->where('is_primary', true)->count());
    }

    public function test_verified_primary_unchanged_when_new_unverified_self_added(): void
    {
        $selfId = $this->ensureSelfRelationId();
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
        $primaryId = (int) DB::table('profile_contacts')->insertGetId([
            'profile_id' => $profile->id,
            'contact_relation_id' => $selfId,
            'contact_name' => 'Self',
            'phone_number' => '9811111111',
            'is_primary' => true,
            'visibility_rule' => 'unlock_only',
            'verified_status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $snapshot = [
            'core' => [],
            'contacts' => [
                [
                    'id' => $primaryId,
                    'relation_type' => 'self',
                    'contact_name' => 'Self',
                    'phone_number' => '9811111111',
                    'is_primary' => true,
                ],
                [
                    'relation_type' => 'self',
                    'contact_name' => 'Self',
                    'phone_number' => '9822222222',
                    'is_primary' => true,
                ],
            ],
        ];

        app(MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');

        $this->assertSame(1, (int) DB::table('profile_contacts')->where('id', $primaryId)->value('is_primary'));
        $this->assertSame(0, (int) DB::table('profile_contacts')->where('phone_number', '9822222222')->value('is_primary'));
    }

    public function test_only_unverified_self_contacts_yield_no_primary(): void
    {
        $selfId = $this->ensureSelfRelationId();
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        $snapshot = [
            'core' => [],
            'contacts' => [
                [
                    'relation_type' => 'self',
                    'contact_name' => 'Self',
                    'phone_number' => '9833333333',
                    'is_primary' => true,
                ],
            ],
        ];

        app(MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');

        $this->assertSame(0, (int) DB::table('profile_contacts')->where('profile_id', $profile->id)->where('is_primary', true)->count());
    }

    public function test_verified_self_becomes_primary_from_snapshot(): void
    {
        $selfId = $this->ensureSelfRelationId();
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
        $cid = (int) DB::table('profile_contacts')->insertGetId([
            'profile_id' => $profile->id,
            'contact_relation_id' => $selfId,
            'contact_name' => 'Self',
            'phone_number' => '9844444444',
            'is_primary' => false,
            'visibility_rule' => 'unlock_only',
            'verified_status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $snapshot = [
            'core' => [],
            'contacts' => [
                [
                    'id' => $cid,
                    'relation_type' => 'self',
                    'contact_name' => 'Self',
                    'phone_number' => '9844444444',
                    'is_primary' => true,
                ],
            ],
        ];

        app(MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');

        $this->assertSame(1, (int) DB::table('profile_contacts')->where('id', $cid)->value('is_primary'));
    }

    public function test_mixed_verified_unverified_only_verified_can_hold_primary(): void
    {
        $selfId = $this->ensureSelfRelationId();
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
        $unverifiedId = (int) DB::table('profile_contacts')->insertGetId([
            'profile_id' => $profile->id,
            'contact_relation_id' => $selfId,
            'contact_name' => 'Self',
            'phone_number' => '9855555555',
            'is_primary' => false,
            'visibility_rule' => 'unlock_only',
            'verified_status' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $verifiedId = (int) DB::table('profile_contacts')->insertGetId([
            'profile_id' => $profile->id,
            'contact_relation_id' => $selfId,
            'contact_name' => 'Self',
            'phone_number' => '9866666666',
            'is_primary' => false,
            'visibility_rule' => 'unlock_only',
            'verified_status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $snapshot = [
            'core' => [],
            'contacts' => [
                [
                    'id' => $unverifiedId,
                    'relation_type' => 'self',
                    'contact_name' => 'Self',
                    'phone_number' => '9855555555',
                    'is_primary' => true,
                ],
                [
                    'id' => $verifiedId,
                    'relation_type' => 'self',
                    'contact_name' => 'Self',
                    'phone_number' => '9866666666',
                    'is_primary' => true,
                ],
            ],
        ];

        app(MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');

        $this->assertSame(0, (int) DB::table('profile_contacts')->where('id', $unverifiedId)->value('is_primary'));
        $this->assertSame(1, (int) DB::table('profile_contacts')->where('id', $verifiedId)->value('is_primary'));
    }

    public function test_verify_otp_endpoint_marks_verified(): void
    {
        $selfId = $this->ensureSelfRelationId();
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
        $cid = (int) DB::table('profile_contacts')->insertGetId([
            'profile_id' => $profile->id,
            'contact_relation_id' => $selfId,
            'contact_name' => 'Self',
            'phone_number' => '9555555555',
            'is_primary' => false,
            'visibility_rule' => 'unlock_only',
            'verified_status' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otp = '123456';
        Cache::put(
            'profile_contact_otp:'.$user->id.':'.$cid,
            password_hash($otp, PASSWORD_DEFAULT),
            now()->addMinutes(10)
        );

        $this->actingAs($user)
            ->post(route('matrimony.profile.contacts.verify-otp', ['contact' => $cid]), [
                'otp' => $otp,
            ])
            ->assertRedirect(route('matrimony.profile.wizard.section', ['section' => 'full']));

        $this->assertSame(1, (int) DB::table('profile_contacts')->where('id', $cid)->value('verified_status'));
    }

    public function test_promote_forbidden_for_other_user(): void
    {
        $selfId = $this->ensureSelfRelationId();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $owner->id]);
        $cid = (int) DB::table('profile_contacts')->insertGetId([
            'profile_id' => $profile->id,
            'contact_relation_id' => $selfId,
            'contact_name' => 'Self',
            'phone_number' => '9666666666',
            'is_primary' => false,
            'visibility_rule' => 'unlock_only',
            'verified_status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        MatrimonyProfile::factory()->create(['user_id' => $other->id]);

        $this->actingAs($other)
            ->post(route('matrimony.profile.contacts.promote-primary', ['contact' => $cid]))
            ->assertForbidden();
    }

    public function test_cannot_verify_father_row_as_self_flow(): void
    {
        $selfId = $this->ensureSelfRelationId();
        $fatherId = $this->ensureFatherRelationId();
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
        $cid = (int) DB::table('profile_contacts')->insertGetId([
            'profile_id' => $profile->id,
            'contact_relation_id' => $fatherId,
            'contact_name' => 'Father',
            'phone_number' => '9777777777',
            'is_primary' => false,
            'visibility_rule' => 'unlock_only',
            'verified_status' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('matrimony.profile.contacts.send-otp', ['contact' => $cid]))
            ->assertForbidden();
    }
}
