<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Models\ProfileFieldConfig;
use App\Services\AuditLogService;
use App\Services\Parsing\ProviderResolver;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/*
|--------------------------------------------------------------------------
| Admin settings / configuration (moved from AdminController, Phase 2)
|--------------------------------------------------------------------------
*/
class AdminSettingsController extends Controller
{
    private const REASON_RULES = ['required', 'string', 'min:10'];

    /**
     * View-back settings (Day-9). Enable/disable, probability 0–100, delay min/max.
     */
    public function viewBackSettings()
    {
        $enabled = AdminSetting::getBool('view_back_enabled', false);
        $probability = (int) AdminSetting::getValue('view_back_probability', '0');
        $probability = max(0, min(100, $probability));
        $delayMin = (int) AdminSetting::getValue('view_back_delay_min', '0');
        $delayMax = (int) AdminSetting::getValue('view_back_delay_max', '0');

        return view('admin.view-back-settings.index', [
            'viewBackEnabled' => $enabled,
            'viewBackProbability' => $probability,
            'viewBackDelayMin' => max(0, $delayMin),
            'viewBackDelayMax' => max(0, $delayMax),
        ]);
    }

    /**
     * Update view-back settings. Persisted via AdminSetting.
     */
    public function updateViewBackSettings(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'view_back_enabled' => 'nullable|in:0,1',
            'view_back_probability' => 'required|integer|min:0|max:100',
            'view_back_delay_min' => 'required|integer|min:0|max:1440',
            'view_back_delay_max' => 'required|integer|min:0|max:1440',
        ]);

        $delayMin = (int) $request->input('view_back_delay_min', 0);
        $delayMax = (int) $request->input('view_back_delay_max', 0);

        // Ensure max >= min
        if ($delayMax < $delayMin) {
            $delayMax = $delayMin;
        }

        $enabled = $request->has('view_back_enabled') ? '1' : '0';
        $probability = (string) $request->input('view_back_probability', 0);

        AdminSetting::setValue('view_back_enabled', $enabled);
        AdminSetting::setValue('view_back_probability', $probability);
        AdminSetting::setValue('view_back_delay_min', (string) $delayMin);
        AdminSetting::setValue('view_back_delay_max', (string) $delayMax);

        AuditLogService::log(
            $request->user(),
            'update_view_back_settings',
            'AdminSetting',
            null,
            "enabled={$enabled}, probability={$probability}%, delay={$delayMin}-{$delayMax}min",
            false
        );

        return redirect()->route('admin.view-back-settings.index')
            ->with('success', 'View-back settings updated.');
    }

    /**
     * Demo search visibility (Day-8). Global toggle: show/hide demo profiles in search.
     */
    public function demoSearchSettings()
    {
        $visible = AdminSetting::getBool('demo_profiles_visible_in_search', true);

        return view('admin.demo-search-settings.index', [
            'demoProfilesVisibleInSearch' => $visible,
        ]);
    }

    /**
     * Update demo search visibility. Persisted via AdminSetting.
     */
    public function updateDemoSearchSettings(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'demo_profiles_visible_in_search' => 'nullable|in:0,1',
        ]);

        $visible = $request->has('demo_profiles_visible_in_search') ? '1' : '0';
        AdminSetting::setValue('demo_profiles_visible_in_search', $visible);

        AuditLogService::log(
            $request->user(),
            'update_demo_search_settings',
            'AdminSetting',
            null,
            "demo_profiles_visible_in_search={$visible}",
            false
        );

        return redirect()->route('admin.demo-search-settings.index')
            ->with('success', 'Showcase search visibility updated.');
    }

    /**
     * Photo approval setting: require admin approval before user photos are visible to others.
     * Default: approval not required (photos visible immediately).
     */
    public function photoApprovalSettings()
    {
        $required = AdminSetting::getBool('photo_approval_required', false);
        $primaryRequired = AdminSetting::getBool('photo_primary_required', true);
        $maxPerProfile = (int) AdminSetting::getValue('photo_max_per_profile', '5');
        $maxUploadMb = (int) AdminSetting::getValue('photo_max_upload_mb', '8');
        $maxEdgePx = (int) AdminSetting::getValue('photo_max_edge_px', '1200');

        return view('admin.photo-approval-settings.index', [
            'photoApprovalRequired' => $required,
            'photoPrimaryRequired' => $primaryRequired,
            'photoMaxPerProfile' => max(1, $maxPerProfile),
            'photoMaxUploadMb' => max(1, $maxUploadMb),
            'photoMaxEdgePx' => max(400, $maxEdgePx),
        ]);
    }

    /**
     * Intake engine settings: rate limits and basic parsing behaviour (Day-35).
     */
    public function intakeSettings()
    {
        $daily = (int) AdminSetting::getValue('intake_max_daily_per_user', '5');
        $monthly = (int) AdminSetting::getValue('intake_max_monthly_per_user', '20');
        $maxPdfMb = (int) AdminSetting::getValue('intake_max_pdf_mb', '10');
        $maxPdfPages = (int) AdminSetting::getValue('intake_max_pdf_pages', '8');
        $maxImagesPerIntake = (int) AdminSetting::getValue('intake_max_images_per_intake', '5');
        $globalDailyCap = (int) AdminSetting::getValue('intake_global_daily_cap', '0');
        $autoParse = AdminSetting::getBool('intake_auto_parse_enabled', true);
        $resolver = app(ProviderResolver::class);
        $processingMode = $resolver->processingMode();
        $primaryAiProvider = $resolver->primaryAiProvider();
        $hybridExtractionProvider = $resolver->hybridExtractionProvider();
        $hybridParserProvider = $resolver->hybridParserProvider();
        $hybridOcrFallback = $resolver->hybridOcrFallback();
        $ocrProvider = AdminSetting::getValue('intake_ocr_provider', 'tesseract');
        $ocrLanguage = AdminSetting::getValue('intake_ocr_language_hint', 'mixed');
        $retryLimit = (int) AdminSetting::getValue('intake_parse_retry_limit', '3');
        $highThreshold = (float) AdminSetting::getValue('intake_confidence_high_threshold', '0.85');
        $autoApplyJson = AdminSetting::getValue('intake_auto_apply_fields', '[]');
        $autoApplyFields = json_decode($autoApplyJson, true);
        if (! is_array($autoApplyFields)) {
            $autoApplyFields = [];
        }
        $retentionDays = (int) AdminSetting::getValue('intake_file_retention_days', '90');
        $keepParsedJson = AdminSetting::getBool('intake_keep_parsed_json_after_purge', true);

        return view('admin.intake-settings.index', [
            'dailyLimit' => max(0, $daily),
            'monthlyLimit' => max(0, $monthly),
            'maxPdfMb' => max(1, $maxPdfMb),
            'maxPdfPages' => max(1, $maxPdfPages),
            'maxImagesPerIntake' => max(1, $maxImagesPerIntake),
            'globalDailyCap' => max(0, $globalDailyCap),
            'autoParseEnabled' => $autoParse,
            'processingMode' => $processingMode,
            'primaryAiProvider' => $primaryAiProvider,
            'hybridExtractionProvider' => $hybridExtractionProvider,
            'hybridParserProvider' => $hybridParserProvider,
            'hybridOcrFallback' => $hybridOcrFallback,
            'ocrProvider' => $ocrProvider,
            'ocrLanguageHint' => $ocrLanguage,
            'parseRetryLimit' => max(0, $retryLimit),
            'confidenceHighThreshold' => $highThreshold > 0 && $highThreshold < 1 ? $highThreshold : 0.85,
            'autoApplyFields' => $autoApplyFields,
            'requireAdminBeforeAttach' => AdminSetting::getBool('intake_require_admin_before_attach', false),
            'fileRetentionDays' => max(0, $retentionDays),
            'keepParsedJsonAfterPurge' => $keepParsedJson,
        ]);
    }

    /**
     * Update intake engine settings.
     */
    public function updateIntakeSettings(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'intake_max_daily_per_user' => 'required|integer|min:0|max:50',
            'intake_max_monthly_per_user' => 'required|integer|min:0|max:200',
            'intake_max_pdf_mb' => 'required|integer|min:1|max:20',
            'intake_max_pdf_pages' => 'required|integer|min:1|max:50',
            'intake_max_images_per_intake' => 'required|integer|min:1|max:10',
            'intake_global_daily_cap' => 'required|integer|min:0|max:10000',
            'intake_auto_parse_enabled' => 'nullable|in:0,1',
            'intake_processing_mode' => 'required|string|in:end_to_end,hybrid',
            'intake_primary_ai_provider' => [
                Rule::requiredIf(fn () => $request->input('intake_processing_mode') === ProviderResolver::MODE_END_TO_END),
                'nullable',
                'string',
                Rule::in(['openai', 'sarvam']),
            ],
            'intake_hybrid_extraction_provider' => [
                Rule::requiredIf(fn () => $request->input('intake_processing_mode') === ProviderResolver::MODE_HYBRID),
                'nullable',
                'string',
                Rule::in(['openai', 'sarvam', 'tesseract']),
            ],
            'intake_hybrid_parser_provider' => [
                Rule::requiredIf(fn () => $request->input('intake_processing_mode') === ProviderResolver::MODE_HYBRID),
                'nullable',
                'string',
                Rule::in(['openai', 'sarvam']),
            ],
            'intake_hybrid_ocr_fallback' => [
                Rule::requiredIf(fn () => $request->input('intake_processing_mode') === ProviderResolver::MODE_HYBRID),
                'nullable',
                'string',
                Rule::in(['tesseract', 'off']),
            ],
            'intake_ocr_language_hint' => 'required|string|in:mr,en,mixed',
            'intake_parse_retry_limit' => 'required|integer|min:0|max:5',
            'intake_confidence_high_threshold' => 'required|numeric|min:0.5|max:0.99',
            'intake_auto_apply_fields' => 'array',
            'intake_auto_apply_fields.*' => 'string',
            'intake_require_admin_before_attach' => 'nullable|in:0,1',
            'intake_file_retention_days' => 'required|integer|min:0|max:365',
            'intake_keep_parsed_json_after_purge' => 'nullable|in:0,1',
        ]);

        $daily = (string) $request->input('intake_max_daily_per_user', 5);
        $monthly = (string) $request->input('intake_max_monthly_per_user', 20);
        $maxPdfMb = (string) $request->input('intake_max_pdf_mb', 10);
        $maxPdfPages = (string) $request->input('intake_max_pdf_pages', 8);
        $maxImagesPerIntake = (string) $request->input('intake_max_images_per_intake', 5);
        $globalDailyCap = (string) $request->input('intake_global_daily_cap', 0);
        $autoParse = $request->has('intake_auto_parse_enabled') ? '1' : '0';
        $processingMode = (string) $request->input('intake_processing_mode', ProviderResolver::MODE_END_TO_END);
        $primaryAiProvider = strtolower(trim((string) $request->input('intake_primary_ai_provider', 'openai')));
        if (! in_array($primaryAiProvider, ['openai', 'sarvam'], true)) {
            $primaryAiProvider = 'openai';
        }
        $hybridExtraction = strtolower(trim((string) $request->input('intake_hybrid_extraction_provider', 'openai')));
        if (! in_array($hybridExtraction, ['openai', 'sarvam', 'tesseract'], true)) {
            $hybridExtraction = 'openai';
        }
        $hybridParser = strtolower(trim((string) $request->input('intake_hybrid_parser_provider', 'openai')));
        if (! in_array($hybridParser, ['openai', 'sarvam'], true)) {
            $hybridParser = 'openai';
        }
        $hybridOcrFallback = strtolower(trim((string) $request->input('intake_hybrid_ocr_fallback', 'tesseract')));
        if (! in_array($hybridOcrFallback, ['tesseract', 'off'], true)) {
            $hybridOcrFallback = 'tesseract';
        }

        $activeParser = '';
        $aiVisionProvider = '';
        $ocrProvider = (string) AdminSetting::getValue('intake_ocr_provider', 'tesseract');

        if ($processingMode === ProviderResolver::MODE_END_TO_END) {
            $activeParser = 'ai_vision_extract_v1';
            $aiVisionProvider = $primaryAiProvider;
        } elseif ($processingMode === ProviderResolver::MODE_HYBRID) {
            $activeParser = 'hybrid_v1';
            if ($hybridExtraction === 'openai' || $hybridExtraction === 'sarvam') {
                $aiVisionProvider = $hybridExtraction;
            } else {
                $aiVisionProvider = '';
            }
            $ocrProvider = $hybridOcrFallback === 'off' ? 'off' : 'tesseract';
        } else {
            $processingMode = ProviderResolver::MODE_END_TO_END;
            $activeParser = 'ai_vision_extract_v1';
            $aiVisionProvider = $primaryAiProvider;
        }
        $ocrLanguage = (string) $request->input('intake_ocr_language_hint', 'mixed');
        $retryLimit = (string) $request->input('intake_parse_retry_limit', 3);
        $highThreshold = (string) $request->input('intake_confidence_high_threshold', 0.85);
        $autoApplyInput = $request->input('intake_auto_apply_fields', []);
        $allowedAutoApply = ['full_name', 'date_of_birth', 'gender', 'religion', 'caste', 'sub_caste', 'marital_status'];
        $autoApplyFiltered = [];
        if (is_array($autoApplyInput)) {
            foreach ($autoApplyInput as $fieldKey) {
                if (in_array($fieldKey, $allowedAutoApply, true)) {
                    $autoApplyFiltered[] = $fieldKey;
                }
            }
        }
        $autoApplyJson = json_encode(array_values(array_unique($autoApplyFiltered)));
        $requireAdminBeforeAttach = $request->has('intake_require_admin_before_attach') ? '1' : '0';
        $fileRetentionDays = (string) $request->input('intake_file_retention_days', 90);
        $keepParsedJson = $request->has('intake_keep_parsed_json_after_purge') ? '1' : '0';

        AdminSetting::setValue('intake_max_daily_per_user', $daily);
        AdminSetting::setValue('intake_max_monthly_per_user', $monthly);
        AdminSetting::setValue('intake_max_pdf_mb', $maxPdfMb);
        AdminSetting::setValue('intake_max_pdf_pages', $maxPdfPages);
        AdminSetting::setValue('intake_max_images_per_intake', $maxImagesPerIntake);
        AdminSetting::setValue('intake_global_daily_cap', $globalDailyCap);
        AdminSetting::setValue('intake_auto_parse_enabled', $autoParse);
        AdminSetting::setValue('intake_processing_mode', $processingMode);
        AdminSetting::setValue('intake_active_parser', $activeParser);
        AdminSetting::setValue('intake_ai_vision_provider', $aiVisionProvider);
        if ($processingMode === ProviderResolver::MODE_END_TO_END) {
            AdminSetting::setValue('intake_primary_ai_provider', $primaryAiProvider);
        }
        if ($processingMode === ProviderResolver::MODE_HYBRID) {
            AdminSetting::setValue('intake_hybrid_extraction_provider', $hybridExtraction);
            AdminSetting::setValue('intake_hybrid_parser_provider', $hybridParser);
            AdminSetting::setValue('intake_hybrid_ocr_fallback', $hybridOcrFallback);
            AdminSetting::setValue('intake_ocr_provider', $ocrProvider);
        }
        AdminSetting::setValue('intake_ocr_language_hint', $ocrLanguage);
        AdminSetting::setValue('intake_parse_retry_limit', $retryLimit);
        AdminSetting::setValue('intake_confidence_high_threshold', $highThreshold);
        AdminSetting::setValue('intake_auto_apply_fields', $autoApplyJson);
        AdminSetting::setValue('intake_require_admin_before_attach', $requireAdminBeforeAttach);
        AdminSetting::setValue('intake_file_retention_days', $fileRetentionDays);
        AdminSetting::setValue('intake_keep_parsed_json_after_purge', $keepParsedJson);

        AuditLogService::log(
            $request->user(),
            'update_intake_settings',
            'AdminSetting',
            null,
            "intake_max_daily_per_user={$daily}, intake_max_monthly_per_user={$monthly}, intake_max_pdf_mb={$maxPdfMb}, intake_max_pdf_pages={$maxPdfPages}, intake_max_images_per_intake={$maxImagesPerIntake}, intake_global_daily_cap={$globalDailyCap}, intake_auto_parse_enabled={$autoParse}, intake_processing_mode={$processingMode}, intake_primary_ai_provider={$primaryAiProvider}, intake_hybrid_extraction_provider={$hybridExtraction}, intake_hybrid_parser_provider={$hybridParser}, intake_hybrid_ocr_fallback={$hybridOcrFallback}, intake_active_parser={$activeParser}, intake_ai_vision_provider={$aiVisionProvider}, intake_ocr_provider={$ocrProvider}, intake_ocr_language_hint={$ocrLanguage}, intake_parse_retry_limit={$retryLimit}, intake_confidence_high_threshold={$highThreshold}, intake_auto_apply_fields=".implode(',', $autoApplyFiltered).", intake_require_admin_before_attach={$requireAdminBeforeAttach}, intake_file_retention_days={$fileRetentionDays}, intake_keep_parsed_json_after_purge={$keepParsedJson}",
            false
        );

        return redirect()->route('admin.intake-settings.index')
            ->with('success', 'Intake settings updated.');
    }

    /**
     * Update photo approval setting. Persisted via AdminSetting. Audit logged.
     */
    public function updatePhotoApprovalSettings(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'photo_approval_required' => 'nullable|in:0,1',
            'photo_primary_required' => 'nullable|in:0,1',
            'photo_max_per_profile' => 'required|integer|min:1|max:10',
            'photo_max_upload_mb' => 'required|integer|min:1|max:20',
            'photo_max_edge_px' => 'required|integer|min:400|max:2400',
        ]);

        $value = $request->has('photo_approval_required') ? '1' : '0';
        $primaryRequired = $request->has('photo_primary_required') ? '1' : '0';
        $maxPerProfile = (string) $request->input('photo_max_per_profile', 5);
        $maxUploadMb = (string) $request->input('photo_max_upload_mb', 8);
        $maxEdgePx = (string) $request->input('photo_max_edge_px', 1200);
        AdminSetting::setValue('photo_approval_required', $value);
        AdminSetting::setValue('photo_primary_required', $primaryRequired);
        AdminSetting::setValue('photo_max_per_profile', $maxPerProfile);
        AdminSetting::setValue('photo_max_upload_mb', $maxUploadMb);
        AdminSetting::setValue('photo_max_edge_px', $maxEdgePx);

        AuditLogService::log(
            $request->user(),
            'update_photo_approval_settings',
            'AdminSetting',
            null,
            "photo_approval_required={$value}, photo_primary_required={$primaryRequired}, photo_max_per_profile={$maxPerProfile}, photo_max_upload_mb={$maxUploadMb}, photo_max_edge_px={$maxEdgePx}",
            false
        );

        return redirect()->route('admin.photo-approval-settings.index')
            ->with('success', 'Photo approval setting updated.');
    }

    /**
     * Registration & mobile verification settings (redirect after register, OTP mode).
     */
    public function mobileVerificationSettings()
    {
        $redirectAfterRegister = AdminSetting::getBool('redirect_to_mobile_verify_after_registration', true);
        $mode = AdminSetting::getValue('mobile_verification_mode', 'off');

        return view('admin.mobile-verification-settings.index', [
            'redirectAfterRegister' => $redirectAfterRegister,
            'mobileVerificationMode' => $mode,
        ]);
    }

    public function updateMobileVerificationSettings(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'redirect_to_mobile_verify_after_registration' => 'nullable',
            'mobile_verification_mode' => 'required|in:off,dev_show,live',
        ]);
        $redirect = $request->boolean('redirect_to_mobile_verify_after_registration');
        $mode = $request->input('mobile_verification_mode', 'off');
        AdminSetting::setValue('redirect_to_mobile_verify_after_registration', $redirect ? '1' : '0');
        AdminSetting::setValue('mobile_verification_mode', $mode);
        AuditLogService::log(
            $request->user(),
            'update_mobile_verification_settings',
            'AdminSetting',
            null,
            "redirect_after_register={$redirect}, mode={$mode}",
            false
        );

        return redirect()->route('admin.mobile-verification-settings.index')
            ->with('success', 'Registration & mobile verification settings updated.');
    }

    /**
     * List all profile field configurations (Day-17).
     */
    public function profileFieldConfigIndex()
    {
        $fieldConfigs = ProfileFieldConfig::orderBy('field_key')->get();

        return view('admin.profile-field-config.index', [
            'fieldConfigs' => $fieldConfigs,
        ]);
    }

    /**
     * Update profile field configuration flags (Day-17).
     * Bulk update: updates all fields in single request.
     */
    public function profileFieldConfigUpdate(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'reason' => self::REASON_RULES,
            'fields' => 'required|array',
            'fields.*.id' => 'required|integer|exists:profile_field_configs,id',
            // Checkboxes are optional (absent = unchecked)
            'fields.*.is_enabled' => 'sometimes|in:1',
            'fields.*.is_visible' => 'sometimes|in:1',
            'fields.*.is_searchable' => 'sometimes|in:1',
            'fields.*.is_mandatory' => 'sometimes|in:1',
        ]);

        $updatedCount = 0;
        $changes = [];

        foreach ($request->input('fields', []) as $fieldData) {
            $field = ProfileFieldConfig::findOrFail($fieldData['id']);
            $original = [
                'is_enabled' => $field->is_enabled,
                'is_visible' => $field->is_visible,
                'is_searchable' => $field->is_searchable,
                'is_mandatory' => $field->is_mandatory,
            ];

            // HTML checkboxes: present = checked (value='1'), absent = unchecked
            $updates = [
                'is_enabled' => isset($fieldData['is_enabled']) && $fieldData['is_enabled'] == '1',
                'is_visible' => isset($fieldData['is_visible']) && $fieldData['is_visible'] == '1',
                'is_searchable' => isset($fieldData['is_searchable']) && $fieldData['is_searchable'] == '1',
                'is_mandatory' => isset($fieldData['is_mandatory']) && $fieldData['is_mandatory'] == '1',
            ];

            // Only update if there are actual changes
            if ($updates !== $original) {
                $field->update($updates);
                $updatedCount++;
                $fieldChanges = [];
                foreach ($updates as $key => $value) {
                    if ($value !== $original[$key]) {
                        $fieldChanges[] = "{$key}: ".($original[$key] ? 'true' : 'false').' → '.($value ? 'true' : 'false');
                    }
                }
                $changes[] = $field->field_key.' ('.implode(', ', $fieldChanges).')';
            }
        }

        if ($updatedCount > 0) {
            AuditLogService::log(
                $request->user(),
                'profile_field_config_update',
                'profile_field_configs',
                null,
                "Updated {$updatedCount} field(s). Changes: ".implode('; ', $changes).". Reason: {$request->reason}",
                false
            );
        }

        return redirect()->route('admin.profile-field-config.index')
            ->with('success', "Updated {$updatedCount} field configuration(s).");
    }

    /**
     * App-wide toggles (admin bypass mode, …) via {@see SettingService} / {@see AdminSetting}.
     */
    public function appSettings(SettingService $settings): \Illuminate\View\View
    {
        $raw = $settings->get('admin_bypass_mode');
        $adminBypassMode = $raw === null
            ? (bool) config('app.admin_bypass_mode', false)
            : filter_var($raw, FILTER_VALIDATE_BOOLEAN);

        return view('admin.app-settings.index', [
            'adminBypassMode' => $adminBypassMode,
        ]);
    }

    public function updateAppSettings(Request $request, SettingService $settings): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'admin_bypass_mode' => 'nullable|in:0,1',
        ]);

        $on = $request->boolean('admin_bypass_mode');
        $settings->set('admin_bypass_mode', $on);

        AuditLogService::log(
            $request->user(),
            'update_app_settings',
            'AdminSetting',
            null,
            'admin_bypass_mode='.($on ? '1' : '0'),
            false
        );

        return redirect()->route('admin.app-settings.index')
            ->with('success', 'App settings updated.');
    }
}
