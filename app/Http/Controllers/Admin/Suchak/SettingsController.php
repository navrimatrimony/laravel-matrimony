<?php

namespace App\Http\Controllers\Admin\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakPolicy;
use App\Models\SuchakVisitConfirmation;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(SuchakPolicyService $policyService): View
    {
        return view('admin.suchak.settings', [
            'current' => $this->currentValues($policyService),
            'pricingModes' => $this->pricingModes(),
            'paymentModes' => $this->paymentModes(),
            'commissionModes' => $this->commissionModes(),
            'visitConfirmationModes' => $this->visitConfirmationModes(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'default_consent_validity_months' => ['required', 'integer', 'min:1', 'max:60'],
            'allow_two_year_consent' => ['required', 'boolean'],
            'allow_until_revoked_consent' => ['required', 'boolean'],
            'request_action_sla_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'collaboration_sla_days' => ['required', 'integer', 'min:1', 'max:365'],
            'pdf_download_limit_per_day' => ['required', 'integer', 'min:1', 'max:10000'],
            'qr_token_expiry_days' => ['required', 'integer', 'min:1', 'max:365'],
            'suchak_upload_daily_limit' => ['required', 'integer', 'min:1', 'max:10000'],
            'suchak_active_profile_limit_by_plan' => ['required', 'integer', 'min:0', 'max:100000'],
            'suchak_free_trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'suchak_grace_period_days' => ['required', 'integer', 'min:0', 'max:365'],
            'suchak_plan_pricing_mode' => ['required', 'string', Rule::in(array_keys($this->pricingModes()))],
            'suchak_payment_mode' => ['required', 'string', Rule::in(array_keys($this->paymentModes()))],
            'suchak_allow_work_before_admin_approval' => ['required', 'boolean'],
            'suchak_auto_publish_on_approval' => ['required', 'boolean'],
            'suchak_consent_whatsapp_privacy_paragraph' => ['required', 'string', 'max:700', 'not_regex:/^\s*$/'],
            'suchak_hero_registration_form_enabled' => ['required', 'boolean'],
            'suchak_hero_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_suchak_hero_image' => ['nullable', 'boolean'],
            'homepage_copy' => ['required', 'array'],
            'homepage_copy.mr.eyebrow' => ['nullable', 'string', 'max:180'],
            'homepage_copy.mr.title' => ['nullable', 'string', 'max:240'],
            'homepage_copy.mr.subtitle' => ['nullable', 'string', 'max:700'],
            'homepage_copy.mr.primary_cta' => ['nullable', 'string', 'max:120'],
            'homepage_copy.mr.dashboard_cta' => ['nullable', 'string', 'max:120'],
            'homepage_copy.mr.secondary_cta' => ['nullable', 'string', 'max:120'],
            'homepage_copy.mr.trust' => ['nullable', 'string', 'max:700'],
            'homepage_copy.mr.hero_form_title' => ['nullable', 'string', 'max:160'],
            'homepage_copy.mr.hero_form_body' => ['nullable', 'string', 'max:700'],
            'homepage_copy.mr.benefits_title' => ['nullable', 'string', 'max:160'],
            'homepage_copy.mr.benefits_intro' => ['nullable', 'string', 'max:500'],
            'homepage_copy.mr.business_title' => ['nullable', 'string', 'max:220'],
            'homepage_copy.mr.business_body' => ['nullable', 'string', 'max:700'],
            'homepage_copy.mr.process_title' => ['nullable', 'string', 'max:160'],
            'homepage_copy.mr.tools_title' => ['nullable', 'string', 'max:160'],
            'homepage_copy.mr.final_title' => ['nullable', 'string', 'max:240'],
            'homepage_copy.mr.final_body' => ['nullable', 'string', 'max:700'],
            'homepage_copy.mr.status_cta' => ['nullable', 'string', 'max:160'],
            'homepage_copy.en.eyebrow' => ['nullable', 'string', 'max:180'],
            'homepage_copy.en.title' => ['nullable', 'string', 'max:240'],
            'homepage_copy.en.subtitle' => ['nullable', 'string', 'max:700'],
            'homepage_copy.en.primary_cta' => ['nullable', 'string', 'max:120'],
            'homepage_copy.en.dashboard_cta' => ['nullable', 'string', 'max:120'],
            'homepage_copy.en.secondary_cta' => ['nullable', 'string', 'max:120'],
            'homepage_copy.en.trust' => ['nullable', 'string', 'max:700'],
            'homepage_copy.en.hero_form_title' => ['nullable', 'string', 'max:160'],
            'homepage_copy.en.hero_form_body' => ['nullable', 'string', 'max:700'],
            'homepage_copy.en.benefits_title' => ['nullable', 'string', 'max:160'],
            'homepage_copy.en.benefits_intro' => ['nullable', 'string', 'max:500'],
            'homepage_copy.en.business_title' => ['nullable', 'string', 'max:220'],
            'homepage_copy.en.business_body' => ['nullable', 'string', 'max:700'],
            'homepage_copy.en.process_title' => ['nullable', 'string', 'max:160'],
            'homepage_copy.en.tools_title' => ['nullable', 'string', 'max:160'],
            'homepage_copy.en.final_title' => ['nullable', 'string', 'max:240'],
            'homepage_copy.en.final_body' => ['nullable', 'string', 'max:700'],
            'homepage_copy.en.status_cta' => ['nullable', 'string', 'max:160'],
            'homepage_benefits' => ['required', 'array', 'size:4'],
            'homepage_benefits.*.title_mr' => ['nullable', 'string', 'max:160'],
            'homepage_benefits.*.body_mr' => ['nullable', 'string', 'max:350'],
            'homepage_benefits.*.title_en' => ['nullable', 'string', 'max:160'],
            'homepage_benefits.*.body_en' => ['nullable', 'string', 'max:350'],
            'homepage_process' => ['required', 'array', 'size:4'],
            'homepage_process.*.label_mr' => ['nullable', 'string', 'max:140'],
            'homepage_process.*.label_en' => ['nullable', 'string', 'max:140'],
            'homepage_tools' => ['required', 'array', 'size:6'],
            'homepage_tools.*.label_mr' => ['nullable', 'string', 'max:140'],
            'homepage_tools.*.label_en' => ['nullable', 'string', 'max:140'],
            'homepage_style' => ['required', 'array'],
            'homepage_style.primary_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'homepage_style.primary_dark_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'homepage_style.ink_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'homepage_style.page_background_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'homepage_style.hero_background_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'homepage_style.overlay_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'homepage_style.desktop_overlay_opacity' => ['required', 'integer', 'min:20', 'max:100'],
            'homepage_style.mobile_overlay_opacity' => ['required', 'integer', 'min:20', 'max:100'],
            'homepage_style.image_position_desktop' => ['required', 'string', Rule::in(['center', 'top', 'bottom', 'left', 'right'])],
            'homepage_style.image_position_mobile' => ['required', 'string', Rule::in(['center', 'top', 'bottom', 'left', 'right'])],
            'homepage_style.hero_min_height_desktop' => ['required', 'integer', 'min:55', 'max:100'],
            'homepage_style.hero_min_height_mobile' => ['required', 'integer', 'min:60', 'max:100'],
            'homepage_style.hero_blur_px' => ['required', 'integer', 'min:0', 'max:12'],
            'homepage_style.bottom_fade_enabled' => ['required', 'boolean'],
            'homepage_style.bottom_fade_height_rem' => ['required', 'integer', 'min:0', 'max:16'],
            'homepage_style.form_card_opacity' => ['required', 'integer', 'min:60', 'max:100'],
            'homepage_style.form_shadow_enabled' => ['required', 'boolean'],
            'suchak_work_area_min_consented_customers' => ['required', 'integer', 'min:1', 'max:1000'],
            'suchak_visit_confirmation_policy_mode' => ['required', 'string', Rule::in(array_keys($this->visitConfirmationModes()))],
            'commission_mode' => ['required', 'string', Rule::in(array_keys($this->commissionModes()))],
            'commission_default_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'commission_default_amount' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'commission_require_ack' => ['required', 'boolean'],
        ]);

        $rows = $this->policyRows($validated, $request);
        $oldHeroImagePath = $this->currentHeroImagePath();
        $newHeroImagePath = $rows[SuchakPolicyService::KEY_SUCHAK_HERO_IMAGE_PATH]['policy_value'] ?? '';
        $existing = SuchakPolicy::query()
            ->whereIn('policy_key', array_keys($rows))
            ->get()
            ->keyBy('policy_key');

        $changes = [];
        foreach ($rows as $key => $row) {
            $policy = $existing->get($key);
            $oldValue = $policy?->policy_value;
            $oldType = $policy?->value_type;
            $wasActive = (bool) ($policy?->is_active ?? false);

            if ($oldValue !== $row['policy_value'] || $oldType !== $row['value_type'] || $wasActive !== true) {
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $row['policy_value'],
                ];
            }
        }

        if ($changes === []) {
            return back()->with('info', 'No Suchak settings changed.');
        }

        DB::transaction(function () use ($rows, $changes, $validated, $request): void {
            foreach ($rows as $key => $row) {
                SuchakPolicy::query()->updateOrCreate(
                    ['policy_key' => $key],
                    [
                        'policy_value' => $row['policy_value'],
                        'value_type' => $row['value_type'],
                        'description' => $row['description'],
                        'is_active' => true,
                    ],
                );
            }

            AuditLogService::log(
                $request->user(),
                'suchak_settings_update',
                'suchak_policy',
                null,
                'Suchak settings update. Reason: '.trim((string) $validated['reason']).'. Changes: '.json_encode($changes, JSON_THROW_ON_ERROR),
                false,
            );
        });

        $this->deleteReplacedHeroImage($oldHeroImagePath, $newHeroImagePath);

        return back()->with('success', 'Suchak settings updated and audited.');
    }

    /**
     * @return array<string, mixed>
     */
    private function currentValues(SuchakPolicyService $policyService): array
    {
        $commissionRules = array_merge(
            SuchakPolicyService::DEFAULT_SUCHAK_COMMISSION_RULES,
            $policyService->commissionRules(),
        );

        return [
            SuchakPolicyService::KEY_DEFAULT_CONSENT_VALIDITY_MONTHS => $policyService->consentValidityMonths(),
            SuchakPolicyService::KEY_ALLOW_TWO_YEAR_CONSENT => $policyService->allowsTwoYearConsent(),
            SuchakPolicyService::KEY_ALLOW_UNTIL_REVOKED_CONSENT => $policyService->allowsUntilRevokedConsent(),
            SuchakPolicyService::KEY_REQUEST_ACTION_SLA_HOURS => $policyService->requestActionSlaHours(),
            SuchakPolicyService::KEY_COLLABORATION_SLA_DAYS => $policyService->collaborationSlaDays(),
            SuchakPolicyService::KEY_PDF_DOWNLOAD_LIMIT_PER_DAY => $policyService->pdfDownloadLimitPerDay(),
            SuchakPolicyService::KEY_QR_TOKEN_EXPIRY_DAYS => $policyService->qrTokenExpiryDays(),
            SuchakPolicyService::KEY_SUCHAK_UPLOAD_DAILY_LIMIT => $policyService->uploadDailyLimit(),
            SuchakPolicyService::KEY_SUCHAK_ACTIVE_PROFILE_LIMIT_BY_PLAN => $policyService->activeProfileFallbackLimit(),
            SuchakPolicyService::KEY_SUCHAK_FREE_TRIAL_DAYS => $policyService->freeTrialDays(),
            SuchakPolicyService::KEY_SUCHAK_GRACE_PERIOD_DAYS => $policyService->gracePeriodDays(),
            SuchakPolicyService::KEY_SUCHAK_PLAN_PRICING_MODE => $policyService->planPricingMode(),
            SuchakPolicyService::KEY_SUCHAK_PAYMENT_MODE => $policyService->paymentMode(),
            SuchakPolicyService::KEY_SUCHAK_ALLOW_WORK_BEFORE_ADMIN_APPROVAL => $policyService->allowsWorkBeforeAdminApproval(),
            SuchakPolicyService::KEY_SUCHAK_AUTO_PUBLISH_ON_APPROVAL => $policyService->autoPublishesOnApproval(),
            SuchakPolicyService::KEY_SUCHAK_CONSENT_WHATSAPP_PRIVACY_PARAGRAPH => $policyService->consentWhatsappPrivacyParagraph(),
            SuchakPolicyService::KEY_SUCHAK_HERO_REGISTRATION_FORM_ENABLED => $policyService->heroRegistrationFormEnabled(),
            SuchakPolicyService::KEY_SUCHAK_HERO_IMAGE_PATH => $policyService->heroImagePath(),
            SuchakPolicyService::KEY_SUCHAK_HOMEPAGE_COPY_JSON => $policyService->homepageCopy(),
            SuchakPolicyService::KEY_SUCHAK_HOMEPAGE_STYLE_JSON => $policyService->homepageStyle(),
            SuchakPolicyService::KEY_SUCHAK_WORK_AREA_MIN_CONSENTED_CUSTOMERS => $policyService->workAreaMinimumConsentedCustomers(),
            SuchakPolicyService::KEY_SUCHAK_VISIT_CONFIRMATION_POLICY_MODE => $policyService->visitConfirmationPolicyMode(),
            'commission_mode' => (string) $commissionRules['mode'],
            'commission_default_percent' => (int) $commissionRules['default_percent'],
            'commission_default_amount' => (float) $commissionRules['default_amount'],
            'commission_require_ack' => (bool) $commissionRules['require_ack'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, array{policy_value: string, value_type: string, description: string}>
     */
    private function policyRows(array $validated, Request $request): array
    {
        $heroImagePath = $this->resolveHeroImagePath($request);
        $homepageCopy = $this->homepageCopyFromValidated($validated);
        $homepageStyle = $this->homepageStyleFromValidated($validated, $request);
        $commissionRules = [
            'mode' => (string) $validated['commission_mode'],
            'default_percent' => (int) $validated['commission_default_percent'],
            'default_amount' => (float) $validated['commission_default_amount'],
            'require_ack' => $request->boolean('commission_require_ack'),
        ];

        return [
            SuchakPolicyService::KEY_DEFAULT_CONSENT_VALIDITY_MONTHS => $this->integerRow($validated, 'default_consent_validity_months', 'Default consent validity in months.'),
            SuchakPolicyService::KEY_ALLOW_TWO_YEAR_CONSENT => $this->booleanRow($request, 'allow_two_year_consent', 'Allow two-year consent validity option.'),
            SuchakPolicyService::KEY_ALLOW_UNTIL_REVOKED_CONSENT => $this->booleanRow($request, 'allow_until_revoked_consent', 'Allow until-revoked consent validity option.'),
            SuchakPolicyService::KEY_REQUEST_ACTION_SLA_HOURS => $this->integerRow($validated, 'request_action_sla_hours', 'Request action SLA in hours.'),
            SuchakPolicyService::KEY_COLLABORATION_SLA_DAYS => $this->integerRow($validated, 'collaboration_sla_days', 'Collaboration response SLA in days.'),
            SuchakPolicyService::KEY_PDF_DOWNLOAD_LIMIT_PER_DAY => $this->integerRow($validated, 'pdf_download_limit_per_day', 'PDF download/share limit per day.'),
            SuchakPolicyService::KEY_QR_TOKEN_EXPIRY_DAYS => $this->integerRow($validated, 'qr_token_expiry_days', 'QR token expiry in days.'),
            SuchakPolicyService::KEY_SUCHAK_UPLOAD_DAILY_LIMIT => $this->integerRow($validated, 'suchak_upload_daily_limit', 'Suchak upload limit per day.'),
            SuchakPolicyService::KEY_SUCHAK_ACTIVE_PROFILE_LIMIT_BY_PLAN => $this->integerRow($validated, 'suchak_active_profile_limit_by_plan', 'Fallback active profile limit when plan feature is missing.'),
            SuchakPolicyService::KEY_SUCHAK_FREE_TRIAL_DAYS => $this->integerRow($validated, 'suchak_free_trial_days', 'Default free trial days for Suchak plans.'),
            SuchakPolicyService::KEY_SUCHAK_GRACE_PERIOD_DAYS => $this->integerRow($validated, 'suchak_grace_period_days', 'Default grace period days after Suchak plan expiry.'),
            SuchakPolicyService::KEY_SUCHAK_PLAN_PRICING_MODE => $this->stringRow($validated, 'suchak_plan_pricing_mode', 'Suchak plan pricing mode.'),
            SuchakPolicyService::KEY_SUCHAK_PAYMENT_MODE => $this->stringRow($validated, 'suchak_payment_mode', 'Suchak platform payment mode.'),
            SuchakPolicyService::KEY_SUCHAK_ALLOW_WORK_BEFORE_ADMIN_APPROVAL => $this->booleanRow($request, 'suchak_allow_work_before_admin_approval', 'Allow pending-review Suchak accounts to use operational dashboard tools before admin approval.'),
            SuchakPolicyService::KEY_SUCHAK_AUTO_PUBLISH_ON_APPROVAL => $this->booleanRow($request, 'suchak_auto_publish_on_approval', 'Automatically publish a Suchak account publicly when admin approval succeeds.'),
            SuchakPolicyService::KEY_SUCHAK_CONSENT_WHATSAPP_PRIVACY_PARAGRAPH => $this->trimmedStringRow($validated, 'suchak_consent_whatsapp_privacy_paragraph', 'WhatsApp consent message privacy paragraph shown before the secure consent link.'),
            SuchakPolicyService::KEY_SUCHAK_HERO_REGISTRATION_FORM_ENABLED => $this->booleanRow($request, 'suchak_hero_registration_form_enabled', 'Show the Suchak registration form directly inside the public Suchak hero section.'),
            SuchakPolicyService::KEY_SUCHAK_HERO_IMAGE_PATH => [
                'policy_value' => $heroImagePath,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Public Suchak homepage hero image path stored on the public disk.',
            ],
            SuchakPolicyService::KEY_SUCHAK_HOMEPAGE_COPY_JSON => $this->jsonRow(
                $homepageCopy,
                'Public Suchak homepage bilingual copy, benefits, process steps, and tool labels.',
            ),
            SuchakPolicyService::KEY_SUCHAK_HOMEPAGE_STYLE_JSON => $this->jsonRow(
                $homepageStyle,
                'Public Suchak homepage hero visual style controls.',
            ),
            SuchakPolicyService::KEY_SUCHAK_WORK_AREA_MIN_CONSENTED_CUSTOMERS => $this->integerRow($validated, 'suchak_work_area_min_consented_customers', 'Minimum valid consented customers required before an area is treated as Suchak work area.'),
            SuchakPolicyService::KEY_SUCHAK_VISIT_CONFIRMATION_POLICY_MODE => $this->stringRow($validated, 'suchak_visit_confirmation_policy_mode', 'Confirmation policy required before platform visit payouts can be qualified.'),
            SuchakPolicyService::KEY_SUCHAK_COMMISSION_RULES_JSON => [
                'policy_value' => json_encode($commissionRules, JSON_THROW_ON_ERROR),
                'value_type' => SuchakPolicy::TYPE_JSON,
                'description' => 'Default Suchak collaboration commission rule.',
            ],
        ];
    }

    private function resolveHeroImagePath(Request $request): string
    {
        if ($request->hasFile('suchak_hero_image')) {
            $storedPath = $request->file('suchak_hero_image')->store('suchak/hero-images', 'public');

            if (! is_string($storedPath)) {
                throw ValidationException::withMessages([
                    'suchak_hero_image' => 'The Suchak hero image could not be stored. Please try again.',
                ]);
            }

            return $storedPath;
        }

        if ($request->boolean('remove_suchak_hero_image')) {
            return SuchakPolicyService::DEFAULT_SUCHAK_HERO_IMAGE_PATH;
        }

        return $this->currentHeroImagePath();
    }

    private function currentHeroImagePath(): string
    {
        $value = SuchakPolicy::query()
            ->where('policy_key', SuchakPolicyService::KEY_SUCHAK_HERO_IMAGE_PATH)
            ->where('is_active', true)
            ->value('policy_value');

        return is_string($value) ? trim($value) : SuchakPolicyService::DEFAULT_SUCHAK_HERO_IMAGE_PATH;
    }

    private function deleteReplacedHeroImage(string $oldPath, string $newPath): void
    {
        if ($oldPath === '' || $oldPath === $newPath || ! str_starts_with($oldPath, 'suchak/hero-images/')) {
            return;
        }

        Storage::disk('public')->delete($oldPath);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function homepageCopyFromValidated(array $validated): array
    {
        $defaults = SuchakPolicyService::DEFAULT_SUCHAK_HOMEPAGE_COPY;
        $input = is_array($validated['homepage_copy'] ?? null) ? $validated['homepage_copy'] : [];
        $copy = $defaults;

        foreach (['mr', 'en'] as $locale) {
            $localeInput = is_array($input[$locale] ?? null) ? $input[$locale] : [];

            foreach ($defaults[$locale] as $key => $default) {
                $copy[$locale][$key] = $this->trimOrDefault($localeInput[$key] ?? null, $default);
            }
        }

        $copy['benefits'] = $this->fixedLocalizedRows(
            is_array($validated['homepage_benefits'] ?? null) ? $validated['homepage_benefits'] : [],
            $defaults['benefits'],
            ['title_mr', 'body_mr', 'title_en', 'body_en'],
        );
        $copy['process_steps'] = $this->fixedLocalizedRows(
            is_array($validated['homepage_process'] ?? null) ? $validated['homepage_process'] : [],
            $defaults['process_steps'],
            ['label_mr', 'label_en'],
        );
        $copy['tools'] = $this->fixedLocalizedRows(
            is_array($validated['homepage_tools'] ?? null) ? $validated['homepage_tools'] : [],
            $defaults['tools'],
            ['label_mr', 'label_en'],
        );

        return $copy;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function homepageStyleFromValidated(array $validated, Request $request): array
    {
        $input = is_array($validated['homepage_style'] ?? null) ? $validated['homepage_style'] : [];

        return [
            'primary_color' => strtolower((string) $input['primary_color']),
            'primary_dark_color' => strtolower((string) $input['primary_dark_color']),
            'ink_color' => strtolower((string) $input['ink_color']),
            'page_background_color' => strtolower((string) $input['page_background_color']),
            'hero_background_color' => strtolower((string) $input['hero_background_color']),
            'overlay_color' => strtolower((string) $input['overlay_color']),
            'desktop_overlay_opacity' => (int) $input['desktop_overlay_opacity'],
            'mobile_overlay_opacity' => (int) $input['mobile_overlay_opacity'],
            'image_position_desktop' => (string) $input['image_position_desktop'],
            'image_position_mobile' => (string) $input['image_position_mobile'],
            'hero_min_height_desktop' => (int) $input['hero_min_height_desktop'],
            'hero_min_height_mobile' => (int) $input['hero_min_height_mobile'],
            'hero_blur_px' => (int) $input['hero_blur_px'],
            'bottom_fade_enabled' => $request->boolean('homepage_style.bottom_fade_enabled'),
            'bottom_fade_height_rem' => (int) $input['bottom_fade_height_rem'],
            'form_card_opacity' => (int) $input['form_card_opacity'],
            'form_shadow_enabled' => $request->boolean('homepage_style.form_shadow_enabled'),
        ];
    }

    /**
     * @param  array<int, mixed>  $input
     * @param  array<int, array<string, string>>  $defaults
     * @param  list<string>  $keys
     * @return array<int, array<string, string>>
     */
    private function fixedLocalizedRows(array $input, array $defaults, array $keys): array
    {
        $rows = [];

        foreach ($defaults as $index => $defaultRow) {
            $inputRow = is_array($input[$index] ?? null) ? $input[$index] : [];
            $row = [];

            foreach ($keys as $key) {
                $row[$key] = $this->trimOrDefault($inputRow[$key] ?? null, $defaultRow[$key]);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function trimOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{policy_value: string, value_type: string, description: string}
     */
    private function integerRow(array $validated, string $key, string $description): array
    {
        return [
            'policy_value' => (string) (int) $validated[$key],
            'value_type' => SuchakPolicy::TYPE_INTEGER,
            'description' => $description,
        ];
    }

    /**
     * @return array{policy_value: string, value_type: string, description: string}
     */
    private function booleanRow(Request $request, string $key, string $description): array
    {
        return [
            'policy_value' => $request->boolean($key) ? 'true' : 'false',
            'value_type' => SuchakPolicy::TYPE_BOOLEAN,
            'description' => $description,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{policy_value: string, value_type: string, description: string}
     */
    private function stringRow(array $validated, string $key, string $description): array
    {
        return [
            'policy_value' => (string) $validated[$key],
            'value_type' => SuchakPolicy::TYPE_STRING,
            'description' => $description,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{policy_value: string, value_type: string, description: string}
     */
    private function trimmedStringRow(array $validated, string $key, string $description): array
    {
        return [
            'policy_value' => trim((string) $validated[$key]),
            'value_type' => SuchakPolicy::TYPE_STRING,
            'description' => $description,
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array{policy_value: string, value_type: string, description: string}
     */
    private function jsonRow(array $value, string $description): array
    {
        return [
            'policy_value' => json_encode($value, JSON_THROW_ON_ERROR),
            'value_type' => SuchakPolicy::TYPE_JSON,
            'description' => $description,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function pricingModes(): array
    {
        return [
            'manual_catalog' => 'Manual catalog',
            'free_trial_then_manual' => 'Free trial then manual',
            'paid_required' => 'Paid required',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function paymentModes(): array
    {
        return [
            'manual_only' => 'Manual only',
            'payu_test_mode' => 'PayU test mode',
            'live_credentials_pending' => 'Live credentials pending',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function commissionModes(): array
    {
        return [
            'to_be_discussed' => 'To be discussed',
            'none' => 'No commission',
            'percentage' => 'Percentage',
            'fixed_amount' => 'Fixed amount',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function visitConfirmationModes(): array
    {
        return [
            SuchakVisitConfirmation::POLICY_USER_AND_ADMIN => 'User and admin confirmation',
            SuchakVisitConfirmation::POLICY_ADMIN_ONLY => 'Admin confirmation only',
            SuchakVisitConfirmation::POLICY_USER_ONLY => 'User confirmation only',
        ];
    }
}
