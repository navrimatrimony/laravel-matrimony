<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Notifications\InterestSentNotification;
use App\Services\Interest\ReceivedInterestTeaserBuilder;
use App\Services\Profile\ProfileCanonicalResidenceService;
use App\Services\Push\PushTeaserCopyService;
use App\Services\WhoViewed\WhoViewedTeaserPresenter;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\MinimalLocationSeeder;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pins the two promises the paywall rests on: a locked row shows the person
 * without the identity, and an unlocked row is never dressed up as a teaser.
 */
class ReceivedInterestTeaserAndPushCopyTest extends TestCase
{
    use RefreshDatabase;

    private const SENDER_REAL_NAME = 'Zqxvarun Deshmukhwadkar';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MinimalLocationSeeder::class);
        $this->seed(MasterLookupSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    /**
     * @param  array<string, mixed>  $factoryAttributes
     */
    private function createActiveProfileWithResidence(User $user, array $factoryAttributes = []): MatrimonyProfile
    {
        $p = MatrimonyProfile::factory()->for($user)->create(array_merge([
            'lifecycle_state' => 'draft',
        ], $factoryAttributes));

        $tbl = $p->getTable();
        $leafId = (int) City::query()->where('name', 'Pune City')->firstOrFail()->id;
        if (Schema::hasColumn($tbl, 'location_id')) {
            DB::table($tbl)->where('id', $p->id)->update(['location_id' => $leafId]);
            $p->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $p->id, $leafId, null, true, false);
        }

        $p->update([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        return $p->fresh();
    }

    /*
    |--------------------------------------------------------------------------
    | GAP 1 — GET /api/v1/interests/received
    |--------------------------------------------------------------------------
    */

    public function test_locked_received_interest_row_carries_a_teaser_and_never_the_real_name(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $receiver = User::factory()->create(['is_admin' => false]);
        $receiverProfile = $this->createActiveProfileWithResidence($receiver);

        // Enough senders that the free reveal quota cannot cover them all.
        for ($i = 0; $i < 6; $i++) {
            $senderProfile = $this->createActiveProfileWithResidence(
                User::factory()->create(['is_admin' => false]),
                ['full_name' => self::SENDER_REAL_NAME],
            );

            Interest::query()->create([
                'sender_profile_id' => $senderProfile->id,
                'receiver_profile_id' => $receiverProfile->id,
                'status' => 'pending',
                'priority_score' => 1,
            ]);
        }

        Sanctum::actingAs($receiver->fresh());

        $rows = $this->getJson('/api/v1/interests/received')
            ->assertOk()
            ->json('data.received');

        $this->assertIsArray($rows);

        $lockedRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['incoming_reveal_unlocked'] ?? true) === false,
        ));

        $this->assertNotEmpty(
            $lockedRows,
            'Expected at least one locked row; without one this test proves nothing.'
        );

        foreach ($lockedRows as $row) {
            $this->assertArrayHasKey('teaser', $row, 'A locked row must carry a teaser.');
            $this->assertIsArray($row['teaser']);

            // Exactly the nine keys the app's shared LockedTeaser widget reads —
            // no wider, no narrower.
            $this->assertSame(
                WhoViewedTeaserPresenter::DISPLAY_KEYS,
                array_keys($row['teaser']),
            );

            $this->assertStringNotContainsString(
                self::SENDER_REAL_NAME,
                json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'A locked row leaked the sender\'s real name.'
            );

            $this->assertFalse($row['sender_profile']['revealed'] ?? null);
        }

        foreach ($rows as $row) {
            if (($row['incoming_reveal_unlocked'] ?? true) === true) {
                $this->assertArrayNotHasKey(
                    'teaser',
                    $row,
                    'An unlocked row shows the real person and must not carry a teaser.'
                );
            }
        }
    }

    public function test_builder_returns_no_teaser_for_an_unlocked_row(): void
    {
        $receiver = User::factory()->create(['is_admin' => false]);
        $receiverProfile = $this->createActiveProfileWithResidence($receiver);

        $senderProfile = $this->createActiveProfileWithResidence(
            User::factory()->create(['is_admin' => false]),
            ['full_name' => self::SENDER_REAL_NAME],
        );

        $unlocked = Interest::query()->create([
            'sender_profile_id' => $senderProfile->id,
            'receiver_profile_id' => $receiverProfile->id,
            'status' => 'pending',
            'priority_score' => 1,
        ]);

        $locked = Interest::query()->create([
            'sender_profile_id' => $this->createActiveProfileWithResidence(
                User::factory()->create(['is_admin' => false])
            )->id,
            'receiver_profile_id' => $receiverProfile->id,
            'status' => 'pending',
            'priority_score' => 1,
        ]);

        $interests = Interest::with(ReceivedInterestTeaserBuilder::SENDER_PROFILE_EAGER_LOADS)
            ->whereIn('id', [$unlocked->id, $locked->id])
            ->get();

        $teasers = app(ReceivedInterestTeaserBuilder::class)->forLockedRows(
            $interests,
            [$unlocked->id => true, $locked->id => false],
            $receiverProfile,
            displayShapeOnly: true,
        );

        $this->assertArrayNotHasKey($unlocked->id, $teasers);
        $this->assertArrayHasKey($locked->id, $teasers);
        $this->assertSame(WhoViewedTeaserPresenter::DISPLAY_KEYS, array_keys($teasers[$locked->id]));
    }

    /*
    |--------------------------------------------------------------------------
    | GAP 2 — push copy
    |--------------------------------------------------------------------------
    */

    public function test_locked_push_body_carries_teaser_detail_instead_of_the_generic_line(): void
    {
        $body = app(PushTeaserCopyService::class)->body('new_interest', [
            'type' => 'interest_sent',
            'revealed' => false,
            'teaser' => [
                'headline' => 'खानापूर हून एक मुलगी',
                'lines' => ['22 वर्षे', 'अविवाहित'],
                'viewed_summary' => 'काही वेळापूर्वी स्वारस्य दाखवले',
                'photo_url' => null,
                'avatar_style' => 'blur',
                'blur_photo_class' => 'blur-md scale-110 opacity-90',
                'accent_line' => null,
                'match_line' => 'तुमच्या पसंतीशी 82% जुळणी',
                'interest_hint' => 'तिला तुमच्यात रस आहे',
            ],
        ]);

        $this->assertNotNull($body);
        $this->assertStringStartsWith('खानापूर हून एक मुलगी', $body, 'Who/where must be front-loaded.');
        $this->assertStringContainsString('22 वर्षे', $body);
        $this->assertStringContainsString('82%', $body);
        $this->assertNotSame(__('push.types.new_interest.body'), $body);

        // The time line is deliberately left out of the collapsed-notification budget.
        $this->assertStringNotContainsString('काही वेळापूर्वी', $body);
    }

    public function test_push_copy_never_emits_devanagari_digits(): void
    {
        $body = app(PushTeaserCopyService::class)->body('profile_viewed', [
            'type' => 'profile_viewed',
            'revealed' => false,
            'teaser' => [
                'headline' => 'सांगली हून एक मुलगा',
                'lines' => ['२८ वर्षे'],
                'match_line' => '७५% जुळणी',
            ],
        ]);

        $this->assertNotNull($body);
        $this->assertSame(
            0,
            preg_match('/[\x{0966}-\x{096F}]/u', $body),
            'FROZEN: every numeral in a push string must be Latin 0-9.'
        );
        $this->assertStringContainsString('28 वर्षे', $body);
        $this->assertStringContainsString('75%', $body);
    }

    public function test_real_locked_interest_notification_produces_a_teaser_push_body(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $receiver = User::factory()->create(['is_admin' => false]);
        $this->createActiveProfileWithResidence($receiver);

        $senders = [];
        for ($i = 0; $i < 6; $i++) {
            $senders[] = $this->createActiveProfileWithResidence(
                User::factory()->create(['is_admin' => false]),
                ['full_name' => self::SENDER_REAL_NAME],
            );
        }

        foreach ($senders as $senderProfile) {
            Interest::query()->create([
                'sender_profile_id' => $senderProfile->id,
                'receiver_profile_id' => $receiver->matrimonyProfile->id,
                'status' => 'pending',
                'priority_score' => 1,
            ]);
        }

        $payload = (new InterestSentNotification(end($senders)))->toArray($receiver->fresh());
        $this->assertFalse($payload['revealed'], 'Expected the last sender to be locked.');

        $body = app(PushTeaserCopyService::class)->body('new_interest', $payload);

        $this->assertNotNull($body);
        $this->assertNotSame(__('push.types.new_interest.body'), $body);
        $this->assertStringNotContainsString(self::SENDER_REAL_NAME, $body);
    }

    public function test_revealed_push_body_uses_the_name_and_missing_data_falls_back(): void
    {
        $service = app(PushTeaserCopyService::class);

        $this->assertSame(
            __('push.types.interest_accepted.body_named', ['name' => 'Sunita Patil']),
            $service->body('interest_accepted', [
                'type' => 'interest_accepted',
                'accepter_name' => 'Sunita Patil',
            ]),
        );

        // No teaser, no usable name → null, so the generic reviewed line stands.
        $this->assertNull($service->body('new_interest', [
            'type' => 'interest_sent',
            'revealed' => true,
        ]));

        // The shared placeholder must never be spoken as if it were a name.
        $this->assertNull($service->body('new_interest', [
            'type' => 'interest_sent',
            'revealed' => true,
            'sender_name' => 'Someone',
        ]));

        // No person behind the event at all → untouched.
        $this->assertNull($service->body('plan_expiring', [
            'type' => 'plan_expiring_soon',
            'days_left' => 3,
        ]));
    }

    public function test_push_language_files_stay_symmetric(): void
    {
        $mr = require base_path('lang/mr/push.php');
        $en = require base_path('lang/en/push.php');

        $this->assertSame($this->flatKeys($en), $this->flatKeys($mr));
    }

    /**
     * @param  array<string, mixed>  $rows
     * @return list<string>
     */
    private function flatKeys(array $rows, string $prefix = ''): array
    {
        $keys = [];

        foreach ($rows as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $keys = array_merge($keys, $this->flatKeys($value, $path));

                continue;
            }
            $keys[] = $path;
        }

        sort($keys);

        return $keys;
    }
}
