<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakConsent;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPolicy;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Services\SuchakConsentService;
use App\Modules\Suchak\Services\SuchakPackageCatalogService;
use App\Modules\Suchak\Services\SuchakPaymentRequestService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The WEB half of the contract {@see SuchakApiLanguageContractTest} pins for the
 * API: whatever language the reader asked for, that is the language the page
 * answers in — all of it, not most of it.
 *
 * These four surfaces are where breaking that rule costs the most, because they
 * are the pages a FAMILY opens from a link with no login: the consent letter,
 * the fee agreement, the payment request, and the consent modal a Suchak fills
 * in on their behalf. All four were written as hardcoded Devanagari (the
 * agreement page even declared `<html lang="mr">`), or as their own private
 * `$isMr ? [...] : [...]` array, which is a second translation mechanism that
 * the admin-editable `translations` table cannot reach.
 *
 * THE DEFECT THAT FORCED THIS, and the reason the assertions below are sweeps
 * rather than a list of remembered labels: `stageLabel()` became locale-aware
 * first. The agreement page's installment rows started printing "Once the match
 * is settled" in the middle of Marathi prose, because the labels followed the
 * reader and the page around them did not. Half a translation is worse than
 * none, and this is the page where a family agrees a price.
 *
 * Each page is asserted in BOTH directions on purpose. A test that only checks
 * "no Devanagari under en" is satisfied by a page that has simply forgotten
 * Marathi exists — the opposite defect, equally bad, and the more likely one
 * after a conversion like this.
 */
class SuchakPublicPageLanguageContractTest extends TestCase
{
    use RefreshDatabase;

    /** Devanagari — the alphabet that must not appear in an English page. */
    private const DEVANAGARI = '/[\x{0900}-\x{097F}]/u';

    /**
     * The consent letter: the only thing the person being represented sees
     * before their biodata is shown to anybody.
     */
    public function test_the_public_consent_page_answers_in_the_readers_language(): void
    {
        $token = $this->consentToken();
        $url = route('suchak.consents.public.show', ['token' => $token]);

        $this->assertNoDevanagari($this->body($url, 'en'), 'the public consent page');
        $this->assertHasDevanagari($this->body($url, 'mr'), 'the public consent page');
    }

    /**
     * The gendered name label above the candidate's name used to be a Marathi
     * literal built in PublicConsentController, so it stayed Devanagari however
     * the page was asked for. The controller now sends the KEY and the page owns
     * the wording.
     */
    public function test_the_consent_pages_candidate_label_follows_the_reader(): void
    {
        $token = $this->consentToken();
        $url = route('suchak.consents.public.show', ['token' => $token]);

        $this->assertStringContainsString('Bride&#039;s name', $this->body($url, 'en'));
        $this->assertStringContainsString('वधूचे नाव', $this->body($url, 'mr'));
    }

    /** The fee agreement: the page where a family agrees a price. */
    public function test_the_public_agreement_page_answers_in_the_readers_language(): void
    {
        [, $token] = $this->agreementToken();
        $url = route('suchak.agreements.public.show', ['token' => $token]);

        $this->assertNoDevanagari($this->body($url, 'en'), 'the public agreement page');
        $this->assertHasDevanagari($this->body($url, 'mr'), 'the public agreement page');
    }

    /**
     * THE ORIGINAL DEFECT, pinned where it was seen.
     *
     * Every installment row names a stage, and stage names are server-owned and
     * already translated. When the page around them was Marathi-only, an English
     * reader got English stage names inside Marathi sentences. Both sides of
     * each row must now be in one language.
     */
    public function test_installment_rows_and_the_page_around_them_speak_one_language(): void
    {
        [, $token] = $this->agreementToken([
            'success_fee_tranches' => [
                ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED, 'share_percent' => 10],
                ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, 'share_percent' => 40],
                ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => 50],
            ],
        ]);
        $url = route('suchak.agreements.public.show', ['token' => $token]);

        $english = $this->body($url, 'en');
        // The stage names really are on the page — otherwise "no Devanagari"
        // would pass on a page that simply lost its installment rows.
        $this->assertStringContainsString('Once the match is settled', $english);
        $this->assertStringContainsString('After the engagement', $english);
        $this->assertStringContainsString('Remaining amount', $english);
        $this->assertNoDevanagari($english, 'the agreement page with installment rows');

        $marathi = $this->body($url, 'mr');
        $this->assertStringContainsString('लग्न ठरल्यावर', $marathi);
        $this->assertStringContainsString('साखरपुड्यानंतर', $marathi);
        $this->assertStringContainsString('उर्वरित रक्कम', $marathi);
        // The stage names must NOT still be English inside a Marathi page.
        $this->assertStringNotContainsString('Once the match is settled', $marathi);
    }

    /**
     * Amounts are not language. Latin digits and Indian grouping (₹1,00,000)
     * are a frozen product rule and must survive the switch in both directions.
     */
    public function test_money_stays_latin_digits_and_indian_grouping_in_both_languages(): void
    {
        [, $token] = $this->agreementToken();
        $url = route('suchak.agreements.public.show', ['token' => $token]);

        foreach (['en', 'mr'] as $locale) {
            $body = $this->body($url, $locale);
            $this->assertStringContainsString('₹15,000', $body, "Amount lost its grouping under {$locale}.");
            $this->assertStringContainsString('₹25,000', $body, "Amount lost its grouping under {$locale}.");
            $this->assertDoesNotMatchRegularExpression(
                '/[\x{0966}-\x{096F}]/u',
                $body,
                "Devanagari digits reached the reader under {$locale}.",
            );
        }
    }

    /** The payment request: the page a family opens from a WhatsApp link to pay. */
    public function test_the_public_payment_request_page_answers_in_the_readers_language(): void
    {
        $token = $this->paymentRequestToken();
        $url = route('suchak.payment-requests.show', ['token' => $token]);

        $this->assertNoDevanagari($this->body($url, 'en'), 'the public payment request page');
        $this->assertHasDevanagari($this->body($url, 'mr'), 'the public payment request page');
    }

    /**
     * The consent modal is a partial, so it is rendered directly rather than
     * through a route — the point is the partial's own vocabulary, not whichever
     * Suchak screen happens to include it today.
     */
    public function test_the_consent_action_modal_answers_in_the_readers_language(): void
    {
        $this->assertNoDevanagari($this->modalHtml('en'), 'the consent action modal');
        $this->assertHasDevanagari($this->modalHtml('mr'), 'the consent action modal');
    }

    /**
     * The consent-giver relation list had THREE copies — English in the
     * dashboard, English again as the partial's default, and a Marathi array in
     * the partial that silently overrode both. One list now, so the option a
     * Suchak picks and the value that gets stored cannot drift apart.
     */
    public function test_the_relation_list_has_one_owner_and_follows_the_reader(): void
    {
        $english = $this->modalHtml('en');
        $this->assertStringContainsString('<option value="father"', $english);
        $this->assertStringContainsString('Father', $english);

        $marathi = $this->modalHtml('mr');
        $this->assertStringContainsString('<option value="father"', $marathi);
        $this->assertStringContainsString('वडील', $marathi);
    }

    // ---------------------------------------------------------------- helpers

    private function body(string $url, string $locale): string
    {
        // ?locale= is how a family actually switches this page (the layout's own
        // switcher links to it), so the test switches the same way rather than
        // reaching past the middleware with setLocale().
        $separator = str_contains($url, '?') ? '&' : '?';

        $html = $this->withSession([])
            ->get($url.$separator.'locale='.$locale)
            ->assertOk()
            ->getContent();

        $this->assertNoUnresolvedKeys($html, $url, $locale);

        return $html;
    }

    /**
     * A key that does not exist renders as its own dotted path, so a typo ships
     * "suchak.public_pages.agreement.accept_button" as the label on the button a
     * family presses to agree a price. Silent, and invisible to a
     * Devanagari sweep — both languages would be equally wrong.
     */
    private function assertNoUnresolvedKeys(string $html, string $where, string $locale): void
    {
        preg_match_all(
            '/\b(?:suchak|profile|nav|wizard)(?:\.[a-z_]+){2,}/',
            $html,
            $matches,
        );

        $this->assertSame(
            [],
            array_values(array_unique($matches[0])),
            "Unresolved translation keys rendered on {$where} under {$locale}.",
        );
    }

    private function assertNoDevanagari(string $html, string $what): void
    {
        $html = $this->withoutLegitimateDevanagari($html);

        // Quote enough of each offending run that the failure names the
        // sentence, not just the fact that one exists somewhere in 40kB of HTML.
        preg_match_all('/[\x{0900}-\x{097F}].{0,60}/u', $html, $matches);

        $this->assertDoesNotMatchRegularExpression(
            self::DEVANAGARI,
            $html,
            "Asked in English, {$what} answered with Devanagari: ".implode(' | ', array_slice($matches[0], 0, 5)),
        );
    }

    /**
     * Two things on an English page are Devanagari on purpose, and both come
     * from the shared layout rather than from the pages under test:
     *
     *  - The language switcher labels its Marathi option "मराठी". A language
     *    picker names each language in that language; rendering it as "Marathi"
     *    would leave a Marathi reader hunting for their own language in a script
     *    they came to this control to get away from.
     *  - The site name is a proper noun. Brands are not translated, and it is
     *    read from SiteIdentityService here rather than hardcoded so the test
     *    keeps agreeing with whatever the brand actually is.
     *
     * Everything else is a defect, which is the whole point of removing exactly
     * these two and asserting on the remainder.
     */
    private function withoutLegitimateDevanagari(string $html): string
    {
        $allowed = array_filter([
            'मराठी',
            app(\App\Services\SiteIdentityService::class)->get('site_name', 'नवरी मिळे नवऱ्याला'),
        ]);

        return str_replace($allowed, '', $html);
    }

    private function assertHasDevanagari(string $html, string $what): void
    {
        $this->assertMatchesRegularExpression(
            self::DEVANAGARI,
            $html,
            "Asked in Marathi, {$what} came back with no Marathi in it at all.",
        );
    }

    private function modalHtml(string $locale): string
    {
        app()->setLocale($locale);

        $html = $this->renderModal();
        $this->assertNoUnresolvedKeys($html, 'the consent action modal', $locale);

        return $html;
    }

    private function renderModal(): string
    {
        return view('suchak.partials.consent-action-modal', [
            'representationId' => 1,
            'modalKey' => 'language-contract',
            'consentAction' => '/suchak/representations/1/consent',
            'defaultConsentMobile' => '9876543210',
            'defaultConsentGiverName' => 'Candidate Parent',
            'defaultConsentRelation' => 'candidate_self',
            'defaultConsentType' => SuchakConsent::TYPE_ONE_YEAR,
        ])->render();
    }

    /** A live public consent token for a female candidate. */
    private function consentToken(): string
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        $gender = \App\Models\MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true],
        );

        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Represented Candidate',
            'gender_id' => $gender->id,
            'date_of_birth' => now()->subYears(26)->toDateString(),
            'father_name' => 'Candidate Parent',
            'father_contact_1' => '9876543210',
        ]);

        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
        ]);

        $result = app(SuchakConsentService::class)->createSuchakRelayedLinkConsent(
            $representation,
            $user,
            [
                'consent_given_by_name' => 'Candidate Parent',
                'consent_giver_relation' => 'father',
                'intended_mobile' => '9876543210',
            ],
            '127.0.0.1',
            'Language contract test',
        );

        return $result['raw_token'];
    }

    /**
     * A pending agreement with all four fees set, plus its public acceptance
     * token.
     *
     * @param  array<string, mixed>  $agreementAttributes
     * @return array{0: SuchakCustomerAgreement, 1: string}
     */
    private function agreementToken(array $agreementAttributes = []): array
    {
        [$user, $account] = $this->verifiedAccount();
        $this->autoPublishPackages();

        $package = app(SuchakPackageCatalogService::class)->createCustomPackage(
            $account,
            $user,
            [
                'package_name' => 'Family Coordination',
                'price_amount' => '15000',
                'currency' => 'INR',
                'per_meeting_fee_amount' => '500',
                'per_meeting_online_fee_amount' => '300',
                'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_FIXED,
                'post_marriage_fee_amount' => '25000',
            ],
            [[
                'stage_key' => 'intake_and_shortlist',
                'stage_name' => 'Intake and shortlist',
                'sort_order' => 10,
                'expected_days' => 7,
            ]],
            [[
                'stage_key' => 'intake_and_shortlist',
                'deliverable_key' => 'shortlist_report',
                'deliverable_name' => 'Shortlist report',
                'sort_order' => 10,
            ]],
            null,
            null,
            null,
            true,
        );

        $agreement = app(SuchakAgreementService::class)->createAgreementForPackage(
            $package->fresh(['suchakAccount', 'stages', 'deliverables.servicePackageStage']),
            $user,
            $agreementAttributes,
        );

        $link = app(SuchakAgreementService::class)->issueAcceptanceLink($agreement, $user);

        return [$agreement, $link['raw_token']];
    }

    /** A sent payment request collected by the Suchak, plus its public token. */
    private function paymentRequestToken(): string
    {
        [$user, $account] = $this->verifiedAccount();
        $this->autoPublishPackages();

        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Paying Candidate',
            'date_of_birth' => now()->subYears(27)->toDateString(),
        ]);

        $customerContext = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'payer_name' => 'Candidate family',
            'payer_relationship_to_candidate' => 'Parent',
            'service_context' => SuchakCustomerContext::SERVICE_PACKAGE_LEAD,
            'source_owner' => SuchakPaymentContext::SOURCE_SUCHAK,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $user->id,
            'classified_by_user_id' => $user->id,
            'classified_at' => now(),
            'opened_at' => now(),
        ]);

        $package = app(SuchakPackageCatalogService::class)->createCustomPackage(
            $account,
            $user,
            [
                'package_name' => 'Family Coordination',
                'package_description' => 'Structured customer package.',
                'price_amount' => '15000',
                'currency' => 'INR',
            ],
            [[
                'stage_key' => 'intake_and_shortlist',
                'stage_name' => 'Intake and shortlist',
                'sort_order' => 10,
                'expected_days' => 7,
            ]],
            [[
                'stage_key' => 'intake_and_shortlist',
                'deliverable_key' => 'shortlist_report',
                'deliverable_name' => 'Shortlist report',
                'sort_order' => 10,
            ]],
            $customerContext,
        );

        $agreement = app(SuchakAgreementService::class)->createAgreementForPackage(
            $package,
            $user,
            [
                'agreement_title' => 'Agreement terms',
                'agreement_body' => 'Customer confirms the package scope before payment.',
            ],
        );
        $agreement = app(SuchakAgreementService::class)->acceptTerms($agreement, $user);

        $paymentContext = SuchakPaymentContext::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'matrimony_profile_id' => $profile->id,
            'source_owner' => SuchakPaymentContext::SOURCE_SUCHAK,
            'payment_collector' => SuchakPaymentContext::COLLECTOR_SUCHAK,
            'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
            'resolved_by_user_id' => $user->id,
            'resolution_note' => 'Language contract fixture.',
        ]);

        $result = app(SuchakPaymentRequestService::class)->createAndSend(
            $package->fresh(['suchakAccount.user', 'customerContext', 'stages', 'deliverables.servicePackageStage']),
            $agreement->fresh(['suchakAccount', 'customerContext', 'servicePackage', 'stages', 'deliverables']),
            $paymentContext->fresh(['suchakAccount', 'customerContext', 'matrimonyProfile']),
            $user,
            [
                'request_title' => 'Service payment',
                'request_note' => 'Please review the agreed terms before arranging payment.',
            ],
            '127.0.0.1',
            'Language contract test',
        );

        return $result['plain_token'];
    }

    /** @return array{0: User, 1: SuchakAccount} */
    private function verifiedAccount(): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        return [$user, $account];
    }

    private function autoPublishPackages(): void
    {
        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE],
            [
                'policy_value' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Auto publish packages for the language contract fixture.',
                'is_active' => true,
            ],
        );
    }
}
