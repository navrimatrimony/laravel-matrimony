<?php

namespace Tests\Feature\Suchak;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A refusal is only translated when BOTH halves of it are.
 *
 * A Suchak signing in on a real phone was answered by
 * `POST /api/v1/suchak/login/otp/send` with:
 *
 *     terms accepted स्वीकारणे आवश्यक आहे.
 *
 * The sentence came from `lang/mr/validation.php`; the field name did not.
 * `validation.attributes` was an empty array in both languages, so Laravel fell
 * back to its last resort — the raw request field with its underscores swapped
 * for spaces — and dropped an English identifier into the middle of a Marathi
 * sentence. Half-translated is worse than untranslated: it reads as unfinished
 * software to the one person the product is being sold to.
 *
 * The sweep at the bottom is the test that matters most. It names no field: it
 * walks the Suchak API controllers, collects every attribute they validate, and
 * fails when a NEW one is added without a name in both languages.
 */
class SuchakValidationAttributeNamesTest extends TestCase
{
    use RefreshDatabase;

    /** Devanagari — the alphabet a Marathi refusal is supposed to be written in. */
    private const DEVANAGARI = '/[\x{0900}-\x{097F}]/u';

    private const OTP_SEND_ROUTE = '/api/v1/suchak/login/otp/send';

    /**
     * THE REPORTED DEFECT, pinned as the exact sentence a Suchak reads.
     *
     * Asserted as a whole sentence rather than "contains सेवा अटी", because the
     * defect was never a missing word — it was an English word sitting where a
     * Marathi one belonged, and only the full sentence shows that.
     */
    public function test_the_consent_refusal_names_the_field_in_marathi_not_english(): void
    {
        $errors = $this->refusalFor('mr');

        $this->assertSame('सेवा अटी स्वीकारणे आवश्यक आहे.', $errors['terms_accepted'][0]);
        $this->assertSame('गोपनीयता धोरण स्वीकारणे आवश्यक आहे.', $errors['privacy_accepted'][0]);
    }

    /**
     * The class of defect, not the one instance: no Marathi refusal from this
     * request may carry a Latin-lettered run that is really a request field.
     * `OTP` and `WhatsApp` are words a Marathi speaker reads as words; the field
     * names this asserts against are not.
     */
    public function test_no_marathi_refusal_from_the_sign_in_request_leaks_a_raw_field_name(): void
    {
        foreach ($this->refusalFor('mr') as $field => $messages) {
            foreach ($messages as $message) {
                $this->assertStringNotContainsString(
                    str_replace('_', ' ', $field),
                    $message,
                    "The Marathi refusal for `{$field}` still prints the raw English field: \"{$message}\""
                );
                $this->assertMatchesRegularExpression(
                    self::DEVANAGARI,
                    $message,
                    "The refusal for `{$field}` came back with no Marathi in it at all: \"{$message}\""
                );
            }
        }
    }

    /**
     * The mirror. An English caller must get an English sentence with no
     * Devanagari in it — otherwise "both languages are named" could be
     * satisfied by a server that simply answers Marathi to everyone.
     */
    public function test_the_same_refusal_reads_naturally_in_english(): void
    {
        $errors = $this->refusalFor('en');

        $this->assertSame('The Terms of Service must be accepted.', $errors['terms_accepted'][0]);
        $this->assertSame('The Privacy Policy must be accepted.', $errors['privacy_accepted'][0]);

        foreach ($errors as $field => $messages) {
            foreach ($messages as $message) {
                $this->assertDoesNotMatchRegularExpression(
                    self::DEVANAGARI,
                    $message,
                    "The English refusal for `{$field}` leaks Marathi: \"{$message}\""
                );
            }
        }
    }

    /**
     * Both files must describe the same world. A name that exists only in
     * English is exactly how the English field name reappears under Marathi.
     */
    public function test_both_languages_name_the_same_attributes(): void
    {
        $english = trans('validation.attributes', [], 'en');
        $marathi = trans('validation.attributes', [], 'mr');

        $this->assertIsArray($english);
        $this->assertNotEmpty($english, 'The English attribute map is empty — the fallback is back.');

        $this->assertSame(
            array_keys($english),
            array_keys($marathi),
            'The two languages name different sets of attributes.'
        );
    }

    /**
     * Every Marathi name must actually be a translation, never the raw field
     * echoed back. This is what the empty map used to produce for all 153
     * fields at once.
     */
    public function test_no_marathi_attribute_name_is_the_raw_english_field(): void
    {
        foreach (trans('validation.attributes', [], 'mr') as $field => $name) {
            $this->assertNotSame(
                str_replace('_', ' ', (string) $field),
                (string) $name,
                "`{$field}` is still Laravel's raw fallback under Marathi."
            );
            $this->assertNotSame('', trim((string) $name), "`{$field}` has an empty Marathi name.");
        }
    }

    /**
     * THE SWEEP, and the reason this file is worth keeping.
     *
     * It names no field. It reads what the Suchak API controllers actually
     * validate today, so the next `'consent_witness_name' => ['required']`
     * someone adds fails here on the day it is written rather than on the day a
     * Suchak reads "consent witness name आवश्यक आहे." on his phone.
     */
    public function test_every_suchak_facing_validated_field_is_named_in_both_languages(): void
    {
        $fields = $this->fieldsValidatedBySuchakControllers();

        $this->assertGreaterThan(
            100,
            count($fields),
            'The scan found almost nothing — it has stopped seeing the validation blocks.'
        );

        $english = trans('validation.attributes', [], 'en');
        $marathi = trans('validation.attributes', [], 'mr');

        $unnamed = array_values(array_filter(
            $fields,
            fn (string $field): bool => ! isset($english[$field]) || ! isset($marathi[$field]),
        ));

        $this->assertSame(
            [],
            $unnamed,
            "These Suchak-facing fields have no name in lang/{en,mr}/validation.php, so a refusal about them "
            ."prints the raw English field:\n  ".implode("\n  ", $unnamed)
        );
    }

    /**
     * The 422 body for a sign-in attempt that accepts neither document.
     *
     * `terms_accepted` / `privacy_accepted` are simply absent, which is what the
     * `accepted` rule refuses — the same shape the real app produced.
     *
     * @return array<string, list<string>>
     */
    private function refusalFor(string $locale): array
    {
        $errors = $this->postJson(
            self::OTP_SEND_ROUTE,
            [
                'mobile' => '9876543210',
                'terms_version' => '2026-01-01',
                'privacy_version' => '2026-01-01',
            ],
            ['Accept-Language' => $locale],
        )->assertStatus(422)->json('errors');

        $this->assertArrayHasKey('terms_accepted', $errors);
        $this->assertArrayHasKey('privacy_accepted', $errors);

        return $errors;
    }

    /**
     * Every attribute named inside a `validate([...])` or `Validator::make(…,
     * [...])` rule set in the Suchak API controllers, plus the member OTP
     * controller — which shares the very consent fields this file is about.
     *
     * @return list<string>
     */
    private function fieldsValidatedBySuchakControllers(): array
    {
        $files = array_merge(
            glob(app_path('Http/Controllers/Api/Suchak/*.php')) ?: [],
            [app_path('Http/Controllers/Api/MobileOtpController.php')],
        );

        $fields = [];
        foreach ($files as $file) {
            $source = file_get_contents($file);
            if ($source === false) {
                continue;
            }

            foreach ($this->ruleSetsIn($source) as $ruleSet) {
                preg_match_all("/'([a-zA-Z0-9_.*]+)'\s*=>\s*\[/", $ruleSet, $matches);
                foreach ($matches[1] as $field) {
                    $fields[$field] = true;
                }
            }
        }

        $found = array_keys($fields);
        sort($found);

        return $found;
    }

    /**
     * The `[ … ]` immediately following each `validate(` / `Validator::make(`,
     * matched by counting brackets so nested rule arrays stay inside their own
     * rule set instead of ending it early.
     *
     * @return list<string>
     */
    private function ruleSetsIn(string $source): array
    {
        $ruleSets = [];
        $offset = 0;

        while (preg_match('/(?:->validate|Validator::make)\s*\(/', $source, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $offset = $match[0][1] + strlen($match[0][0]);
            $open = strpos($source, '[', $offset);
            if ($open === false) {
                break;
            }

            $depth = 0;
            for ($i = $open, $length = strlen($source); $i < $length; $i++) {
                if ($source[$i] === '[') {
                    $depth++;
                } elseif ($source[$i] === ']') {
                    $depth--;
                    if ($depth === 0) {
                        $ruleSets[] = substr($source, $open, $i - $open + 1);
                        break;
                    }
                }
            }
        }

        return $ruleSets;
    }
}
