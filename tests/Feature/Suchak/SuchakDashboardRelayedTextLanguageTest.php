<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakRequestPipelineService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RELAYED WHATSAPP TEXT FOLLOWS THE SUCHAK'S LANGUAGE.
 *
 * The share-card message and the three profile-request reply templates on
 * `resources/views/suchak/dashboard.blade.php` are composed on the SUCHAK's screen and read by a
 * family in WhatsApp. Product owner's decision: they follow the SENDER's locale — the Suchak's —
 * which is the locale SetLocaleFromQuery has already resolved for that page.
 *
 * Both were hardcoded Marathi, so a Suchak working in English copied Marathi out of his own English
 * screen. What this class pins is not that `__()` was typed, but that the rendered page actually
 * answers in the resolved language and carries none of the other one.
 */
class SuchakDashboardRelayedTextLanguageTest extends TestCase
{
    use RefreshDatabase;

    private const MARATHI_TAGLINE = 'लग्न जुळवण्यासाठी विश्वासार्ह सूचक सेवा. अधिक माहितीसाठी संपर्क करा.';

    private const ENGLISH_TAGLINE = 'Trusted matchmaking service for arranging marriages. Get in touch for more details.';

    private const MARATHI_TEMPLATES = [
        'मी हे स्थळ संबंधित कुटुंबाला दाखवतो. उत्तर आले की तुम्हाला कळवतो.',
        'या स्थळाबद्दल अधिक माहिती देण्यासाठी कृपया तुमचा संपर्क क्रमांक आणि सोयीची वेळ पाठवा.',
        'हे स्थळ सध्या चर्चेत आहे. पुढील माहिती मिळताच तुम्हाला कळवतो.',
    ];

    private const ENGLISH_TEMPLATES = [
        'I will show this profile to the family. I will let you know as soon as they answer.',
        'Please send your contact number and a convenient time so I can share more about this profile.',
        'This profile is already under discussion. I will update you as soon as I know more.',
    ];

    public function test_the_share_card_message_is_marathi_when_the_suchak_reads_marathi(): void
    {
        [$user, $account] = $this->suchakWithAnOpenProfileRequest();
        unset($account);

        $html = $this->actingAs($user)->get('/suchak/dashboard?locale=mr')->assertOk()->getContent();

        // The message travels inside the wa.me link, so it is the ENCODED text that must be
        // Marathi — asserting on the visible page would not prove what the family receives.
        $this->assertStringContainsString(rawurlencode(self::MARATHI_TAGLINE), $html);
        $this->assertStringNotContainsString(rawurlencode(self::ENGLISH_TAGLINE), $html);
    }

    public function test_the_share_card_message_is_english_when_the_suchak_reads_english(): void
    {
        [$user, $account] = $this->suchakWithAnOpenProfileRequest();
        unset($account);

        $html = $this->actingAs($user)->get('/suchak/dashboard?locale=en')->assertOk()->getContent();

        $this->assertStringContainsString(rawurlencode(self::ENGLISH_TAGLINE), $html);
        $this->assertStringNotContainsString(rawurlencode(self::MARATHI_TAGLINE), $html);
    }

    public function test_the_number_in_the_share_card_keeps_latin_digits_in_both_languages(): void
    {
        [$user, $account] = $this->suchakWithAnOpenProfileRequest();
        $number = (string) ($account->whatsapp_number ?: $account->mobile_number);
        $this->assertNotSame('', $number);

        foreach (['mr', 'en'] as $locale) {
            $html = $this->actingAs($user)->get('/suchak/dashboard?locale='.$locale)->assertOk()->getContent();

            // `:number` is substituted, never number_format'ed, so the frozen rule holds: every
            // digit a family reads is Latin 0-9 whichever language the Suchak chose.
            $this->assertStringContainsString(rawurlencode('WhatsApp: '.$number), $html, 'locale='.$locale);
            $this->assertDoesNotMatchRegularExpression('/[\x{0966}-\x{096F}]/u', $number);
        }
    }

    public function test_the_three_reply_templates_answer_in_the_suchaks_language(): void
    {
        [$user, $account] = $this->suchakWithAnOpenProfileRequest();
        unset($account);

        $marathi = $this->actingAs($user)->get('/suchak/dashboard?locale=mr')->assertOk()->getContent();
        foreach (self::MARATHI_TEMPLATES as $template) {
            $this->assertStringContainsString($template, $marathi);
        }
        foreach (self::ENGLISH_TEMPLATES as $template) {
            $this->assertStringNotContainsString($template, $marathi);
        }

        $english = $this->actingAs($user)->get('/suchak/dashboard?locale=en')->assertOk()->getContent();
        foreach (self::ENGLISH_TEMPLATES as $template) {
            $this->assertStringContainsString($template, $english);
        }
        foreach (self::MARATHI_TEMPLATES as $template) {
            $this->assertStringNotContainsString($template, $english);
        }
    }

    /**
     * The wording lives in the lang files, not in the template. Without this a later edit could put
     * one language back into the Blade and every assertion above would still pass on that language.
     */
    public function test_the_relayed_wording_is_not_hardcoded_in_the_template(): void
    {
        $blade = (string) file_get_contents(resource_path('views/suchak/dashboard.blade.php'));

        foreach (array_merge(
            [self::MARATHI_TAGLINE, self::ENGLISH_TAGLINE],
            self::MARATHI_TEMPLATES,
            self::ENGLISH_TEMPLATES,
        ) as $wording) {
            $this->assertStringNotContainsString($wording, $blade);
        }

        foreach ([
            'suchak.dashboard.share_card_whatsapp_line',
            'suchak.dashboard.share_card_tagline',
            'suchak.dashboard.profile_request_reply_shown_to_family',
            'suchak.dashboard.profile_request_reply_ask_contact',
            'suchak.dashboard.profile_request_reply_under_discussion',
        ] as $key) {
            $this->assertStringContainsString($key, $blade);

            // Both languages carry a value — a key with only one is a blank on somebody's screen.
            foreach (['mr', 'en'] as $locale) {
                $this->assertNotSame($key, __($key, [], $locale), $key.' has no '.$locale.' wording.');
            }
        }
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────────────────────

    /**
     * A verified Suchak holding one OPEN member request, which is what makes the reply-template
     * block render at all.
     *
     * @return array{0: User, 1: SuchakAccount}
     */
    private function suchakWithAnOpenProfileRequest(): array
    {
        $suchakUser = User::factory()->create();
        /** @var SuchakAccount $account */
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'suchak_name' => 'Kishor Pawar',
            'office_name' => 'Pawar Vivah Sanstha',
            'whatsapp_number' => '9876543210',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        $candidateProfile = $this->activeProfile('Sunita Gaikwad');
        /** @var SuchakProfileRepresentation $representation */
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $candidateProfile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        $member = User::factory()->create();
        $memberProfile = $this->activeProfile('Amol Jadhav');
        $memberProfile->forceFill(['user_id' => $member->id])->save();

        $this->app->make(SuchakRequestPipelineService::class)->createRequest(
            $member,
            $memberProfile->fresh(),
            $representation->fresh(['suchakAccount', 'matrimonyProfile']),
        );

        return [$suchakUser->fresh(), $account->fresh()];
    }

    private function activeProfile(string $fullName): MatrimonyProfile
    {
        $state = $this->address('Maharashtra', 'state', 1, null);
        $district = $this->address('Pune', 'district', 2, $state);
        $taluka = $this->address('Shirur', 'taluka', 3, $district);
        $village = $this->address('Ranjangaon', 'village', 4, $taluka, 'rural');

        $profile = MatrimonyProfile::factory()->create([
            'full_name' => $fullName,
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $village]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $village, null, true, false);
        }

        $profile->update([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        return $profile->fresh();
    }

    private function address(string $name, string $hierarchy, int $level, ?int $parent, ?string $tag = null): int
    {
        return DB::table('addresses')->insertGetId(array_filter([
            'name' => $name,
            'slug' => strtolower($name).'-'.$hierarchy.'-'.uniqid('', true),
            'hierarchy' => $hierarchy,
            'level' => $level,
            'parent_id' => $parent,
            'tag' => $tag,
            'created_at' => now(),
            'updated_at' => now(),
        ], static fn ($v): bool => $v !== null));
    }
}
