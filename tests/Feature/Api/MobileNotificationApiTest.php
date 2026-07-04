<?php

namespace Tests\Feature\Api;

use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileNotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_locked_teaser_notification_returns_safe_mobile_display_payload(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->storeNotification($user, [
            'type' => 'profile_viewed',
            'message' => 'Someone viewed your profile.',
            'revealed' => false,
            'teaser' => [
                'headline' => 'Someone from Pune',
                'lines' => ['Pune district', '29 years'],
                'viewed_summary' => 'Viewed recently',
                'photo_url' => 'https://example.test/blurred-photo.jpg',
                'avatar_style' => 'blur',
                'blur_photo_class' => 'blur-md scale-110 opacity-90',
                'profile_id' => 999,
                'viewer_profile_id' => 999,
                'user_id' => 999,
                'phone' => '9999999999',
                'email' => 'hidden@example.test',
                'whatsapp' => '9999999999',
                'contact' => ['phone' => '9999999999'],
            ],
            'teaser_context_label' => 'View who viewed',
        ]);

        $response = $this->getJson('/api/v1/notifications')->assertOk();

        $row = $response->json('notifications.0');
        $this->assertSame('profile_viewed', $row['key']);
        $this->assertSame('plans', $row['route_hint']);
        $this->assertNull($row['profile_id']);
        $this->assertSame('locked_teaser', $row['display']['layout']);
        $this->assertNull($row['display']['actor']);
        $this->assertSame('locked_teaser', $row['display']['privacy']['state']);
        $this->assertSame('blurred', $row['display']['privacy']['photo']);
        $this->assertSame('hidden', $row['display']['privacy']['contact']);
        $this->assertSame('plans', $row['display']['cta']['route_hint']);
        $this->assertSame('who_viewed', $row['display']['secondary_cta']['route_hint']);
        $this->assertSame('Someone from Pune', $row['display']['teaser']['headline']);

        $forbiddenKeys = [
            'id',
            'profile_id',
            'viewer_profile_id',
            'user_id',
            'contact',
            'phone',
            'email',
            'whatsapp',
            'paid_contact',
            'primary_cta',
        ];
        $teaserKeys = $this->recursivePayloadKeys($row['display']['teaser']);
        foreach ($forbiddenKeys as $key) {
            $this->assertNotContains($key, $teaserKeys);
        }
    }

    public function test_mediation_notifications_route_to_mediation_inbox_without_profile_action(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->storeNotification($user, [
            'type' => 'mediation_request_received',
            'message' => 'WhatsApp Response request received.',
            'mediation_request_id' => 55,
            'sender_profile_id' => 123,
            'receiver_profile_id' => 456,
            'subject_profile_id' => 789,
        ]);

        $response = $this->getJson('/api/v1/notifications')->assertOk();

        $row = $response->json('notifications.0');
        $this->assertSame('mediation_inbox', $row['route_hint']);
        $this->assertSame('mediation_inbox', $row['action_type']);
        $this->assertSame(55, $row['request_id']);
        $this->assertNull($row['profile_id']);
        $this->assertSame('mediation', $row['display']['layout']);
        $this->assertSame('mediation_inbox', $row['display']['cta']['route_hint']);
    }

    public function test_revealed_profile_notification_includes_actor_display_payload(): void
    {
        $user = User::factory()->create();
        $actorUser = User::factory()->create();
        $actorProfile = MatrimonyProfile::withoutEvents(fn () => MatrimonyProfile::factory()
            ->for($actorUser)
            ->create(['full_name' => 'Mobile Actor Profile']));
        Sanctum::actingAs($user);

        $this->storeNotification($user, [
            'type' => 'profile_viewed',
            'message' => 'Mobile Actor Profile viewed your profile.',
            'revealed' => true,
            'viewer_profile_id' => $actorProfile->id,
        ]);

        $response = $this->getJson('/api/v1/notifications')->assertOk();

        $row = $response->json('notifications.0');
        $this->assertSame('profile', $row['route_hint']);
        $this->assertSame((int) $actorProfile->id, $row['profile_id']);
        $this->assertSame('profile', $row['display']['layout']);
        $this->assertSame((int) $actorProfile->id, $row['display']['actor']['id']);
        $this->assertSame('Mobile Actor Profile', $row['display']['actor']['name']);
        $this->assertSame('revealed', $row['display']['privacy']['state']);
        $this->assertSame('profile', $row['display']['cta']['route_hint']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeNotification(User $user, array $data): void
    {
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'Tests\\Fixtures\\MobileNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function recursivePayloadKeys(array $payload): array
    {
        $keys = [];
        foreach ($payload as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value)) {
                array_push($keys, ...$this->recursivePayloadKeys($value));
            }
        }

        return $keys;
    }
}
