<?php

namespace Tests\Feature\Suchak;

use App\Models\Caste;
use App\Models\City;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPipeline;
use App\Models\SuchakProfileNote;
use App\Models\SuchakProfileRepresentation;
use App\Models\Translation;
use App\Models\User;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    /** This Suchak's own consented customer — his book, his to see by name. */
    private const OWN_CUSTOMER_NAME = 'Sunita Lang Contract';

    /** Another Suchak's candidate — masked, and never named in prose. */
    private const OTHER_SUCHAKS_CANDIDATE_NAME = 'Ganesh Other Bureau';

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
     * THE FIRST SCREEN. `/suchak/dashboard` is the "आज" feed a Suchak opens
     * every morning, and it was the worst offender in the product: the whole
     * worklist was built from English string literals, so a Marathi app showed
     * "Collaboration opportunity" as a title and — worse — a sentence whose
     * first half was Marathi (the fit summary, which came from the matching
     * engine's lang file) and whose second half was hardcoded English.
     */
    public function test_the_daily_worklist_carries_no_devanagari_under_english(): void
    {
        $account = $this->accountWithAFullWorklist();
        Sanctum::actingAs($account->user);

        $cards = $this->getJson('/api/v1/suchak/dashboard', ['Accept-Language' => 'en'])
            ->assertOk()
            ->json('data.worklist');

        // Pinned, so the sweep cannot quietly shrink to one card type while
        // still "passing". Every kind the engine can produce is exercised.
        $this->assertEqualsCanonicalizing(
            ['follow_up_due', 'consent_expiring', 'pdf_missing', 'sla_risk', 'payment_due', 'collaboration_opportunity'],
            array_values(array_unique(array_column($cards, 'type'))),
            'The fixture stopped covering every worklist card type.'
        );

        foreach ($cards as $index => $card) {
            foreach (['label', 'reason', 'action_label'] as $field) {
                $this->assertDoesNotMatchRegularExpression(
                    self::DEVANAGARI,
                    (string) $card[$field],
                    "Card #{$index} ({$card['type']}) answered Accept-Language: en with Marathi in `{$field}`: \"{$card[$field]}\""
                );
            }
        }
    }

    /**
     * The mirror, and it is asserted PER CARD rather than "somewhere in the
     * body". A half-translated feed satisfies "the response contains some
     * Marathi"; it does not satisfy "every card a Suchak reads is Marathi",
     * which is what he actually experiences.
     */
    public function test_every_worklist_card_is_marathi_when_asked(): void
    {
        $account = $this->accountWithAFullWorklist();
        Sanctum::actingAs($account->user);

        $cards = $this->getJson('/api/v1/suchak/dashboard', ['Accept-Language' => 'mr'])
            ->assertOk()
            ->json('data.worklist');

        $this->assertNotEmpty($cards, 'The worklist fixture produced no cards, so this test proves nothing.');

        foreach ($cards as $index => $card) {
            foreach (['label', 'reason', 'action_label'] as $field) {
                $this->assertMatchesRegularExpression(
                    self::DEVANAGARI,
                    (string) $card[$field],
                    "Card #{$index} ({$card['type']}) is still English in `{$field}` under Accept-Language: mr: \"{$card[$field]}\""
                );
            }
        }
    }

    /**
     * A Suchak was shown "Reference: masked-def044cc4c1d." in the middle of a
     * sentence. He cannot act on it, look it up, or say it to a family — it is
     * an internal hash, and putting one in front of a human makes a finished
     * product look unfinished.
     *
     * The mask itself is not the defect and is not removed: `candidate_reference`
     * still carries it as a FIELD, which is where the cross-Suchak mask belongs.
     * The defect was the mask being used as PROSE, in either language.
     */
    public function test_no_worklist_card_puts_an_internal_reference_in_its_prose(): void
    {
        $account = $this->accountWithAFullWorklist();
        Sanctum::actingAs($account->user);

        foreach (['en', 'mr'] as $locale) {
            $cards = $this->getJson('/api/v1/suchak/dashboard', ['Accept-Language' => $locale])
                ->assertOk()
                ->json('data.worklist');

            $this->assertNotEmpty($cards);

            foreach ($cards as $card) {
                foreach (['label', 'reason', 'action_label'] as $field) {
                    $this->assertStringNotContainsString(
                        'masked-',
                        (string) $card[$field],
                        "[{$locale}] {$card['type']} still prints an internal reference in `{$field}`: \"{$card[$field]}\""
                    );
                }
            }
        }

        // …and the field itself is untouched, so nothing downstream lost its key.
        $withReference = collect(
            $this->getJson('/api/v1/suchak/dashboard', ['Accept-Language' => 'en'])->json('data.worklist')
        )->filter(fn (array $card): bool => $card['candidate_reference'] !== null);

        $this->assertNotEmpty($withReference, 'No card carried candidate_reference at all — the contract key was dropped.');
        foreach ($withReference as $card) {
            $this->assertStringStartsWith('masked-', (string) $card['candidate_reference']);
        }
    }

    /**
     * The replacement for the hash. The opportunity is scored against the
     * Suchak's OWN consented customer — a person his own customer list already
     * names in full — so the card names them. The other Suchak's candidate is
     * NOT named; that one stays behind `candidate_reference`.
     */
    public function test_the_collaboration_card_names_the_suchaks_own_customer(): void
    {
        $account = $this->accountWithAFullWorklist();
        Sanctum::actingAs($account->user);

        $card = collect($this->getJson('/api/v1/suchak/dashboard', ['Accept-Language' => 'mr'])->json('data.worklist'))
            ->firstWhere('type', 'collaboration_opportunity');

        $this->assertNotNull($card, 'The fixture produced no collaboration opportunity.');
        $this->assertStringContainsString(self::OWN_CUSTOMER_NAME, (string) $card['reason']);
        $this->assertStringNotContainsString(self::OTHER_SUCHAKS_CANDIDATE_NAME, (string) $card['reason']);
    }

    /**
     * Not every worklist key is reachable from one fixture (a payment request
     * with no expiry, a platform lead's SLA, a ledger row with no amount
     * recorded). Those are exactly the branches that rot into English, so the
     * whole group is swept statically as well: every leaf worded in both
     * languages, and no leaf where the two languages are the same string.
     */
    public function test_every_worklist_key_is_worded_in_both_languages(): void
    {
        $english = Arr::dot(trans('suchak.worklist', [], 'en'));
        $marathi = Arr::dot(trans('suchak.worklist', [], 'mr'));

        $this->assertNotEmpty($english);
        $this->assertSame(array_keys($english), array_keys($marathi), 'The two languages define different worklist keys.');

        foreach ($english as $key => $value) {
            $this->assertDoesNotMatchRegularExpression(
                self::DEVANAGARI,
                $value,
                "suchak.worklist.{$key} leaks Marathi into the English wording."
            );
            $this->assertMatchesRegularExpression(
                self::DEVANAGARI,
                $marathi[$key],
                "suchak.worklist.{$key} was never translated — it is still English under mr."
            );
            $this->assertNotSame(
                $value,
                $marathi[$key],
                "suchak.worklist.{$key} is the same string in both languages."
            );
        }
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

    /**
     * One Suchak whose morning feed carries a card of every kind the engine can
     * produce: a due follow-up, an expiring consent, a customer with no biodata
     * PDF, a pipeline whose hold is running out, money due in the ledger, and a
     * collaboration opportunity against another Suchak's candidate.
     *
     * Names are deliberately Latin so that "no Devanagari under en" stays a
     * statement about the WORDING and never accidentally about the data.
     */
    private function accountWithAFullWorklist(): SuchakAccount
    {
        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();

        $now = now();
        $religion = Religion::query()->create([
            'key' => 'lang_contract_religion',
            'label' => 'Lang Contract Religion',
            'label_en' => 'Lang Contract Religion',
            'is_active' => true,
        ]);
        $caste = Caste::query()->create([
            'religion_id' => $religion->id,
            'key' => 'lang_contract_caste',
            'label' => 'Lang Contract Caste',
            'label_en' => 'Lang Contract Caste',
            'is_active' => true,
        ]);

        $account = SuchakAccount::factory()->create([
            'user_id' => User::factory()->create()->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'registration_completed_at' => now(),
        ]);

        $ownProfile = $this->activeProfile([
            'full_name' => self::OWN_CUSTOMER_NAME,
            'gender_id' => $this->genderId('male'),
            'religion_id' => $religion->id,
            'caste_id' => $caste->id,
        ]);
        $ownRepresentation = $this->consentedRepresentation($account, $ownProfile, [
            // Inside the 7-day window, so this same customer also raises the
            // consent-expiring card. No PDF export exists either, so he raises
            // the biodata-PDF card too.
            'consent_valid_until' => $now->copy()->addDays(2),
        ]);

        SuchakProfileNote::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $ownProfile->id,
            'note_type' => SuchakProfileNote::TYPE_FOLLOW_UP,
            'follow_up_at' => $now->copy()->subHour(),
        ]);

        SuchakLedgerEntry::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $ownProfile->id,
            'status' => SuchakLedgerEntry::STATUS_DUE,
            'amount' => 5000,
            'currency' => 'INR',
            'due_date' => $now->copy()->subDay()->toDateString(),
        ]);

        SuchakPipeline::factory()->create([
            'selected_suchak_account_id' => $account->id,
            'target_matrimony_profile_id' => $ownProfile->id,
            'representation_id' => $ownRepresentation->id,
            'pipeline_status' => SuchakPipeline::STATUS_PENDING,
            'lock_expires_at' => $now->copy()->addHours(2),
        ]);

        $otherAccount = SuchakAccount::factory()->create([
            'user_id' => User::factory()->create()->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'registration_completed_at' => now(),
        ]);
        $otherProfile = $this->activeProfile([
            'full_name' => self::OTHER_SUCHAKS_CANDIDATE_NAME,
            'gender_id' => $this->genderId('female'),
            'religion_id' => $religion->id,
            'caste_id' => $caste->id,
        ]);
        $this->consentedRepresentation($otherAccount, $otherProfile, [
            'consent_valid_until' => $now->copy()->addYear(),
        ]);

        return $account;
    }

    private function genderId(string $key): int
    {
        return (int) MasterGender::query()->firstOrCreate(
            ['key' => $key],
            ['label' => ucfirst($key), 'is_active' => true],
        )->id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeProfile(array $attributes): MatrimonyProfile
    {
        $city = City::query()->where('name', 'Pune City')->firstOrFail();

        $profile = MatrimonyProfile::factory()->create(array_merge([
            'date_of_birth' => now()->subYears(29)->toDateString(),
            'highest_education' => 'Graduate',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], $attributes));

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $city->id]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, (int) $city->id, null, true, false);
        }

        $profile->forceFill(['lifecycle_state' => 'active', 'is_suspended' => false])->save();

        return $profile->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function consentedRepresentation(
        SuchakAccount $account,
        MatrimonyProfile $profile,
        array $attributes = [],
    ): SuchakProfileRepresentation {
        return SuchakProfileRepresentation::factory()->create(array_merge([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ], $attributes));
    }
}
