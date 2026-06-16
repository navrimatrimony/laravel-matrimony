<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\MasterGender;
use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\SuchakPolicy;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SuchakConsentOperationalUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_no_consent_shows_get_consent_in_customer_detail_and_default_whatsapp_modal(): void
    {
        [$suchakUser, , $representation] = $this->pendingRepresentationFixture();

        $response = $this->actingAs($suchakUser)
            ->get($this->manageUrl($representation))
            ->assertOk()
            ->assertSee('Customer details', false)
            ->assertSee('Back to customer list', false)
            ->assertSee('Profile state', false)
            ->assertSee('Consent not active', false)
            ->assertSee('Consent is required before PDF/QR, public routing, and update suggestions.', false)
            ->assertSee('Get consent', false)
            ->assertSee('Consent type', false)
            ->assertSee('Send via WhatsApp', false)
            ->assertSee('Send on WhatsApp', false)
            ->assertSee('Other consent options', false)
            ->assertSee('Upload signed proof', false)
            ->assertSee('Platform-assisted consent', false)
            ->assertSee('value="9876543210"', false)
            ->assertSee('min-h-14', false)
            ->assertSee('mt-8 border-t', false)
            ->assertDontSee('name="evidence_note"', false)
            ->assertSee('sm:max-w-xl', false)
            ->assertDontSee('sm:max-w-3xl', false)
            ->assertDontSee('Choose consent method', false)
            ->assertDontSee('Customer list', false)
            ->assertDontSee('Mobile-matched secure link', false)
            ->assertDontSee('Enter customer OTP', false)
            ->assertDontSee('Verify consent', false)
            ->assertDontSee('Send new platform OTP', false)
            ->assertDontSee('Record OTP sent', false);

        $this->assertStringNotContainsString('verified mobile', strtolower($response->getContent()));
    }

    public function test_consent_modal_copy_follows_selected_marathi_language(): void
    {
        [$suchakUser, , $representation] = $this->pendingRepresentationFixture();

        $this->actingAs($suchakUser)
            ->get(route('suchak.dashboard', [
                'dashboard_tab' => 'profiles',
                'manage_representation' => $representation->id,
                'locale' => 'mr',
            ]))
            ->assertOk()
            ->assertSee('ग्राहक संमती विनंती तयार करा', false)
            ->assertSee('Profile मधील mobile default आहे. पाठवण्यापूर्वी Suchak तो बदलू शकतो.', false)
            ->assertSee('WhatsApp वर पाठवा', false)
            ->assertSee('उमेदवार स्वतः', false)
            ->assertSee('इतर consent options', false)
            ->assertDontSee('Create customer consent request', false)
            ->assertDontSee('name="evidence_note"', false);
    }

    public function test_suchak_relayed_request_creates_pending_link_and_public_yes_activates_consent(): void
    {
        [$suchakUser, , $representation] = $this->pendingRepresentationFixture();

        $response = $this->actingAs($suchakUser)
            ->post(route('suchak.representations.consents.request', $representation), [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_method' => SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
                'consent_given_by_name' => 'Candidate Parent',
                'consent_giver_relation' => 'father',
                'intended_mobile' => '9876543210',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Consent link created.')
            ->assertSessionHas('suchak_consent_url')
            ->assertSessionHas('suchak_consent_forward_message')
            ->assertSessionHas('suchak_consent_whatsapp_url');

        $consent = SuchakConsent::query()->where('representation_id', $representation->id)->firstOrFail();
        $consentUrl = (string) $response->getSession()->get('suchak_consent_url');
        $token = basename($consentUrl);

        $this->assertSame(SuchakConsent::STATUS_REQUESTED, $consent->consent_status);
        $this->assertSame(SuchakConsent::METHOD_SUCHAK_RELAYED_LINK, $consent->consent_method);
        $this->assertSame('9876543210', $consent->intended_mobile);
        $this->assertNull($consent->otp_hash);

        $this->actingAs($suchakUser)
            ->withSession([
                'suchak_consent_notice_id' => $consent->id,
                'suchak_consent_url' => $response->getSession()->get('suchak_consent_url'),
                'suchak_consent_forward_message' => $response->getSession()->get('suchak_consent_forward_message'),
                'suchak_consent_whatsapp_url' => $response->getSession()->get('suchak_consent_whatsapp_url'),
            ])
            ->get($this->manageUrl($representation))
            ->assertOk()
            ->assertSee('Consent message ready', false)
            ->assertSee('Message to send', false)
            ->assertSee('Copy message', false)
            ->assertSee('Send on WhatsApp', false)
            ->assertSee('Open consent page', false)
            ->assertSee('Consent link ready', false)
            ->assertSee('Waiting for customer/family response', false)
            ->assertSee('Regenerate link', false)
            ->assertSee('Cancel and create new', false)
            ->assertDontSee('Enter customer OTP', false)
            ->assertDontSee('Verify consent', false)
            ->assertDontSee('Send new platform OTP', false);

        $this->get(route('suchak.consents.public.show', ['token' => $token, 'locale' => 'mr']))
            ->assertOk()
            ->assertSee('संमतीपत्र', false)
            ->assertSee('कृपया खालील माहिती तपासा आणि तुमचा निर्णय निवडा.', false)
            ->assertSee('Demo Suchak Bureau', false)
            ->assertSee('Demo Suchak', false)
            ->assertSee('Aundh, Pune', false)
            ->assertSee('98xxxxxx10', false)
            ->assertSee('स्थळाचा थोडक्यात तपशील', false)
            ->assertSee('वधूचे नाव', false)
            ->assertSee('Demo Consent Candidate', false)
            ->assertSee('वय', false)
            ->assertSee('27 वर्षे', false)
            ->assertSee('तुमची संमती', false)
            ->assertSee('होय, मी संमती देतो/देते', false)
            ->assertSee('नाही, मी संमती देत नाही', false)
            ->assertSee('property="og:image"', false)
            ->assertSee('suchak/profile-photos/demo.jpg', false)
            ->assertSee('female-profile.svg', false)
            ->assertSee('English', false)
            ->assertSee('मराठी', false)
            ->assertSee('max-w-4xl', false)
            ->assertDontSee('सूचकाची माहिती', false)
            ->assertDontSee('सूचकाचे नाव', false)
            ->assertDontSee('भाग / पत्ता', false)
            ->assertDontSee('शिक्षण', false)
            ->assertDontSee('B.Com', false)
            ->assertDontSee('ठिकाण', false)
            ->assertDontSee('पुणे', false)
            ->assertDontSee('sm:max-w-md', false)
            ->assertDontSee('Navri Mile Navryala', false)
            ->assertDontSee('नवरी मिळे नवऱ्याला', false)
            ->assertDontSee('masked-', false)
            ->assertDontSee('Profile reference', false)
            ->assertDontSee('Profile #', false)
            ->assertDontSee('9876543210', false)
            ->assertDontSee('Mobile verified', false)
            ->assertDontSee('verified mobile', false);

        $this->get(route('suchak.consents.public.show', ['token' => $token, 'locale' => 'mr']))
            ->assertOk()
            ->assertSee('Demo Suchak Bureau', false)
            ->assertSee('तुमची संमती', false)
            ->assertSee('होय, मी संमती देतो/देते', false)
            ->assertSee('नाही, मी संमती देत नाही', false)
            ->assertSee('मराठी', false);

        $this->post(route('suchak.consents.public.decision', ['token' => $token]), [
            'decision' => SuchakConsent::STATUS_ACCEPTED,
        ])
            ->assertOk()
            ->assertSee('Consent accepted.', false)
            ->assertDontSee('Mobile verified', false);

        $accepted = $consent->fresh();
        $this->assertSame(SuchakConsent::STATUS_ACCEPTED, $accepted->consent_status);
        $this->assertTrue($accepted->mobile_match);
        $this->assertNotNull($accepted->accepted_at);
        $this->assertSame(SuchakProfileRepresentation::STATUS_ACTIVE, $representation->fresh()->representation_status);
        $this->assertSame(SuchakProfileRepresentation::CONSENT_ACCEPTED, $representation->fresh()->consent_status);

        $this->actingAs($suchakUser)
            ->get($this->manageUrl($representation))
            ->assertOk()
            ->assertSee('Consent active', false)
            ->assertSee('Accepted for requested mobile number', false)
            ->assertDontSee('Enter customer OTP', false);
    }

    public function test_whatsapp_consent_popup_submit_returns_direct_share_url(): void
    {
        [$suchakUser, , $representation] = $this->pendingRepresentationFixture();

        $response = $this->actingAs($suchakUser)
            ->withHeader('referer', $this->manageUrl($representation))
            ->postJson(route('suchak.representations.consents.request', $representation), [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_method' => SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
                'consent_given_by_name' => 'Candidate Parent',
                'consent_giver_relation' => 'father',
                'intended_mobile' => '9876543210',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Consent link created.')
            ->assertJsonStructure([
                'redirect_url',
                'consent_url',
                'forward_message',
                'whatsapp_url',
            ]);

        $this->assertStringStartsWith('https://wa.me/919876543210?text=', (string) $response->json('whatsapp_url'));
        $decodedWhatsAppUrl = urldecode((string) $response->json('whatsapp_url'));
        $this->assertStringContainsString('Demo Suchak Bureau', $decodedWhatsAppUrl);
        $this->assertStringContainsString('मी Demo Suchak Bureau.', $decodedWhatsAppUrl);
        $this->assertStringContainsString('तुमच्या विवाहस्थळाची माहिती अनुरूप, योग्य आणि चांगल्या स्थळांपर्यंत पुढे पाठवण्यासाठी मला तुमची परवानगी हवी आहे.', $decodedWhatsAppUrl);
        $this->assertStringContainsString('स्थळाचा थोडक्यात तपशील:', $decodedWhatsAppUrl);
        $this->assertStringContainsString('• वधूचे नाव: Demo Consent Candidate', $decodedWhatsAppUrl);
        $this->assertStringContainsString('• वय: 27 वर्षे', $decodedWhatsAppUrl);
        $this->assertStringContainsString('तुम्ही होकार दिल्यानंतरच हे स्थळ विवाह जुळवणीसाठी पुढे दाखवले जाईल. तुमचा मोबाईल नंबर किंवा कुटुंबाची खाजगी माहिती तुमच्या मंजुरीशिवाय कोणालाही दिली जाणार नाही, याची खात्री बाळगा.', $decodedWhatsAppUrl);
        $this->assertStringContainsString("कृपया पुढील प्रक्रियेसाठी खालील लिंकवर क्लिक करा आणि आपला निर्णय निवडा:\nhttp://127.0.0.1:8000/suchak/consent/", $decodedWhatsAppUrl);
        $this->assertStringNotContainsString('• उमेदवार:', $decodedWhatsAppUrl);
        $this->assertStringNotContainsString('masked-', $decodedWhatsAppUrl);
        $this->assertStringNotContainsString('(Female)', $decodedWhatsAppUrl);
        $this->assertStringNotContainsString('टीप:', $decodedWhatsAppUrl);
        $this->assertStringNotContainsString('जबाबदार व्यक्तीनेच', $decodedWhatsAppUrl);
        $this->assertStringNotContainsString('कृपया खालील सुरक्षित लिंक', $decodedWhatsAppUrl);
        $this->assertStringNotContainsString('👉', $decodedWhatsAppUrl);
        $this->assertStringNotContainsString('ही link', $decodedWhatsAppUrl);
        $this->assertStringNotContainsString('पर्यंत चालू आहे', $decodedWhatsAppUrl);
        $this->assertStringNotContainsString('Navri Mile Navryala', $decodedWhatsAppUrl);
    }

    public function test_whatsapp_consent_message_uses_admin_configured_privacy_paragraph(): void
    {
        [$suchakUser, , $representation] = $this->pendingRepresentationFixture();

        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_CONSENT_WHATSAPP_PRIVACY_PARAGRAPH],
            [
                'policy_value' => 'Admin configured privacy line for WhatsApp consent.',
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Test override.',
                'is_active' => true,
            ],
        );

        $response = $this->actingAs($suchakUser)
            ->withHeader('referer', $this->manageUrl($representation))
            ->postJson(route('suchak.representations.consents.request', $representation), [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_method' => SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
                'consent_given_by_name' => 'Candidate Parent',
                'consent_giver_relation' => 'father',
                'intended_mobile' => '9876543210',
            ])
            ->assertOk();

        $decodedWhatsAppUrl = urldecode((string) $response->json('whatsapp_url'));
        $this->assertStringContainsString('Admin configured privacy line for WhatsApp consent.', $decodedWhatsAppUrl);
        $this->assertStringNotContainsString(SuchakPolicyService::DEFAULT_SUCHAK_CONSENT_WHATSAPP_PRIVACY_PARAGRAPH, $decodedWhatsAppUrl);
    }

    public function test_public_consent_page_uses_groom_label_for_male_candidate(): void
    {
        [$suchakUser, , $representation] = $this->pendingRepresentationFixture('male', 'Ramesh Mane');

        $response = $this->actingAs($suchakUser)
            ->post(route('suchak.representations.consents.request', $representation), [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_method' => SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
                'consent_given_by_name' => 'Candidate Parent',
                'consent_giver_relation' => 'father',
                'intended_mobile' => '9876543210',
            ]);
        $token = basename((string) $response->getSession()->get('suchak_consent_url'));
        $message = (string) $response->getSession()->get('suchak_consent_forward_message');

        $this->assertStringContainsString('• वराचे नाव: Ramesh Mane', $message);
        $this->assertStringNotContainsString('masked-', $message);
        $this->assertStringNotContainsString('(Male)', $message);

        $this->get(route('suchak.consents.public.show', ['token' => $token, 'locale' => 'mr']))
            ->assertOk()
            ->assertSee('वराचे नाव', false)
            ->assertSee('Ramesh Mane', false)
            ->assertDontSee('वधूचे नाव', false)
            ->assertDontSee('masked-', false)
            ->assertDontSee('Profile #', false);
    }

    public function test_revoked_consent_shows_get_consent_again_on_customer_detail(): void
    {
        [$suchakUser, , $representation] = $this->pendingRepresentationFixture();

        $response = $this->actingAs($suchakUser)
            ->post(route('suchak.representations.consents.request', $representation), [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_method' => SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
                'consent_given_by_name' => 'Candidate Parent',
                'consent_giver_relation' => 'father',
                'intended_mobile' => '9876543210',
            ]);
        $token = basename((string) $response->getSession()->get('suchak_consent_url'));
        $this->post(route('suchak.consents.public.decision', ['token' => $token]), [
            'decision' => SuchakConsent::STATUS_ACCEPTED,
        ]);

        $accepted = SuchakConsent::query()
            ->where('representation_id', $representation->id)
            ->where('consent_status', SuchakConsent::STATUS_ACCEPTED)
            ->firstOrFail();

        $this->actingAs($suchakUser)
            ->post(route('suchak.consents.revoke', $accepted), [
                'reason' => 'Customer withdrew consent for testing.',
            ])
            ->assertRedirect();

        $this->actingAs($suchakUser)
            ->get($this->manageUrl($representation))
            ->assertOk()
            ->assertSee('Consent: Revoked', false)
            ->assertSee('Get consent', false)
            ->assertSee('Send on WhatsApp', false)
            ->assertDontSee('Renew consent', false);
    }

    public function test_pending_link_can_be_regenerated_and_cancelled_before_new_request(): void
    {
        [$suchakUser, , $representation] = $this->pendingRepresentationFixture();

        $response = $this->actingAs($suchakUser)
            ->post(route('suchak.representations.consents.request', $representation), [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_method' => SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
                'consent_given_by_name' => 'Candidate Parent',
                'consent_giver_relation' => 'father',
                'intended_mobile' => '9876543210',
            ]);

        $consent = SuchakConsent::query()->where('representation_id', $representation->id)->firstOrFail();
        $firstUrl = (string) $response->getSession()->get('suchak_consent_url');
        $firstToken = basename($firstUrl);

        $regenerated = $this->actingAs($suchakUser)
            ->post(route('suchak.consents.resend', $consent))
            ->assertRedirect()
            ->assertSessionHas('success', 'Consent link regenerated.')
            ->assertSessionHas('suchak_consent_forward_message');

        $regeneratedUrl = (string) $regenerated->getSession()->get('suchak_consent_url');
        $regeneratedToken = basename($regeneratedUrl);
        $this->assertNotSame($firstUrl, $regeneratedUrl);

        $this->actingAs($suchakUser)
            ->post(route('suchak.consents.cancel-pending', $consent))
            ->assertRedirect()
            ->assertSessionHas('success', 'Pending consent request cancelled. You can create a new request now.');

        $this->assertSame(SuchakConsent::STATUS_CANCELLED, $consent->fresh()->consent_status);
        $this->assertSame(SuchakProfileRepresentation::STATUS_PENDING, $representation->fresh()->representation_status);
        $this->assertSame(SuchakProfileRepresentation::CONSENT_NOT_REQUESTED, $representation->fresh()->consent_status);

        $this->get(route('suchak.consents.public.show', ['token' => $firstToken]))
            ->assertOk()
            ->assertSee('This link is invalid.', false)
            ->assertDontSee('name="decision"', false);

        $this->get(route('suchak.consents.public.show', ['token' => $regeneratedToken]))
            ->assertOk()
            ->assertSee('This request is no longer active.', false)
            ->assertDontSee('name="decision"', false);

        $this->actingAs($suchakUser)
            ->post(route('suchak.representations.consents.request', $representation), [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_method' => SuchakConsent::METHOD_PLATFORM_ASSISTED_LINK,
                'consent_given_by_name' => 'Candidate Parent',
                'consent_giver_relation' => 'father',
                'intended_mobile' => '9876543210',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Consent link created.');

        $this->assertSame(2, SuchakConsent::query()->where('representation_id', $representation->id)->count());
    }

    public function test_public_no_decision_marks_rejected_and_prevents_reuse(): void
    {
        [$suchakUser, , $representation] = $this->pendingRepresentationFixture();
        $response = $this->actingAs($suchakUser)
            ->post(route('suchak.representations.consents.request', $representation), [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_method' => SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
                'consent_given_by_name' => 'Candidate Mother',
                'consent_giver_relation' => 'mother',
                'intended_mobile' => '9876543211',
            ]);
        $token = basename((string) $response->getSession()->get('suchak_consent_url'));

        $this->post(route('suchak.consents.public.decision', ['token' => $token]), [
            'decision' => SuchakConsent::STATUS_REJECTED,
        ])
            ->assertOk()
            ->assertSee('Consent rejected.', false);

        $this->post(route('suchak.consents.public.decision', ['token' => $token]), [
            'decision' => SuchakConsent::STATUS_ACCEPTED,
        ])
            ->assertOk()
            ->assertSee('Consent link has already been used.', false);

        $this->assertSame(SuchakConsent::STATUS_REJECTED, SuchakConsent::query()->where('representation_id', $representation->id)->firstOrFail()->consent_status);
    }

    public function test_expired_public_link_cannot_be_accepted_from_route(): void
    {
        [$suchakUser, , $representation] = $this->pendingRepresentationFixture();
        $response = $this->actingAs($suchakUser)
            ->post(route('suchak.representations.consents.request', $representation), [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_method' => SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
                'consent_given_by_name' => 'Candidate Father',
                'consent_giver_relation' => 'father',
                'intended_mobile' => '9876543212',
            ]);
        $token = basename((string) $response->getSession()->get('suchak_consent_url'));
        $consent = SuchakConsent::query()->where('representation_id', $representation->id)->firstOrFail();
        $consent->forceFill([
            'token_expires_at' => now()->subMinute(),
            'expires_at' => now()->subMinute(),
        ])->save();

        $this->post(route('suchak.consents.public.decision', ['token' => $token]), [
            'decision' => SuchakConsent::STATUS_ACCEPTED,
        ])
            ->assertOk()
            ->assertSee('Consent link has expired.', false);

        $this->assertSame(SuchakConsent::STATUS_EXPIRED, $consent->fresh()->consent_status);
    }

    public function test_offline_signed_proof_requires_file_and_accepts_with_file(): void
    {
        [$suchakUser, , $representation] = $this->pendingRepresentationFixture();
        Storage::fake('local');

        $this->actingAs($suchakUser)
            ->from($this->manageUrl($representation))
            ->post(route('suchak.representations.consents.request', $representation), [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_method' => SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF,
                'consent_given_by_name' => 'Candidate Brother',
                'consent_giver_relation' => 'brother',
                'intended_mobile' => '9876543213',
                'declaration' => '1',
            ])
            ->assertSessionHasErrors('proof_document');

        $this->actingAs($suchakUser)
            ->post(route('suchak.representations.consents.request', $representation), [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_method' => SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF,
                'consent_given_by_name' => 'Candidate Brother',
                'consent_giver_relation' => 'brother',
                'intended_mobile' => '9876543213',
                'proof_document' => UploadedFile::fake()->create('signed-consent.pdf', 128, 'application/pdf'),
                'declaration' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Signed proof uploaded and consent accepted.');

        $accepted = SuchakConsent::query()->where('representation_id', $representation->id)->firstOrFail();
        $this->assertSame(SuchakConsent::STATUS_ACCEPTED, $accepted->consent_status);
        $this->assertSame(SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF, $accepted->consent_method);
        $this->assertNotNull($accepted->proof_file_path);
        Storage::disk('local')->assertExists($accepted->proof_file_path);
    }

    public function test_platform_assisted_link_creates_pending_request_without_customer_code_ui(): void
    {
        [$suchakUser, , $representation] = $this->pendingRepresentationFixture();

        $response = $this->actingAs($suchakUser)
            ->post(route('suchak.representations.consents.request', $representation), [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_method' => SuchakConsent::METHOD_PLATFORM_ASSISTED_LINK,
                'consent_given_by_name' => 'Candidate Guardian',
                'consent_giver_relation' => 'guardian',
                'intended_mobile' => '9876543214',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Consent link created.')
            ->assertSessionHas('suchak_consent_url');

        $consent = SuchakConsent::query()->where('representation_id', $representation->id)->firstOrFail();
        $this->assertSame(SuchakConsent::METHOD_PLATFORM_ASSISTED_LINK, $consent->consent_method);
        $this->assertSame('manual_delivery_pending', $consent->delivery_status);
        $this->assertNull($consent->otp_hash);

        $this->actingAs($suchakUser)
            ->withSession([
                'suchak_consent_notice_id' => $consent->id,
                'suchak_consent_url' => $response->getSession()->get('suchak_consent_url'),
                'suchak_consent_forward_message' => $response->getSession()->get('suchak_consent_forward_message'),
            ])
            ->get($this->manageUrl($representation))
            ->assertOk()
            ->assertSee('Platform-assisted consent in progress', false)
            ->assertSee('Delivery gateway is not connected.', false)
            ->assertDontSee('Enter customer OTP', false)
            ->assertDontSee('Verify consent', false)
            ->assertDontSee('Send new platform OTP', false);
    }

    public function test_non_owner_suchak_cannot_create_consent_for_representation(): void
    {
        [, , $representation] = $this->pendingRepresentationFixture();
        $otherUser = User::factory()->create();
        SuchakAccount::factory()->create([
            'user_id' => $otherUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        $this->actingAs($otherUser)
            ->post(route('suchak.representations.consents.request', $representation), [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_method' => SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
                'consent_given_by_name' => 'Wrong Suchak',
                'consent_giver_relation' => 'guardian',
                'intended_mobile' => '9876543215',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Only the representation Suchak actor can request consent.');

        $this->assertDatabaseMissing('suchak_consents', [
            'representation_id' => $representation->id,
            'intended_mobile' => '9876543215',
        ]);
    }

    public function test_admin_consent_evidence_uses_secure_link_wording_without_hash_values(): void
    {
        [$suchakUser, $account, $representation] = $this->pendingRepresentationFixture();
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);

        $response = $this->actingAs($suchakUser)
            ->post(route('suchak.representations.consents.request', $representation), [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_method' => SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
                'consent_given_by_name' => 'Candidate Parent',
                'consent_giver_relation' => 'father',
                'intended_mobile' => '9876543210',
            ]);
        $token = basename((string) $response->getSession()->get('suchak_consent_url'));
        $this->post(route('suchak.consents.public.decision', ['token' => $token]), [
            'decision' => SuchakConsent::STATUS_ACCEPTED,
        ]);

        $consent = SuchakConsent::query()->where('representation_id', $representation->id)->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.suchak.accounts.show', $account))
            ->assertOk()
            ->assertSee('Consent Evidence', false)
            ->assertSee('Evidence type', false)
            ->assertSee('Accepted for requested mobile number', false)
            ->assertDontSee($consent->token_hash, false)
            ->assertDontSee('Hashed OTP stored', false)
            ->assertDontSee('OTP evidence', false);
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: SuchakProfileRepresentation}
     */
    private function pendingRepresentationFixture(string $genderKey = 'female', string $candidateName = 'Demo Consent Candidate'): array
    {
        $suchakUser = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'suchak_name' => 'Demo Suchak',
            'office_name' => 'Demo Suchak Bureau',
            'mobile_number' => '9876543210',
            'address_line' => 'Aundh, Pune',
            'profile_photo_path' => 'suchak/profile-photos/demo.jpg',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);
        $locationId = DB::table('addresses')->insertGetId([
            'parent_id' => null,
            'name' => 'Pune',
            'name_mr' => 'पुणे',
            'name_en' => 'Pune',
            'slug' => 'pune-consent-test-'.uniqid(),
            'hierarchy' => 'village',
            'level' => 4,
            'tag' => 'city',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $profile = MatrimonyProfile::factory()->create([
            'date_of_birth' => now()->subYears(27)->toDateString(),
            'full_name' => $candidateName,
            'gender_id' => $this->genderId($genderKey),
            'highest_education' => 'B.Com',
            'location_id' => $locationId,
        ]);
        $contactRow = [
            'profile_id' => $profile->id,
            'contact_name' => $candidateName,
            'phone_number' => '9876543210',
            'is_primary' => true,
            'visibility_rule' => 'unlock_only',
            'verified_status' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('profile_contacts', 'contact_relation_id')) {
            $contactRow['contact_relation_id'] = null;
        }
        if (Schema::hasColumn('profile_contacts', 'relation_type')) {
            $contactRow['relation_type'] = 'self';
        }
        DB::table('profile_contacts')->insert($contactRow);
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
            'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
        ]);

        return [$suchakUser, $account, $representation];
    }

    private function genderId(string $key): int
    {
        return (int) MasterGender::query()->firstOrCreate(
            ['key' => $key],
            ['label' => ucfirst($key), 'is_active' => true],
        )->id;
    }

    private function manageUrl(SuchakProfileRepresentation $representation): string
    {
        return route('suchak.dashboard', [
            'dashboard_tab' => 'profiles',
            'manage_representation' => $representation->id,
        ]);
    }
}
