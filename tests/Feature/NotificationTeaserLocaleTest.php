<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\ProfileView;
use App\Models\User;
use App\Notifications\ProfileViewedNotification;
use App\Services\Profile\ProfileCanonicalResidenceService;
use App\Services\WhoViewed\NotificationTeaserRenderer;
use App\Services\WhoViewed\WhoViewedTeaserPolicy;
use App\Services\WhoViewed\WhoViewedTeaserPresenter;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A notification's teaser is rendered for the READER, not for whoever wrote the row.
 *
 * A profile-view notification is written inside the VIEWER's request, so the teaser
 * it stored inherited the viewer's language — a Marathi member could open the app and
 * find an English card sitting above older Marathi ones. The stored copy also froze
 * its relative time at the instant of writing, so every locked card on production
 * read "Viewed just now" forever. Both are fixed by rendering at read time; these
 * tests pin that, and pin that the rows which CANNOT be re-rendered still draw.
 */
class NotificationTeaserLocaleTest extends TestCase
{
    use RefreshDatabase;

    private const VIEWER_REAL_NAME = 'Zqxvarun Deshmukhwadkar';

    /** Devanagari letters and matras — deliberately stops short of the digit block. */
    private const DEVANAGARI_TEXT = '/[\x{0900}-\x{0963}]/u';

    /** The numerals the FROZEN Latin-digit rule forbids anywhere a member can see. */
    private const DEVANAGARI_DIGIT = '/[\x{0966}-\x{096F}]/u';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MinimalLocationSeeder::class);
        $this->seed(MasterLookupSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();

        // The rich card, so the teaser carries real translated copy AND a number
        // (the repeat-view accent line) for the digit rule to be tested against.
        AdminSetting::setValue(WhoViewedTeaserPolicy::SETTING_KEY, json_encode([
            'apply_who_viewed_locked' => true,
            'name_display' => 'courtesy_from_place',
            'location_granularity' => 'taluka_and_above',
            'show_age_mode' => 'exact',
            'show_repeat_view_teaser' => true,
            'show_match_teaser' => false,
            'teaser_viewed_time' => 'human',
        ], JSON_THROW_ON_ERROR));
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

    /**
     * The exact payload production wrote for an English-preferring viewer.
     *
     * Built through the real notification class, not hand-assembled, so the write
     * path stays part of what this test covers.
     *
     * @return array<string, mixed>
     */
    private function payloadWrittenInEnglish(User $owner, MatrimonyProfile $viewerProfile): array
    {
        $previous = app()->getLocale();
        app()->setLocale('en');

        try {
            $payload = (new ProfileViewedNotification($viewerProfile))->toArray($owner->fresh());
        } finally {
            app()->setLocale($previous);
        }

        $this->assertFalse(
            $payload['revealed'] ?? true,
            'This test only means something on a LOCKED row; the identity gate revealed the viewer.'
        );
        $this->assertIsArray($payload['teaser'] ?? null);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeRow(User $owner, array $data, ?string $createdAt = null): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => ProfileViewedNotification::class,
            'notifiable_type' => $owner->getMorphClass(),
            'notifiable_id' => $owner->getKey(),
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'read_at' => null,
            'created_at' => $createdAt ?? now()->subHours(3),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function recordViews(MatrimonyProfile $owner, MatrimonyProfile $viewer, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            ProfileView::query()->create([
                'viewer_profile_id' => $viewer->id,
                'viewed_profile_id' => $owner->id,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchCard(User $reader, string $notificationId, string $acceptLanguage): array
    {
        Sanctum::actingAs($reader->fresh());

        $rows = $this->withHeader('Accept-Language', $acceptLanguage)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->json('notifications');

        $card = collect($rows)->firstWhere('id', $notificationId);
        $this->assertIsArray($card, 'The stored notification did not come back from the API.');

        return $card;
    }

    public function test_a_marathi_reader_gets_a_marathi_teaser_for_a_row_written_in_english(): void
    {
        $owner = User::factory()->create(['is_admin' => false]);
        $ownerProfile = $this->createActiveProfileWithResidence($owner);
        $viewerProfile = $this->createActiveProfileWithResidence(
            User::factory()->create(['is_admin' => false]),
            ['full_name' => self::VIEWER_REAL_NAME],
        );
        $this->recordViews($ownerProfile, $viewerProfile, 7);

        $payload = $this->payloadWrittenInEnglish($owner, $viewerProfile);
        $storedTeaser = json_encode($payload['teaser'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertDoesNotMatchRegularExpression(
            self::DEVANAGARI_TEXT,
            $storedTeaser,
            'The stored teaser was supposed to be the English one — the defect this test reproduces.'
        );

        $id = $this->storeRow($owner, $payload);

        $marathiCard = $this->fetchCard($owner, $id, 'mr');
        $marathiTeaser = json_encode($marathiCard['display']['teaser'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertMatchesRegularExpression(
            self::DEVANAGARI_TEXT,
            $marathiTeaser,
            'A Marathi reader still got the English teaser the viewer\'s request happened to write.'
        );
        $this->assertNotSame($storedTeaser, $marathiTeaser);

        // …and the same row flips back for an English reader, which is the half of
        // the defect that never shows up if you only ever test one direction.
        $englishTeaser = json_encode(
            $this->fetchCard($owner, $id, 'en')['display']['teaser'],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $this->assertDoesNotMatchRegularExpression(self::DEVANAGARI_TEXT, $englishTeaser);
    }

    public function test_the_reader_locale_also_decides_the_context_label(): void
    {
        $owner = User::factory()->create(['is_admin' => false]);
        $this->createActiveProfileWithResidence($owner);
        $viewerProfile = $this->createActiveProfileWithResidence(User::factory()->create(['is_admin' => false]));

        $payload = $this->payloadWrittenInEnglish($owner, $viewerProfile);
        $this->assertSame('Open who viewed me', $payload['teaser_context_label'] ?? null);

        $id = $this->storeRow($owner, $payload);

        $this->assertSame(
            __('notifications.teaser_open_who_viewed', [], 'mr'),
            $this->fetchCard($owner, $id, 'mr')['display']['secondary_cta']['label'] ?? null,
        );
        $this->assertSame(
            'Open who viewed me',
            $this->fetchCard($owner, $id, 'en')['display']['secondary_cta']['label'] ?? null,
        );
    }

    public function test_an_old_row_without_the_actor_key_still_renders_its_stored_teaser(): void
    {
        $owner = User::factory()->create(['is_admin' => false]);
        $this->createActiveProfileWithResidence($owner);
        $viewerProfile = $this->createActiveProfileWithResidence(User::factory()->create(['is_admin' => false]));

        // Exactly the 135 rows sitting on production for user 174: written before the
        // actor key existed, so the raw facts are simply not recoverable from them.
        $payload = $this->payloadWrittenInEnglish($owner, $viewerProfile);
        unset($payload[NotificationTeaserRenderer::ACTOR_PROFILE_ID_KEY]);

        $id = $this->storeRow($owner, $payload);
        $card = $this->fetchCard($owner, $id, 'mr');

        $teaser = $card['display']['teaser'] ?? null;
        $this->assertIsArray($teaser, 'An un-rebuildable row must fall back to its stored teaser, never to nothing.');
        $this->assertSame(
            WhoViewedTeaserPresenter::displayPayload($payload['teaser']),
            $teaser,
            'The stored card must come through unchanged — this is the screen that already works.'
        );
        $this->assertNotSame('', trim((string) $teaser['headline']));
        $this->assertSame('locked_teaser', $card['display']['layout']);

        // The button is still rebuilt: the label has both languages in lang files
        // even when the teaser itself is stuck in one.
        $this->assertSame(
            __('notifications.teaser_open_who_viewed', [], 'mr'),
            $card['display']['secondary_cta']['label'] ?? null,
        );
    }

    public function test_a_rendered_teaser_keeps_the_nine_key_contract_and_latin_digits(): void
    {
        $owner = User::factory()->create(['is_admin' => false]);
        $ownerProfile = $this->createActiveProfileWithResidence($owner);
        $viewerProfile = $this->createActiveProfileWithResidence(
            User::factory()->create(['is_admin' => false]),
            ['full_name' => self::VIEWER_REAL_NAME, 'date_of_birth' => now()->subYears(33)->toDateString()],
        );
        $this->recordViews($ownerProfile, $viewerProfile, 7);

        $id = $this->storeRow($owner, $this->payloadWrittenInEnglish($owner, $viewerProfile));
        $card = $this->fetchCard($owner, $id, 'mr');
        $teaser = $card['display']['teaser'];

        // Both Flutter surfaces are already deployed against these nine keys, in
        // this order. Widening or narrowing the shape breaks a shipped APK.
        $this->assertSame(WhoViewedTeaserPresenter::DISPLAY_KEYS, array_keys($teaser));

        $json = json_encode($card, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertDoesNotMatchRegularExpression(
            self::DEVANAGARI_DIGIT,
            $json,
            'FROZEN rule: every numeral a member sees is Latin 0-9, in any language.'
        );
        $this->assertStringContainsString('7', $json, 'The repeat-view count should be rendered, in Latin digits.');
        $this->assertStringNotContainsString(
            self::VIEWER_REAL_NAME,
            $json,
            'A locked row must never carry the viewer\'s real name.'
        );
    }

    public function test_rendering_a_hundred_row_page_does_not_cost_a_query_per_row(): void
    {
        $owner = User::factory()->create(['is_admin' => false]);
        $ownerProfile = $this->createActiveProfileWithResidence($owner);

        $viewers = [];
        for ($i = 0; $i < 10; $i++) {
            $viewers[] = $this->createActiveProfileWithResidence(User::factory()->create(['is_admin' => false]));
        }

        $payloads = [];
        foreach ($viewers as $viewerProfile) {
            $this->recordViews($ownerProfile, $viewerProfile, 2);
            $payloads[] = $this->payloadWrittenInEnglish($owner, $viewerProfile);
        }

        for ($i = 0; $i < 100; $i++) {
            $this->storeRow($owner, $payloads[$i % count($payloads)], now()->subMinutes(200 - $i)->toDateTimeString());
        }

        $renderer = app(NotificationTeaserRenderer::class);
        $owner = $owner->fresh();
        $ownerProfile = $owner->matrimonyProfile;

        $all = $owner->notifications()->get();
        $this->assertCount(100, $all);

        $tenRows = $this->countQueries(fn () => $renderer->forPage($all->take(10), $ownerProfile));
        $hundredRows = $this->countQueries(fn () => $renderer->forPage($all, $ownerProfile));

        // Ten rows and a hundred rows read the same fixed set: the actor profiles and
        // their relations in one go, the repeat-view counts in one grouped query, the
        // admin policy once, the geo-table guard once. Measured at 10 and 9 — a
        // hundred-row page costs no more than a ten-row one. The absolute ceiling is
        // asserted too, so "flat, but flatly enormous" cannot pass either.
        $this->assertLessThanOrEqual(
            $tenRows + 5,
            $hundredRows,
            "Teaser rendering grew with page size: 10 rows = {$tenRows} queries, 100 rows = {$hundredRows}."
        );
        $this->assertLessThanOrEqual(
            20,
            $hundredRows,
            "A hundred-row page cost {$hundredRows} queries to render."
        );
    }

    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $callback();

        return $count;
    }
}
