<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakPolicy;
use App\Models\SuchakPlatformLead;
use App\Models\SuchakVisitConfirmation;

class SuchakPolicyService
{
    public const KEY_DEFAULT_CONSENT_VALIDITY_MONTHS = 'default_consent_validity_months';
    public const KEY_ALLOW_TWO_YEAR_CONSENT = 'allow_two_year_consent';
    public const KEY_ALLOW_UNTIL_REVOKED_CONSENT = 'allow_until_revoked_consent';
    public const KEY_REQUEST_ACTION_SLA_HOURS = 'request_action_sla_hours';
    public const KEY_COLLABORATION_SLA_DAYS = 'collaboration_sla_days';
    public const KEY_PDF_DOWNLOAD_LIMIT_PER_DAY = 'pdf_download_limit_per_day';
    public const KEY_QR_TOKEN_EXPIRY_DAYS = 'qr_token_expiry_days';
    public const KEY_SUCHAK_UPLOAD_DAILY_LIMIT = 'suchak_upload_daily_limit';
    public const KEY_SUCHAK_ACTIVE_PROFILE_LIMIT_BY_PLAN = 'suchak_active_profile_limit_by_plan';
    public const KEY_SUCHAK_FREE_TRIAL_DAYS = 'suchak_free_trial_days';
    public const KEY_SUCHAK_GRACE_PERIOD_DAYS = 'suchak_grace_period_days';
    public const KEY_SUCHAK_PLAN_PRICING_MODE = 'suchak_plan_pricing_mode';
    public const KEY_SUCHAK_PAYMENT_MODE = 'suchak_payment_mode';
    public const KEY_SUCHAK_AUTO_APPROVE_ON_OTP = 'suchak_auto_approve_on_otp';
    public const KEY_SUCHAK_ALLOW_WORK_BEFORE_ADMIN_APPROVAL = 'suchak_allow_work_before_admin_approval';
    public const KEY_SUCHAK_AUTO_PUBLISH_ON_APPROVAL = 'suchak_auto_publish_on_approval';
    public const KEY_SUCHAK_CONSENT_WHATSAPP_PRIVACY_PARAGRAPH = 'suchak_consent_whatsapp_privacy_paragraph';
    public const KEY_SUCHAK_HERO_REGISTRATION_FORM_ENABLED = 'suchak_hero_registration_form_enabled';
    public const KEY_SUCHAK_HERO_IMAGE_PATH = 'suchak_hero_image_path';
    public const KEY_SUCHAK_HOMEPAGE_COPY_JSON = 'suchak_homepage_copy_json';
    public const KEY_SUCHAK_HOMEPAGE_STYLE_JSON = 'suchak_homepage_style_json';
    public const KEY_SUCHAK_WORK_AREA_MIN_CONSENTED_CUSTOMERS = 'suchak_work_area_min_consented_customers';
    public const KEY_SUCHAK_COMMISSION_RULES_JSON = 'suchak_commission_rules_json';
    public const KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE = 'suchak_package_publish_approval_mode';
    public const KEY_SUCHAK_TERMS_POLICY_MODE = 'suchak_terms_policy_mode';
    public const KEY_SUCHAK_PAYMENT_DETAIL_VISIBILITY_POLICY = 'suchak_payment_detail_visibility_policy';
    public const KEY_SUCHAK_VISIT_CONFIRMATION_POLICY_MODE = 'suchak_visit_confirmation_policy_mode';
    public const KEY_SUCHAK_LEAD_ALLOCATION_POLICY_MODE = 'suchak_lead_allocation_policy_mode';
    public const KEY_SUCHAK_LEAD_ALLOCATION_SLA_HOURS = 'suchak_lead_allocation_sla_hours';
    public const KEY_SUCHAK_LOYALTY_TIER_POLICY_JSON = 'suchak_loyalty_tier_policy_json';
    public const KEY_SUCHAK_EXPORT_RETENTION_POLICY_JSON = 'suchak_export_retention_policy_json';

    public const DEFAULT_CONSENT_VALIDITY_MONTHS = 12;
    public const DEFAULT_REQUEST_ACTION_SLA_HOURS = 48;
    public const DEFAULT_COLLABORATION_SLA_DAYS = 7;
    public const DEFAULT_PDF_DOWNLOAD_LIMIT_PER_DAY = 20;
    public const DEFAULT_QR_TOKEN_EXPIRY_DAYS = 30;
    public const DEFAULT_SUCHAK_UPLOAD_DAILY_LIMIT = 25;
    public const DEFAULT_SUCHAK_ACTIVE_PROFILE_LIMIT_BY_PLAN = 0;
    public const DEFAULT_SUCHAK_FREE_TRIAL_DAYS = 0;
    public const DEFAULT_SUCHAK_GRACE_PERIOD_DAYS = 0;
    public const DEFAULT_SUCHAK_PLAN_PRICING_MODE = 'manual_catalog';
    public const DEFAULT_SUCHAK_PAYMENT_MODE = 'manual_only';
    public const DEFAULT_SUCHAK_AUTO_APPROVE_ON_OTP = false;
    public const DEFAULT_SUCHAK_ALLOW_WORK_BEFORE_ADMIN_APPROVAL = true;
    public const DEFAULT_SUCHAK_AUTO_PUBLISH_ON_APPROVAL = false;
    public const DEFAULT_SUCHAK_CONSENT_WHATSAPP_PRIVACY_PARAGRAPH = 'तुम्ही होकार दिल्यानंतरच हे स्थळ विवाह जुळवणीसाठी पुढे दाखवले जाईल. तुमचा मोबाईल नंबर किंवा कुटुंबाची खाजगी माहिती तुमच्या मंजुरीशिवाय कोणालाही दिली जाणार नाही, याची खात्री बाळगा.';
    public const DEFAULT_SUCHAK_HERO_REGISTRATION_FORM_ENABLED = true;
    public const DEFAULT_SUCHAK_HERO_IMAGE_PATH = '';
    public const DEFAULT_SUCHAK_HOMEPAGE_COPY = [
        'mr' => [
            'eyebrow' => 'Suchak platform',
            'title' => 'सूचक म्हणून तुमचा विवाह-जुळवणी व्यवसाय वाढवा',
            'subtitle' => 'ग्राहकांचे biodata, follow-up, packages आणि payment records एका सुरक्षित platform वर व्यवस्थित manage करा.',
            'primary_cta' => 'सूचक म्हणून नोंदणी करा',
            'dashboard_cta' => 'Dashboard उघडा',
            'secondary_cta' => 'कसे काम करते?',
            'trust' => 'Private contact details आणि direct payment details public दाखवले जात नाहीत. Suchak workflow admin-governed verification आणि platform rules नुसार चालतो.',
            'hero_form_title' => 'Suchak registration',
            'hero_form_body' => 'आधी basic माहिती भरा. पुढे WhatsApp OTP verify होईल; documents dashboard मधून upload करता येतील.',
            'benefits_title' => 'मुख्य फायदे',
            'benefits_intro' => 'जास्त माहिती नको; Suchak ला रोजच्या कामात उपयोगी पडणारी साधने स्पष्ट दिसली पाहिजेत.',
            'business_title' => 'Business growth साठी व्यवस्थित setup',
            'business_body' => 'Existing customer work अधिक organized करा, service packages नीट ठेवा आणि platform rules नुसार नवीन opportunities handle करा.',
            'process_title' => 'सरळ process',
            'tools_title' => 'Approved Suchak साठी tools',
            'final_title' => 'तुमचा Suchak business digital पद्धतीने manage करायला सुरुवात करा',
            'final_body' => 'Suchak workflow मध्ये सामील व्हा आणि customer management, biodata sharing आणि follow-up अधिक व्यवस्थित करा.',
            'status_cta' => 'Already applied? Status तपासा',
        ],
        'en' => [
            'eyebrow' => 'Suchak platform',
            'title' => 'Grow your matchmaking business as a Suchak',
            'subtitle' => 'Manage customer biodata, follow-ups, packages, and payment records on a secure platform.',
            'primary_cta' => 'Register as Suchak',
            'dashboard_cta' => 'Open dashboard',
            'secondary_cta' => 'How it works',
            'trust' => 'Private contact details and direct payment details are not shown publicly. Suchak workflows run under admin-governed verification and platform rules.',
            'hero_form_title' => 'Suchak registration',
            'hero_form_body' => 'Enter basic details first. WhatsApp OTP comes next; documents can be uploaded from the dashboard.',
            'benefits_title' => 'Core benefits',
            'benefits_intro' => 'No clutter. The page should show what helps a Suchak run daily work better.',
            'business_title' => 'A structured setup for business growth',
            'business_body' => 'Organize existing customer work, manage service packages, and handle new opportunities under platform rules.',
            'process_title' => 'Simple process',
            'tools_title' => 'Tools for approved Suchaks',
            'final_title' => 'Start managing your Suchak business digitally',
            'final_body' => 'Join the Suchak workflow and make customer management, biodata sharing, and follow-up more organized.',
            'status_cta' => 'Already applied? Check status',
        ],
        'benefits' => [
            [
                'title_mr' => 'ग्राहक व्यवस्थापन सोपे',
                'body_mr' => 'Biodata, notes, follow-up आणि status एकाच ठिकाणी ठेवा.',
                'title_en' => 'Simpler customer management',
                'body_en' => 'Keep biodata, notes, follow-ups, and status in one place.',
            ],
            [
                'title_mr' => 'Secure biodata sharing',
                'body_mr' => 'PDF/QR sharing करताना private contact details public leak होऊ नयेत.',
                'title_en' => 'Secure biodata sharing',
                'body_en' => 'Use PDF/QR sharing without publicly leaking private contact details.',
            ],
            [
                'title_mr' => 'Packages आणि payment records',
                'body_mr' => 'Suchak services, customer payments आणि ledger evidence व्यवस्थित नोंदवा.',
                'title_en' => 'Packages and payment records',
                'body_en' => 'Record service packages, customer payments, and ledger evidence clearly.',
            ],
            [
                'title_mr' => 'Platform presence',
                'body_mr' => 'सूचक म्हणून professional presence तयार करा.',
                'title_en' => 'Platform presence',
                'body_en' => 'Build a professional Suchak presence.',
            ],
        ],
        'process_steps' => [
            ['label_mr' => 'नोंदणी करा', 'label_en' => 'Register'],
            ['label_mr' => 'Mobile/KYC verify करा', 'label_en' => 'Verify mobile/KYC'],
            ['label_mr' => 'Admin approval', 'label_en' => 'Admin approval'],
            ['label_mr' => 'Customer work सुरू करा', 'label_en' => 'Start customer work'],
        ],
        'tools' => [
            ['label_mr' => 'Dashboard', 'label_en' => 'Dashboard'],
            ['label_mr' => 'Customer Biodata Entry', 'label_en' => 'Customer Biodata Entry'],
            ['label_mr' => 'Secure PDF/QR Sharing', 'label_en' => 'Secure PDF/QR Sharing'],
            ['label_mr' => 'Follow-up / CRM', 'label_en' => 'Follow-up / CRM'],
            ['label_mr' => 'Payment Records', 'label_en' => 'Payment Records'],
            ['label_mr' => 'Masked Search', 'label_en' => 'Masked Search'],
        ],
    ];
    public const DEFAULT_SUCHAK_HOMEPAGE_STYLE = [
        'primary_color' => '#b91c1c',
        'primary_dark_color' => '#7f1d1d',
        'ink_color' => '#211817',
        'page_background_color' => '#fff7f3',
        'hero_background_color' => '#2d1412',
        'overlay_color' => '#fff8f4',
        'desktop_overlay_opacity' => 94,
        'mobile_overlay_opacity' => 92,
        'image_position_desktop' => 'center',
        'image_position_mobile' => 'center',
        'hero_min_height_desktop' => 86,
        'hero_min_height_mobile' => 84,
        'hero_blur_px' => 0,
        'bottom_fade_enabled' => true,
        'bottom_fade_height_rem' => 7,
        'form_card_opacity' => 94,
        'form_shadow_enabled' => true,
    ];
    public const DEFAULT_SUCHAK_WORK_AREA_MIN_CONSENTED_CUSTOMERS = 4;
    public const DEFAULT_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE = 'admin_review';
    public const DEFAULT_SUCHAK_TERMS_POLICY_MODE = 'strict';
    public const DEFAULT_SUCHAK_PAYMENT_DETAIL_VISIBILITY_POLICY = SuchakPaymentRequest::VISIBILITY_TERMS_SATISFIED_ONLY;
    public const DEFAULT_SUCHAK_VISIT_CONFIRMATION_POLICY_MODE = SuchakVisitConfirmation::POLICY_USER_AND_ADMIN;
    public const DEFAULT_SUCHAK_LEAD_ALLOCATION_POLICY_MODE = SuchakPlatformLead::POLICY_AREA_COMMUNITY_ROTATION;
    public const DEFAULT_SUCHAK_LEAD_ALLOCATION_SLA_HOURS = 48;
    public const DEFAULT_SUCHAK_LOYALTY_TIER_POLICY = [
        ['tier_key' => 'starter', 'tier_label' => 'Starter', 'minimum_score' => 0],
        ['tier_key' => 'growth', 'tier_label' => 'Growth', 'minimum_score' => 40],
        ['tier_key' => 'partner', 'tier_label' => 'Partner', 'minimum_score' => 70],
    ];
    public const DEFAULT_SUCHAK_EXPORT_RETENTION_POLICY = [
        'private_contact_export_requires_admin_approval' => true,
        'business_export_audit_required' => true,
        'archive_job_deletes_source_records' => false,
        'financial_record_retention_days' => 2555,
        'dispute_record_retention_days' => 3650,
    ];
    public const DEFAULT_SUCHAK_COMMISSION_RULES = [
        'mode' => 'to_be_discussed',
        'default_percent' => 0,
        'default_amount' => 0,
        'require_ack' => true,
    ];

    public function value(string $key, mixed $default = null): mixed
    {
        $policy = $this->activePolicy($key);

        if ($policy === null) {
            return $default;
        }

        return $this->typedValue($policy, $default);
    }

    public function integer(string $key, int $default): int
    {
        $value = $this->value($key, $default);

        return is_int($value) ? $value : $default;
    }

    public function positiveInteger(string $key, int $default): int
    {
        $value = $this->integer($key, $default);

        return $value > 0 ? $value : $default;
    }

    public function boolean(string $key, bool $default): bool
    {
        $value = $this->value($key, $default);

        return is_bool($value) ? $value : $default;
    }

    public function string(string $key, string $default): string
    {
        $value = $this->value($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function array(string $key, array $default): array
    {
        $value = $this->value($key, $default);

        return is_array($value) ? $value : $default;
    }

    public function consentValidityMonths(): int
    {
        return $this->positiveInteger(
            self::KEY_DEFAULT_CONSENT_VALIDITY_MONTHS,
            self::DEFAULT_CONSENT_VALIDITY_MONTHS,
        );
    }

    public function allowsTwoYearConsent(): bool
    {
        return $this->boolean(self::KEY_ALLOW_TWO_YEAR_CONSENT, true);
    }

    public function allowsUntilRevokedConsent(): bool
    {
        return $this->boolean(self::KEY_ALLOW_UNTIL_REVOKED_CONSENT, true);
    }

    public function requestActionSlaHours(): int
    {
        return $this->positiveInteger(
            self::KEY_REQUEST_ACTION_SLA_HOURS,
            self::DEFAULT_REQUEST_ACTION_SLA_HOURS,
        );
    }

    public function collaborationSlaDays(): int
    {
        return $this->positiveInteger(
            self::KEY_COLLABORATION_SLA_DAYS,
            self::DEFAULT_COLLABORATION_SLA_DAYS,
        );
    }

    public function pdfDownloadLimitPerDay(): int
    {
        return $this->positiveInteger(
            self::KEY_PDF_DOWNLOAD_LIMIT_PER_DAY,
            self::DEFAULT_PDF_DOWNLOAD_LIMIT_PER_DAY,
        );
    }

    public function qrTokenExpiryDays(): int
    {
        return $this->positiveInteger(
            self::KEY_QR_TOKEN_EXPIRY_DAYS,
            self::DEFAULT_QR_TOKEN_EXPIRY_DAYS,
        );
    }

    public function uploadDailyLimit(): int
    {
        return $this->positiveInteger(
            self::KEY_SUCHAK_UPLOAD_DAILY_LIMIT,
            self::DEFAULT_SUCHAK_UPLOAD_DAILY_LIMIT,
        );
    }

    public function activeProfileFallbackLimit(): int
    {
        return $this->integer(
            self::KEY_SUCHAK_ACTIVE_PROFILE_LIMIT_BY_PLAN,
            self::DEFAULT_SUCHAK_ACTIVE_PROFILE_LIMIT_BY_PLAN,
        );
    }

    public function freeTrialDays(): int
    {
        return max(0, $this->integer(
            self::KEY_SUCHAK_FREE_TRIAL_DAYS,
            self::DEFAULT_SUCHAK_FREE_TRIAL_DAYS,
        ));
    }

    public function gracePeriodDays(): int
    {
        return max(0, $this->integer(
            self::KEY_SUCHAK_GRACE_PERIOD_DAYS,
            self::DEFAULT_SUCHAK_GRACE_PERIOD_DAYS,
        ));
    }

    public function planPricingMode(): string
    {
        return $this->string(
            self::KEY_SUCHAK_PLAN_PRICING_MODE,
            self::DEFAULT_SUCHAK_PLAN_PRICING_MODE,
        );
    }

    public function paymentMode(): string
    {
        return $this->string(
            self::KEY_SUCHAK_PAYMENT_MODE,
            self::DEFAULT_SUCHAK_PAYMENT_MODE,
        );
    }

    public function autoApprovesOnOtp(): bool
    {
        return $this->boolean(
            self::KEY_SUCHAK_AUTO_APPROVE_ON_OTP,
            self::DEFAULT_SUCHAK_AUTO_APPROVE_ON_OTP,
        );
    }

    public function allowsWorkBeforeAdminApproval(): bool
    {
        return $this->boolean(
            self::KEY_SUCHAK_ALLOW_WORK_BEFORE_ADMIN_APPROVAL,
            self::DEFAULT_SUCHAK_ALLOW_WORK_BEFORE_ADMIN_APPROVAL,
        );
    }

    public function autoPublishesOnApproval(): bool
    {
        return $this->boolean(
            self::KEY_SUCHAK_AUTO_PUBLISH_ON_APPROVAL,
            self::DEFAULT_SUCHAK_AUTO_PUBLISH_ON_APPROVAL,
        );
    }

    public function consentWhatsappPrivacyParagraph(): string
    {
        return $this->string(
            self::KEY_SUCHAK_CONSENT_WHATSAPP_PRIVACY_PARAGRAPH,
            self::DEFAULT_SUCHAK_CONSENT_WHATSAPP_PRIVACY_PARAGRAPH,
        );
    }

    public function heroRegistrationFormEnabled(): bool
    {
        return $this->boolean(
            self::KEY_SUCHAK_HERO_REGISTRATION_FORM_ENABLED,
            self::DEFAULT_SUCHAK_HERO_REGISTRATION_FORM_ENABLED,
        );
    }

    public function heroImagePath(): string
    {
        return $this->string(
            self::KEY_SUCHAK_HERO_IMAGE_PATH,
            self::DEFAULT_SUCHAK_HERO_IMAGE_PATH,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function homepageCopy(): array
    {
        return $this->normalizeHomepageCopy($this->array(
            self::KEY_SUCHAK_HOMEPAGE_COPY_JSON,
            self::DEFAULT_SUCHAK_HOMEPAGE_COPY,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function homepageStyle(): array
    {
        return $this->normalizeHomepageStyle($this->array(
            self::KEY_SUCHAK_HOMEPAGE_STYLE_JSON,
            self::DEFAULT_SUCHAK_HOMEPAGE_STYLE,
        ));
    }

    public function workAreaMinimumConsentedCustomers(): int
    {
        return $this->positiveInteger(
            self::KEY_SUCHAK_WORK_AREA_MIN_CONSENTED_CUSTOMERS,
            self::DEFAULT_SUCHAK_WORK_AREA_MIN_CONSENTED_CUSTOMERS,
        );
    }

    public function packagePublishApprovalMode(): string
    {
        $mode = $this->string(
            self::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE,
            self::DEFAULT_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE,
        );

        return in_array($mode, ['admin_review', 'auto_publish'], true)
            ? $mode
            : self::DEFAULT_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE;
    }

    public function termsPolicyMode(): string
    {
        $mode = $this->string(
            self::KEY_SUCHAK_TERMS_POLICY_MODE,
            self::DEFAULT_SUCHAK_TERMS_POLICY_MODE,
        );

        return in_array($mode, SuchakCustomerAgreement::POLICY_MODES, true)
            ? $mode
            : self::DEFAULT_SUCHAK_TERMS_POLICY_MODE;
    }

    public function paymentDetailVisibilityPolicy(): string
    {
        $mode = $this->string(
            self::KEY_SUCHAK_PAYMENT_DETAIL_VISIBILITY_POLICY,
            self::DEFAULT_SUCHAK_PAYMENT_DETAIL_VISIBILITY_POLICY,
        );

        return in_array($mode, SuchakPaymentRequest::VISIBILITY_POLICIES, true)
            ? $mode
            : self::DEFAULT_SUCHAK_PAYMENT_DETAIL_VISIBILITY_POLICY;
    }

    public function visitConfirmationPolicyMode(): string
    {
        $mode = $this->string(
            self::KEY_SUCHAK_VISIT_CONFIRMATION_POLICY_MODE,
            self::DEFAULT_SUCHAK_VISIT_CONFIRMATION_POLICY_MODE,
        );

        return in_array($mode, SuchakVisitConfirmation::POLICY_MODES, true)
            ? $mode
            : self::DEFAULT_SUCHAK_VISIT_CONFIRMATION_POLICY_MODE;
    }

    public function leadAllocationPolicyMode(): string
    {
        $mode = $this->string(
            self::KEY_SUCHAK_LEAD_ALLOCATION_POLICY_MODE,
            self::DEFAULT_SUCHAK_LEAD_ALLOCATION_POLICY_MODE,
        );

        return in_array($mode, SuchakPlatformLead::POLICIES, true)
            ? $mode
            : self::DEFAULT_SUCHAK_LEAD_ALLOCATION_POLICY_MODE;
    }

    public function leadAllocationSlaHours(): int
    {
        return $this->positiveInteger(
            self::KEY_SUCHAK_LEAD_ALLOCATION_SLA_HOURS,
            self::DEFAULT_SUCHAK_LEAD_ALLOCATION_SLA_HOURS,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loyaltyTierPolicy(): array
    {
        $tiers = $this->array(
            self::KEY_SUCHAK_LOYALTY_TIER_POLICY_JSON,
            self::DEFAULT_SUCHAK_LOYALTY_TIER_POLICY,
        );

        return array_values(array_filter($tiers, function (mixed $tier): bool {
            return is_array($tier)
                && isset($tier['tier_key'], $tier['tier_label'], $tier['minimum_score']);
        }));
    }

    /**
     * @return array<string, mixed>
     */
    public function exportRetentionPolicy(): array
    {
        return $this->array(
            self::KEY_SUCHAK_EXPORT_RETENTION_POLICY_JSON,
            self::DEFAULT_SUCHAK_EXPORT_RETENTION_POLICY,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function commissionRules(): array
    {
        return $this->array(
            self::KEY_SUCHAK_COMMISSION_RULES_JSON,
            self::DEFAULT_SUCHAK_COMMISSION_RULES,
        );
    }

    private function activePolicy(string $key): ?SuchakPolicy
    {
        return SuchakPolicy::query()
            ->where('policy_key', $key)
            ->where('is_active', true)
            ->first();
    }

    private function typedValue(SuchakPolicy $policy, mixed $default): mixed
    {
        $raw = trim((string) $policy->policy_value);

        return match ($policy->value_type) {
            SuchakPolicy::TYPE_INTEGER => preg_match('/^-?\d+$/', $raw) === 1 ? (int) $raw : $default,
            SuchakPolicy::TYPE_BOOLEAN => $this->booleanValue($raw),
            SuchakPolicy::TYPE_JSON => $this->jsonValue($raw, $default),
            SuchakPolicy::TYPE_STRING => $raw,
            default => $default,
        };
    }

    private function booleanValue(string $raw): bool
    {
        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    private function jsonValue(string $raw, mixed $default): mixed
    {
        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    /**
     * @param  array<string, mixed>  $copy
     * @return array<string, mixed>
     */
    private function normalizeHomepageCopy(array $copy): array
    {
        $normalized = self::DEFAULT_SUCHAK_HOMEPAGE_COPY;

        foreach (['mr', 'en'] as $locale) {
            if (! is_array($copy[$locale] ?? null)) {
                continue;
            }

            foreach ($normalized[$locale] as $key => $default) {
                $normalized[$locale][$key] = $this->nonEmptyString($copy[$locale][$key] ?? null, $default);
            }
        }

        $normalized['benefits'] = $this->normalizeLocalizedList(
            is_array($copy['benefits'] ?? null) ? $copy['benefits'] : [],
            self::DEFAULT_SUCHAK_HOMEPAGE_COPY['benefits'],
            ['title_mr', 'body_mr', 'title_en', 'body_en'],
        );
        $normalized['process_steps'] = $this->normalizeLocalizedList(
            is_array($copy['process_steps'] ?? null) ? $copy['process_steps'] : [],
            self::DEFAULT_SUCHAK_HOMEPAGE_COPY['process_steps'],
            ['label_mr', 'label_en'],
        );
        $normalized['tools'] = $this->normalizeLocalizedList(
            is_array($copy['tools'] ?? null) ? $copy['tools'] : [],
            self::DEFAULT_SUCHAK_HOMEPAGE_COPY['tools'],
            ['label_mr', 'label_en'],
        );

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $style
     * @return array<string, mixed>
     */
    private function normalizeHomepageStyle(array $style): array
    {
        $normalized = self::DEFAULT_SUCHAK_HOMEPAGE_STYLE;

        foreach (['primary_color', 'primary_dark_color', 'ink_color', 'page_background_color', 'hero_background_color', 'overlay_color'] as $key) {
            $normalized[$key] = $this->hexColor($style[$key] ?? null, (string) $normalized[$key]);
        }

        foreach ([
            'desktop_overlay_opacity' => [20, 100],
            'mobile_overlay_opacity' => [20, 100],
            'hero_min_height_desktop' => [55, 100],
            'hero_min_height_mobile' => [60, 100],
            'hero_blur_px' => [0, 12],
            'bottom_fade_height_rem' => [0, 16],
            'form_card_opacity' => [60, 100],
        ] as $key => [$min, $max]) {
            $value = is_numeric($style[$key] ?? null) ? (int) $style[$key] : (int) $normalized[$key];
            $normalized[$key] = max($min, min($max, $value));
        }

        foreach (['image_position_desktop', 'image_position_mobile'] as $key) {
            $normalized[$key] = in_array(($style[$key] ?? null), ['center', 'top', 'bottom', 'left', 'right'], true)
                ? (string) $style[$key]
                : (string) $normalized[$key];
        }

        $normalized['bottom_fade_enabled'] = (bool) ($style['bottom_fade_enabled'] ?? $normalized['bottom_fade_enabled']);
        $normalized['form_shadow_enabled'] = (bool) ($style['form_shadow_enabled'] ?? $normalized['form_shadow_enabled']);

        return $normalized;
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  array<int, array<string, string>>  $defaults
     * @param  list<string>  $keys
     * @return array<int, array<string, string>>
     */
    private function normalizeLocalizedList(array $items, array $defaults, array $keys): array
    {
        $normalized = [];

        foreach ($defaults as $index => $defaultRow) {
            $row = is_array($items[$index] ?? null) ? $items[$index] : [];
            $normalizedRow = [];

            foreach ($keys as $key) {
                $normalizedRow[$key] = $this->nonEmptyString($row[$key] ?? null, $defaultRow[$key]);
            }

            $normalized[] = $normalizedRow;
        }

        return $normalized;
    }

    private function nonEmptyString(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function hexColor(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
            ? strtolower($value)
            : $default;
    }
}
