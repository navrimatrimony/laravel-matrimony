<?php

namespace Tests\Feature\Api;

use App\Models\Caste;
use App\Models\Religion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The api group had no locale middleware, so six controllers each set the
 * locale themselves with their own precedence. SetApiLocale is the one place
 * that now decides it. These tests pin the precedence so a later edit can't
 * quietly flip an endpoint's default language — the kind of regression that
 * ships silently and only surfaces as "why is my Marathi user seeing English
 * caste names".
 *
 * The onboarding lookup routes sit behind auth:sanctum, which is exactly how
 * the Flutter apps call them, so the tests act as a signed-in member and drive
 * the language the same way the apps do — a ?locale= param or Accept-Language,
 * never a controller-side setLocale the middleware would overwrite.
 */
class SetApiLocaleTest extends TestCase
{
    use RefreshDatabase;

    private int $religionId;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());

        $religion = Religion::create([
            'key' => 'hindu', 'label' => 'Hindu', 'label_en' => 'Hindu',
            'label_mr' => 'हिंदू', 'is_active' => true,
        ]);
        Caste::create([
            'religion_id' => $religion->id, 'key' => 'brahmin', 'label' => 'Brahmin',
            'label_en' => 'Brahmin', 'label_mr' => 'ब्राह्मण', 'is_active' => true,
        ]);
        $this->religionId = $religion->id;
    }

    private function firstLabel(string $query, array $headers = []): string
    {
        // /api/v1/castes emits its `label` from the Caste::display_label
        // accessor, which is exactly the resolver under test — so the label
        // that comes back is the one the middleware-chosen locale produced.
        return $this->getJson('/api/v1/castes?religion_id='.$this->religionId.$query, $headers)
            ->assertOk()
            ->json('0.label');
    }

    public function test_explicit_locale_param_wins(): void
    {
        $this->assertSame('ब्राह्मण', $this->firstLabel('&locale=mr'));
        $this->assertSame('Brahmin', $this->firstLabel('&locale=en'));
    }

    public function test_a_regional_tag_is_narrowed_to_its_language(): void
    {
        // A client sending mr-IN still means Marathi.
        $this->assertSame('ब्राह्मण', $this->firstLabel('&locale=mr-IN'));
    }

    public function test_accept_language_is_honoured_when_no_param_is_given(): void
    {
        $this->assertSame('ब्राह्मण', $this->firstLabel('', ['Accept-Language' => 'mr']));
        $this->assertSame('Brahmin', $this->firstLabel('', ['Accept-Language' => 'en-US,en;q=0.9']));
    }

    public function test_a_locale_we_do_not_ship_is_ignored_and_the_next_tier_decides(): void
    {
        // The app ships exactly two languages: mr and en. A locale value that is
        // neither — a typo, a stale client, a bad actor sending ?locale=xx — must
        // not hard-fail and must not be forced to one language on its own. It
        // simply doesn't count, and the decision falls to the next signal
        // (Accept-Language here). Deliberately using 'xx', which is not a real
        // language, so this test keeps meaning "junk is rejected" even after a
        // real third language (Hindi, Kannada, ...) is added to SUPPORTED — at
        // which point a 'hi'/'kn' param WOULD be honoured, and testing against
        // one of those would silently invert.
        $this->assertSame('ब्राह्मण', $this->firstLabel('&locale=xx', ['Accept-Language' => 'mr']));
        $this->assertSame('Brahmin', $this->firstLabel('&locale=xx', ['Accept-Language' => 'en']));
    }

    public function test_no_signal_at_all_defaults_to_marathi(): void
    {
        // The trait this middleware replaced defaulted the location endpoints to
        // Marathi; keeping that is why SUPPORTED lists 'mr' first. A request with
        // neither param nor a matching Accept-Language stays Marathi.
        $this->assertSame('ब्राह्मण', $this->firstLabel('', ['Accept-Language' => '']));
    }

    public function test_the_apps_live_accept_language_beats_a_stale_saved_preference(): void
    {
        // The exact bug this ordering prevents: a member whose account was
        // created with preferred_locale 'en' switches the app to Marathi. The
        // app sends Accept-Language: mr on every call; the stale saved 'en' must
        // not override it, or the member's live in-app choice never takes effect
        // (profile detail flips back to English a second after it opens).
        Sanctum::actingAs(User::factory()->create(['preferred_locale' => 'en']));
        $this->assertSame('ब्राह्मण', $this->firstLabel('', ['Accept-Language' => 'mr']));

        // The mirror: a Marathi-saved member who switches the app to English.
        Sanctum::actingAs(User::factory()->create(['preferred_locale' => 'mr']));
        $this->assertSame('Brahmin', $this->firstLabel('', ['Accept-Language' => 'en']));
    }

    public function test_saved_preference_still_decides_when_no_usable_header_is_sent(): void
    {
        // preferred_locale is not dead — it is the fallback for a request that
        // sends no usable Accept-Language (an older build predating the header).
        Sanctum::actingAs(User::factory()->create(['preferred_locale' => 'en']));
        $this->assertSame('Brahmin', $this->firstLabel('', ['Accept-Language' => '']));
    }
}
