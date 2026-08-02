<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakProfileRepresentation;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * THE FROZEN RULE, pinned:
 *
 *   The app's base language is ENGLISH. Marathi lives in the database / the
 *   server's lang files. Whatever language the caller asked for, the value is
 *   fetched in THAT language. Nothing is hardcoded.
 *
 * It was broken in three separate ways at once, and each one has a test here:
 *
 *  1. The stage ladder's wording was a Marathi-only PHP constant
 *     (`STAGE_LABELS_MR`), so every `stage_label` in every Suchak payload was
 *     Marathi no matter what the caller sent.
 *  2. Eleven controllers wrote their refusals as Marathi string literals, and
 *     the gate middleware in front of them wrote the SAME refusal as an English
 *     literal — so one rule had two hardcoded sentences in two languages.
 *  3. `SetApiLocale` resolved a locale and then never called
 *     `Translation::loadIntoTranslator()`, which the web middleware has always
 *     called. Translations in this product are admin-editable rows that
 *     override the lang files; the api group read the FILE value only, so an
 *     admin's correction reached the website and never reached either Flutter
 *     app.
 *
 * The sweep test is the one that matters most: it does not name a field, so a
 * NEW Marathi literal added to any of these payloads tomorrow fails it.
 */
class SuchakApiLanguageContractTest extends TestCase
{
    use RefreshDatabase;

    /** Devanagari — the alphabet that must not appear in an English response. */
    private const DEVANAGARI = '/[\x{0900}-\x{097F}]/u';

    /**
     * The ladder is the vocabulary the whole Suchak domain is priced and argued
     * in, so it is the vocabulary most worth pinning in both languages.
     */
    public function test_stage_ladder_labels_resolve_in_the_callers_language(): void
    {
        app()->setLocale('en');
        $this->assertSame('Meeting arranged', SuchakCollaborationStageEvent::stageLabel('meeting_scheduled'));
        $this->assertSame('Agreement accepted', SuchakCollaborationStageEvent::stageLabel('agreement_accepted'));

        app()->setLocale('mr');
        $this->assertSame('भेट ठरली', SuchakCollaborationStageEvent::stageLabel('meeting_scheduled'));
        $this->assertSame('करार स्वीकारला', SuchakCollaborationStageEvent::stageLabel('agreement_accepted'));
    }

    /**
     * Every rung must be worded in both languages — a half-translated ladder is
     * how "स्थळ पाहिले" ends up sitting in the middle of an English screen.
     */
    public function test_every_rung_on_the_ladder_is_worded_in_both_languages(): void
    {
        foreach (SuchakCollaborationStageEvent::STAGE_LADDER as $stageKey) {
            app()->setLocale('en');
            $english = SuchakCollaborationStageEvent::stageLabel($stageKey);

            $this->assertNotSame($stageKey, $english, "Stage \"{$stageKey}\" has no English wording.");
            $this->assertDoesNotMatchRegularExpression(
                self::DEVANAGARI,
                $english,
                "Stage \"{$stageKey}\" falls back to Marathi under English."
            );

            app()->setLocale('mr');
            $marathi = SuchakCollaborationStageEvent::stageLabel($stageKey);
            $this->assertNotSame($stageKey, $marathi, "Stage \"{$stageKey}\" has no Marathi wording.");
        }
    }

    /**
     * An unknown rung still degrades to its raw key rather than to a blank —
     * an unlabelled stage is a bug someone can report, a blank one is not.
     */
    public function test_an_unknown_stage_still_degrades_to_its_key(): void
    {
        app()->setLocale('en');

        $this->assertSame('not_a_real_stage', SuchakCollaborationStageEvent::stageLabel('not_a_real_stage'));
    }

    /**
     * THE PRINCIPLE, as a sweep: a Suchak API response fetched with
     * `Accept-Language: en` carries no Devanagari at all.
     *
     * Deliberately field-agnostic. Asserting on named labels only pins the
     * labels someone remembered; walking the whole decoded body catches the
     * next hardcoded sentence too, wherever it is added.
     */
    public function test_an_english_response_carries_no_devanagari_anywhere(): void
    {
        $account = $this->accountWithACustomer();
        Sanctum::actingAs($account->user);

        foreach (['/api/v1/suchak/customers', '/api/v1/suchak/dashboard'] as $route) {
            $body = $this->getJson($route, ['Accept-Language' => 'en'])
                ->assertOk()
                ->json();

            foreach ($this->stringsIn($body) as $path => $value) {
                $this->assertDoesNotMatchRegularExpression(
                    self::DEVANAGARI,
                    $value,
                    "{$route} answered Accept-Language: en with Marathi at `{$path}`: \"{$value}\""
                );
            }
        }
    }

    /**
     * The same route in Marathi must still BE Marathi — otherwise the sweep
     * above could be satisfied by a server that simply forgot Marathi exists,
     * which is the opposite defect and just as bad.
     */
    public function test_the_same_route_still_answers_marathi_when_asked(): void
    {
        $account = $this->accountWithACustomer();
        Sanctum::actingAs($account->user);

        $body = $this->getJson('/api/v1/suchak/customers', ['Accept-Language' => 'mr'])
            ->assertOk()
            ->json();

        $marathi = array_filter(
            $this->stringsIn($body),
            fn (string $value): bool => preg_match(self::DEVANAGARI, $value) === 1,
        );

        $this->assertNotEmpty($marathi, 'A Marathi request came back with no Marathi in it at all.');
    }

    /**
     * The gate's refusal follows the caller too. This one sentence used to
     * exist twice — hardcoded English in the middleware, hardcoded Marathi in
     * eleven controllers behind it — so it is worth pinning that BOTH sides now
     * come from the single key.
     */
    public function test_the_gate_refusal_follows_the_callers_language(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->assertSame(
            'A Suchak account is required.',
            $this->getJson('/api/v1/suchak/customers', ['Accept-Language' => 'en'])
                ->assertForbidden()
                ->json('message'),
        );

        $this->assertSame(
            'सूचक खाते आवश्यक आहे.',
            $this->getJson('/api/v1/suchak/customers', ['Accept-Language' => 'mr'])
                ->assertForbidden()
                ->json('message'),
        );
    }

    /**
     * The `loadIntoTranslator` gap, pinned where it actually bit: an admin's
     * correction in the `translations` table must reach the API, not only the
     * website. Before the fix this assertion returned the lang-file wording.
     */
    public function test_admin_edited_translations_reach_the_api_not_only_the_web(): void
    {
        Translation::create([
            'locale' => 'en',
            'key' => 'suchak.api.errors.suchak_account_required',
            'value' => 'You need a Suchak account first.',
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->assertSame(
            'You need a Suchak account first.',
            $this->getJson('/api/v1/suchak/customers', ['Accept-Language' => 'en'])
                ->assertForbidden()
                ->json('message'),
            'The api group is not applying admin-edited translations.'
        );
    }

    /**
     * Every string in a decoded body, keyed by its path, so a failure names the
     * field instead of just saying "somewhere in this response".
     *
     * @param  mixed  $value
     * @return array<string, string>
     */
    private function stringsIn($value, string $path = ''): array
    {
        if (is_string($value)) {
            return [$path === '' ? '(root)' : $path => $value];
        }

        if (! is_array($value)) {
            return [];
        }

        $found = [];
        foreach ($value as $key => $child) {
            $childPath = $path === '' ? (string) $key : $path.'.'.$key;
            $found += $this->stringsIn($child, $childPath);
        }

        return $found;
    }

    private function accountWithACustomer(): SuchakAccount
    {
        $account = SuchakAccount::factory()->create([
            'user_id' => User::factory()->create()->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'registration_completed_at' => now(),
        ]);

        SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => MatrimonyProfile::factory()->create()->id,
        ]);

        return $account;
    }
}
