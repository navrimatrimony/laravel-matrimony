<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Models\ProfileFieldConfig;
use App\Services\AuditLogService;
use App\Services\MemberPresencePresentationService;
use App\Services\Parsing\ProviderResolver;
use App\Services\ProfileCompletenessService;
use App\Services\SettingService;
use App\Services\Showcase\ShowcaseInterestPolicyService;
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

        $rvMin = max(1, (int) AdminSetting::getValue('showcase_random_view_revisit_random_min_days', '3'));
        $rvMax = max($rvMin, (int) AdminSetting::getValue('showcase_random_view_revisit_random_max_days', '14'));

        return view('admin.view-back-settings.index', [
            'viewBackEnabled' => $enabled,
            'viewBackProbability' => $probability,
            'viewBackDelayMin' => max(0, $delayMin),
            'viewBackDelayMax' => max(0, $delayMax),
            'randomViewEnabled' => AdminSetting::getBool('showcase_random_view_enabled', false),
            'randomViewRevisitMode' => (string) AdminSetting::getValue('showcase_random_view_revisit_mode', '30d'),
            'randomViewRevisitRandomMinDays' => $rvMin,
            'randomViewRevisitRandomMaxDays' => $rvMax,
            'randomViewBatchPerRun' => max(0, (int) AdminSetting::getValue('showcase_random_view_batch_per_run', '15')),
            'randomViewCandidatePool' => max(30, (int) AdminSetting::getValue('showcase_random_view_candidate_pool', '120')),
            'randomViewMaxPerRealWeek' => max(0, (int) AdminSetting::getValue('showcase_random_view_max_per_real_per_week', '5')),
            'randomViewMaxPerRealMonth' => max(0, (int) AdminSetting::getValue('showcase_random_view_max_per_real_per_month', '10')),
            'randomViewAgeSpreadYears' => max(1, (int) AdminSetting::getValue('showcase_random_view_age_spread_years', '6')),
            'randomViewNewUserDays' => max(1, (int) AdminSetting::getValue('showcase_random_view_new_user_days', '30')),
            'randomViewWeightDistrict' => max(0, (int) AdminSetting::getValue('showcase_random_view_weight_district', '40')),
            'randomViewWeightReligion' => max(0, (int) AdminSetting::getValue('showcase_random_view_weight_religion', '30')),
            'randomViewWeightCaste' => max(0, (int) AdminSetting::getValue('showcase_random_view_weight_caste', '30')),
            'randomViewWeightAge' => max(0, (int) AdminSetting::getValue('showcase_random_view_weight_age', '20')),
            'randomViewWeightNewUser' => max(0, (int) AdminSetting::getValue('showcase_random_view_weight_new_user', '35')),
            'randomViewWeightBase' => max(0, (int) AdminSetting::getValue('showcase_random_view_weight_base', '10')),
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
     * Showcase → real random views (scheduled). Weighted matching + per-real caps.
     */
    public function updateShowcaseRandomViewSettings(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'showcase_random_view_enabled' => 'nullable|in:0,1',
            'showcase_random_view_revisit_mode' => 'required|string|in:never,1d,7d,30d,random',
            'showcase_random_view_revisit_random_min_days' => 'required|integer|min:1|max:365',
            'showcase_random_view_revisit_random_max_days' => 'required|integer|min:1|max:365',
            'showcase_random_view_batch_per_run' => 'required|integer|min:0|max:500',
            'showcase_random_view_candidate_pool' => 'required|integer|min:30|max:500',
            'showcase_random_view_max_per_real_per_week' => 'required|integer|min:0|max:999',
            'showcase_random_view_max_per_real_per_month' => 'required|integer|min:0|max:999',
            'showcase_random_view_age_spread_years' => 'required|integer|min:1|max:40',
            'showcase_random_view_new_user_days' => 'required|integer|min:1|max:365',
            'showcase_random_view_weight_district' => 'required|integer|min:0|max:500',
            'showcase_random_view_weight_religion' => 'required|integer|min:0|max:500',
            'showcase_random_view_weight_caste' => 'required|integer|min:0|max:500',
            'showcase_random_view_weight_age' => 'required|integer|min:0|max:500',
            'showcase_random_view_weight_new_user' => 'required|integer|min:0|max:500',
            'showcase_random_view_weight_base' => 'required|integer|min:0|max:500',
        ]);

        $minRand = (int) $request->input('showcase_random_view_revisit_random_min_days', 3);
        $maxRand = (int) $request->input('showcase_random_view_revisit_random_max_days', 14);
        if ($maxRand < $minRand) {
            $maxRand = $minRand;
        }

        $enabled = $request->has('showcase_random_view_enabled') ? '1' : '0';

        AdminSetting::setValue('showcase_random_view_enabled', $enabled);
        AdminSetting::setValue('showcase_random_view_revisit_mode', (string) $request->input('showcase_random_view_revisit_mode'));
        AdminSetting::setValue('showcase_random_view_revisit_random_min_days', (string) $minRand);
        AdminSetting::setValue('showcase_random_view_revisit_random_max_days', (string) $maxRand);
        AdminSetting::setValue('showcase_random_view_batch_per_run', (string) $request->input('showcase_random_view_batch_per_run'));
        AdminSetting::setValue('showcase_random_view_candidate_pool', (string) $request->input('showcase_random_view_candidate_pool'));
        AdminSetting::setValue('showcase_random_view_max_per_real_per_week', (string) $request->input('showcase_random_view_max_per_real_per_week'));
        AdminSetting::setValue('showcase_random_view_max_per_real_per_month', (string) $request->input('showcase_random_view_max_per_real_per_month'));
        AdminSetting::setValue('showcase_random_view_age_spread_years', (string) $request->input('showcase_random_view_age_spread_years'));
        AdminSetting::setValue('showcase_random_view_new_user_days', (string) $request->input('showcase_random_view_new_user_days'));
        AdminSetting::setValue('showcase_random_view_weight_district', (string) $request->input('showcase_random_view_weight_district'));
        AdminSetting::setValue('showcase_random_view_weight_religion', (string) $request->input('showcase_random_view_weight_religion'));
        AdminSetting::setValue('showcase_random_view_weight_caste', (string) $request->input('showcase_random_view_weight_caste'));
        AdminSetting::setValue('showcase_random_view_weight_age', (string) $request->input('showcase_random_view_weight_age'));
        AdminSetting::setValue('showcase_random_view_weight_new_user', (string) $request->input('showcase_random_view_weight_new_user'));
        AdminSetting::setValue('showcase_random_view_weight_base', (string) $request->input('showcase_random_view_weight_base'));

        AuditLogService::log(
            $request->user(),
            'update_showcase_random_view_settings',
            'AdminSetting',
            null,
            "showcase_random_view_enabled={$enabled}, revisit=".(string) $request->input('showcase_random_view_revisit_mode'),
            false
        );

        return redirect()->route('admin.view-back-settings.index')
            ->with('success', 'Showcase random view settings updated.');
    }

    /**
     * Showcase search visibility (Day-8). Global toggle: show/hide showcase profiles in search.
     */
    public function showcaseSearchSettings()
    {
        $visible = AdminSetting::getBool('showcase_profiles_visible_in_search', true);
        $oppositeGenderOnly = AdminSetting::getBool('search_opposite_gender_only', false);

        return view('admin.showcase-search-settings.index', [
            'showcaseProfilesVisibleInSearch' => $visible,
            'searchOppositeGenderOnly' => $oppositeGenderOnly,
        ]);
    }

    /**
     * Update showcase search visibility. Persisted via AdminSetting.
     */
    public function updateShowcaseSearchSettings(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'showcase_profiles_visible_in_search' => 'nullable|in:0,1',
            'search_opposite_gender_only' => 'nullable|in:0,1',
        ]);

        $visible = $request->has('showcase_profiles_visible_in_search') ? '1' : '0';
        AdminSetting::setValue('showcase_profiles_visible_in_search', $visible);
        $oppositeOnly = $request->has('search_opposite_gender_only') ? '1' : '0';
        AdminSetting::setValue('search_opposite_gender_only', $oppositeOnly);

        AuditLogService::log(
            $request->user(),
            'update_showcase_search_settings',
            'AdminSetting',
            null,
            "showcase_profiles_visible_in_search={$visible}, search_opposite_gender_only={$oppositeOnly}",
            false
        );

        return redirect()->route('admin.showcase-search-settings.index')
            ->with('success', 'Search visibility settings updated.');
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
        $moderationMode = (string) AdminSetting::getValue('photo_moderation_mode', 'manual'); // auto|manual
        $moderationMode = in_array($moderationMode, ['auto', 'manual'], true) ? $moderationMode : 'manual';
        $aiProvider = (string) AdminSetting::getValue('photo_ai_provider', 'openai'); // openai|sarvam
        $aiProvider = in_array($aiProvider, ['openai', 'sarvam'], true) ? $aiProvider : 'openai';

        $verifySafeAi = AdminSetting::getBool('photo_verify_safe_with_secondary_ai', false);

        return view('admin.photo-approval-settings.index', [
            'photoApprovalRequired' => $required,
            'photoVerifySafeWithSecondaryAi' => $verifySafeAi,
            'photoPrimaryRequired' => $primaryRequired,
            'photoMaxPerProfile' => max(1, $maxPerProfile),
            'photoMaxUploadMb' => max(1, $maxUploadMb),
            'photoMaxEdgePx' => max(400, $maxEdgePx),
            'photoModerationMode' => $moderationMode,
            'photoAiProvider' => $aiProvider,
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
            'photo_verify_safe_with_secondary_ai' => 'nullable|in:0,1',
            'photo_primary_required' => 'nullable|in:0,1',
            'photo_max_per_profile' => 'required|integer|min:1|max:10',
            'photo_max_upload_mb' => 'required|integer|min:1|max:20',
            'photo_max_edge_px' => 'required|integer|min:400|max:2400',
            'photo_moderation_mode' => ['required', 'string', Rule::in(['auto', 'manual'])],
            'photo_ai_provider' => ['required', 'string', Rule::in(['openai', 'sarvam'])],
        ]);

        $value = $request->has('photo_approval_required') ? '1' : '0';
        $verifySafeAi = $request->has('photo_verify_safe_with_secondary_ai') ? '1' : '0';
        $primaryRequired = $request->has('photo_primary_required') ? '1' : '0';
        $maxPerProfile = (string) $request->input('photo_max_per_profile', 5);
        $maxUploadMb = (string) $request->input('photo_max_upload_mb', 8);
        $maxEdgePx = (string) $request->input('photo_max_edge_px', 1200);
        $moderationMode = (string) $request->input('photo_moderation_mode', 'manual');
        $aiProvider = (string) $request->input('photo_ai_provider', 'openai');
        AdminSetting::setValue('photo_approval_required', $value);
        AdminSetting::setValue('photo_verify_safe_with_secondary_ai', $verifySafeAi);
        AdminSetting::setValue('photo_primary_required', $primaryRequired);
        AdminSetting::setValue('photo_max_per_profile', $maxPerProfile);
        AdminSetting::setValue('photo_max_upload_mb', $maxUploadMb);
        AdminSetting::setValue('photo_max_edge_px', $maxEdgePx);
        AdminSetting::setValue('photo_moderation_mode', $moderationMode);
        AdminSetting::setValue('photo_ai_provider', $aiProvider);

        AuditLogService::log(
            $request->user(),
            'update_photo_approval_settings',
            'AdminSetting',
            null,
            "photo_approval_required={$value}, photo_verify_safe_with_secondary_ai={$verifySafeAi}, photo_primary_required={$primaryRequired}, photo_max_per_profile={$maxPerProfile}, photo_max_upload_mb={$maxUploadMb}, photo_max_edge_px={$maxEdgePx}, photo_moderation_mode={$moderationMode}, photo_ai_provider={$aiProvider}",
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
        $viewer = auth()->user();
        $raw = $settings->get('admin_bypass_mode');
        $adminBypassMode = $raw === null
            ? (bool) config('app.admin_bypass_mode', false)
            : filter_var($raw, FILTER_VALIDATE_BOOLEAN);

        $interestMinCorePct = app(\App\Services\RuleEngineService::class)->resolveInterestMinimumPercent();

        $presenceOnlineThresholdMin = max(1, min(
            24 * 60,
            (int) AdminSetting::getValue(
                MemberPresencePresentationService::SETTING_KEY_ONLINE_THRESHOLD_MINUTES,
                '5'
            )
        ));

        return view('admin.app-settings.index', [
            'adminBypassMode' => $adminBypassMode,
            'interestMinCorePct' => $interestMinCorePct,
            'presenceOnlineThresholdMin' => $presenceOnlineThresholdMin,
            'plansEnforceGenderSpecificVisibility' => AdminSetting::getBool('plans_enforce_gender_specific_visibility', true),
            'canManageBillingSettings' => $viewer?->hasAdminRole(['super_admin']) ?? false,
            'billingLegalName' => (string) AdminSetting::getValue('billing_legal_name', ''),
            'billingAddress' => (string) AdminSetting::getValue('billing_address', ''),
            'billingEmail' => (string) AdminSetting::getValue('billing_email', ''),
            'billingPhone' => (string) AdminSetting::getValue('billing_phone', ''),
            'billingGstin' => (string) AdminSetting::getValue('billing_gstin', ''),
            'billingPan' => (string) AdminSetting::getValue('billing_pan', ''),
            'billingStateCode' => (string) AdminSetting::getValue('billing_state_code', ''),
            'billingInvoicePrefix' => (string) AdminSetting::getValue('billing_invoice_prefix', ''),
            'billingInvoiceTerms' => (string) AdminSetting::getValue('billing_invoice_terms', ''),
            'successRateThreshold' => (string) AdminSetting::getValue('success_rate_threshold', '85'),
            'webhookFailureThreshold' => (string) AdminSetting::getValue('webhook_failure_threshold', '5'),
            'queueLagThreshold' => (string) AdminSetting::getValue('queue_lag_threshold', '120'),
            'invoiceFailureThreshold' => (string) AdminSetting::getValue('invoice_failure_threshold', '2'),
        ]);
    }

    public function updateAppSettings(Request $request, SettingService $settings): \Illuminate\Http\RedirectResponse
    {
        $canManageBillingSettings = $request->user()->hasAdminRole(['super_admin']);

        $request->validate([
            'admin_bypass_mode' => 'nullable|in:0,1',
            'interest_min_core_completeness_pct' => 'required|integer|min:0|max:100',
            'member_presence_online_threshold_minutes' => 'required|integer|min:1|max:1440',
            'plans_enforce_gender_specific_visibility' => 'nullable|in:0,1',
            'billing_legal_name' => [Rule::requiredIf($canManageBillingSettings), 'nullable', 'string', 'max:160'],
            'billing_address' => [Rule::requiredIf($canManageBillingSettings), 'nullable', 'string', 'max:1000'],
            'billing_email' => [Rule::requiredIf($canManageBillingSettings), 'nullable', 'email:rfc', 'max:190'],
            'billing_phone' => [Rule::requiredIf($canManageBillingSettings), 'nullable', 'string', 'max:32'],
            'billing_gstin' => 'nullable|string|max:32',
            'billing_pan' => 'nullable|string|max:32',
            'billing_state_code' => 'nullable|string|max:8',
            'billing_invoice_prefix' => 'nullable|string|max:24',
            'billing_invoice_terms' => 'nullable|string|max:3000',
            'success_rate_threshold' => 'required|numeric|min:1|max:100',
            'webhook_failure_threshold' => 'required|integer|min:1|max:10000',
            'queue_lag_threshold' => 'required|integer|min:1|max:10000',
            'invoice_failure_threshold' => 'required|numeric|min:0|max:100',
        ]);

        $on = $request->boolean('admin_bypass_mode');
        $settings->set('admin_bypass_mode', $on);

        $pct = max(0, min(100, (int) $request->input('interest_min_core_completeness_pct', 0)));
        AdminSetting::setValue(ProfileCompletenessService::ADMIN_KEY_INTEREST_MIN_CORE_PCT, (string) $pct);

        $presenceMin = max(1, min(24 * 60, (int) $request->input('member_presence_online_threshold_minutes', 5)));
        AdminSetting::setValue(
            MemberPresencePresentationService::SETTING_KEY_ONLINE_THRESHOLD_MINUTES,
            (string) $presenceMin
        );
        $plansGenderSpecific = $request->boolean('plans_enforce_gender_specific_visibility');
        AdminSetting::setValue('plans_enforce_gender_specific_visibility', $plansGenderSpecific ? '1' : '0');
        AdminSetting::setValue('success_rate_threshold', (string) $request->input('success_rate_threshold', '85'));
        AdminSetting::setValue('webhook_failure_threshold', (string) $request->input('webhook_failure_threshold', '5'));
        AdminSetting::setValue('queue_lag_threshold', (string) $request->input('queue_lag_threshold', '120'));
        AdminSetting::setValue('invoice_failure_threshold', (string) $request->input('invoice_failure_threshold', '2'));
        if ($canManageBillingSettings) {
            AdminSetting::setValue('billing_legal_name', trim((string) $request->input('billing_legal_name', '')));
            AdminSetting::setValue('billing_address', trim((string) $request->input('billing_address', '')));
            AdminSetting::setValue('billing_email', trim((string) $request->input('billing_email', '')));
            AdminSetting::setValue('billing_phone', trim((string) $request->input('billing_phone', '')));
            AdminSetting::setValue('billing_gstin', trim((string) $request->input('billing_gstin', '')));
            AdminSetting::setValue('billing_pan', trim((string) $request->input('billing_pan', '')));
            AdminSetting::setValue('billing_state_code', trim((string) $request->input('billing_state_code', '')));
            AdminSetting::setValue('billing_invoice_prefix', trim((string) $request->input('billing_invoice_prefix', '')));
            AdminSetting::setValue('billing_invoice_terms', trim((string) $request->input('billing_invoice_terms', '')));
        }

        AuditLogService::log(
            $request->user(),
            'update_app_settings',
            'AdminSetting',
            null,
            'admin_bypass_mode='.($on ? '1' : '0').'; interest_min_core_completeness_pct='.$pct.'; member_presence_online_threshold_minutes='.$presenceMin.'; plans_enforce_gender_specific_visibility='.($plansGenderSpecific ? '1' : '0').'; billing settings updated',
            false
        );

        return redirect()->route('admin.app-settings.index')
            ->with('success', 'App settings updated.');
    }

    /**
     * Future sync: thresholds for Python NudeNet (stored now; scanner still uses its own defaults until wired).
     */
    public function moderationEngineSettings(): \Illuminate\View\View
    {
        $nsfw = (string) AdminSetting::getValue('moderation_nsfw_score_min', '');
        $review = (string) AdminSetting::getValue('moderation_review_score_min', '');
        $ignoreRaw = (string) AdminSetting::getValue('moderation_ignore_classes', '[]');
        $ignoreList = json_decode($ignoreRaw, true);
        $ignoreCsv = is_array($ignoreList) ? implode(', ', array_map('strval', $ignoreList)) : '';

        return view('admin.moderation-engine-settings.index', [
            'nsfwScoreMin' => $nsfw,
            'reviewScoreMin' => $review,
            'ignoreClassesCsv' => $ignoreCsv,
        ]);
    }

    public function updateModerationEngineSettings(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'moderation_nsfw_score_min' => ['nullable', 'string', 'max:32'],
            'moderation_review_score_min' => ['nullable', 'string', 'max:32'],
            'moderation_ignore_classes' => ['nullable', 'string', 'max:5000'],
        ]);

        AdminSetting::setValue('moderation_nsfw_score_min', (string) $request->input('moderation_nsfw_score_min', ''));
        AdminSetting::setValue('moderation_review_score_min', (string) $request->input('moderation_review_score_min', ''));

        $csv = trim((string) $request->input('moderation_ignore_classes', ''));
        $parts = $csv === '' ? [] : array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $csv) ?: [])));
        AdminSetting::setValue('moderation_ignore_classes', json_encode(array_values($parts)));

        AuditLogService::log(
            $request->user(),
            'update_moderation_engine_settings',
            'AdminSetting',
            null,
            'moderation_nsfw_score_min / moderation_review_score_min / moderation_ignore_classes updated',
            false
        );

        return redirect()->route('admin.moderation-engine-settings.index')
            ->with('success', 'Moderation engine settings saved. The Python scanner pulls these values from GET /api/moderation-config on a short interval.');
    }

    /**
     * Showcase interest admin: mostly showcase → real sends; plus incoming auto-respond (real → showcase).
     * Other {@see ShowcaseInterestPolicyService} keys may remain in DB untouched.
     */
    public function showcaseInterestSettings(): \Illuminate\View\View
    {
        $p = ShowcaseInterestPolicyService::KEY_PREFIX;

        return view('admin.showcase-interest-settings.index', [
            'rulesEnabled' => AdminSetting::getBool($p.'rules_enabled', false),
            'bypassPlanSendQuotaForShowcaseSender' => AdminSetting::getBool($p.'bypass_plan_send_quota_for_showcase_sender', false),
            'allowShowcaseToReal' => AdminSetting::getBool($p.'allow_showcase_to_real', true),
            'requireOppositeGenderWhenAnyShowcase' => AdminSetting::getBool($p.'require_opposite_gender_when_any_showcase', true),
            'showcaseSenderMinSecondsBetweenSends' => max(0, (int) AdminSetting::getValue($p.'showcase_sender_min_seconds_between_sends', '0')),
            'showcaseSenderMaxSendsPer24h' => max(0, (int) AdminSetting::getValue($p.'showcase_sender_max_sends_per_24h', '0')),
            'showcaseSenderMaxSendsPer7d' => max(0, (int) AdminSetting::getValue($p.'showcase_sender_max_sends_per_7d', '0')),
            'allowShowcaseSenderWithdraw' => AdminSetting::getBool($p.'allow_showcase_sender_withdraw', true),
            'stochasticGatesEnabled' => AdminSetting::getBool($p.'stochastic_gates_enabled', false),
            'probSendPct' => max(0, min(100, (int) AdminSetting::getValue($p.'prob_send_pct', '100'))),
            'scaleProbByMatchWeight' => AdminSetting::getBool($p.'scale_prob_by_match_weight', true),
            'weightAge' => max(0, (int) AdminSetting::getValue($p.'weight_age', '25')),
            'weightReligion' => max(0, (int) AdminSetting::getValue($p.'weight_religion', '25')),
            'weightCaste' => max(0, (int) AdminSetting::getValue($p.'weight_caste', '25')),
            'weightDistrict' => max(0, (int) AdminSetting::getValue($p.'weight_district', '25')),
            'ageMatchMaxYearDiff' => max(0, (int) AdminSetting::getValue($p.'age_match_max_year_diff', '5')),
            'showcaseSenderMaxDistinctReceivers24h' => max(0, (int) AdminSetting::getValue($p.'showcase_sender_max_distinct_receivers_24h', '0')),
            'incomingAutoRespondEnabled' => AdminSetting::getBool($p.'incoming_auto_respond_enabled', false),
            'incomingAutoAcceptPct' => max(0, min(100, (int) AdminSetting::getValue($p.'incoming_auto_accept_pct', '50'))),
            'outgoingAutoSendEnabled' => AdminSetting::getBool($p.'outgoing_auto_send_enabled', false),
            'outgoingAutoBatchPerRun' => max(1, (int) AdminSetting::getValue($p.'outgoing_auto_batch_per_run', '50')),
            'outgoingAutoMaxSendsPerShowcasePerRun' => max(1, (int) AdminSetting::getValue($p.'outgoing_auto_max_sends_per_showcase_per_run', '1')),
            'outgoingAutoCandidatePool' => max(10, (int) AdminSetting::getValue($p.'outgoing_auto_candidate_pool', '120')),
        ]);
    }

    public function updateShowcaseInterestSettings(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'showcase_interest_rules_enabled' => 'nullable|in:0,1',
            'showcase_interest_bypass_plan_send_quota_for_showcase_sender' => 'nullable|in:0,1',
            'showcase_interest_allow_showcase_to_real' => 'nullable|in:0,1',
            'showcase_interest_require_opposite_gender_when_any_showcase' => 'nullable|in:0,1',
            'showcase_interest_showcase_sender_min_seconds_between_sends' => 'required|integer|min:0|max:864000',
            'showcase_interest_showcase_sender_max_sends_per_24h' => 'required|integer|min:0|max:99999',
            'showcase_interest_showcase_sender_max_sends_per_7d' => 'required|integer|min:0|max:999999',
            'showcase_interest_allow_showcase_sender_withdraw' => 'nullable|in:0,1',
            'showcase_interest_stochastic_gates_enabled' => 'nullable|in:0,1',
            'showcase_interest_prob_send_pct' => 'required|integer|min:0|max:100',
            'showcase_interest_scale_prob_by_match_weight' => 'nullable|in:0,1',
            'showcase_interest_weight_age' => 'required|integer|min:0|max:500',
            'showcase_interest_weight_religion' => 'required|integer|min:0|max:500',
            'showcase_interest_weight_caste' => 'required|integer|min:0|max:500',
            'showcase_interest_weight_district' => 'required|integer|min:0|max:500',
            'showcase_interest_age_match_max_year_diff' => 'required|integer|min:0|max:30',
            'showcase_interest_showcase_sender_max_distinct_receivers_24h' => 'required|integer|min:0|max:99999',
            'showcase_interest_incoming_auto_respond_enabled' => 'nullable|in:0,1',
            'showcase_interest_incoming_auto_accept_pct' => 'required|integer|min:0|max:100',
            'showcase_interest_outgoing_auto_send_enabled' => 'nullable|in:0,1',
            'showcase_interest_outgoing_auto_batch_per_run' => 'required|integer|min:1|max:2000',
            'showcase_interest_outgoing_auto_max_sends_per_showcase_per_run' => 'required|integer|min:1|max:20',
            'showcase_interest_outgoing_auto_candidate_pool' => 'required|integer|min:10|max:1000',
        ]);

        $p = ShowcaseInterestPolicyService::KEY_PREFIX;
        AdminSetting::setValue($p.'rules_enabled', $request->has('showcase_interest_rules_enabled') ? '1' : '0');
        AdminSetting::setValue($p.'bypass_plan_send_quota_for_showcase_sender', $request->has('showcase_interest_bypass_plan_send_quota_for_showcase_sender') ? '1' : '0');
        AdminSetting::setValue($p.'allow_showcase_to_real', $request->has('showcase_interest_allow_showcase_to_real') ? '1' : '0');
        AdminSetting::setValue($p.'require_opposite_gender_when_any_showcase', $request->has('showcase_interest_require_opposite_gender_when_any_showcase') ? '1' : '0');
        AdminSetting::setValue($p.'showcase_sender_min_seconds_between_sends', (string) (int) $request->input('showcase_interest_showcase_sender_min_seconds_between_sends', 0));
        AdminSetting::setValue($p.'showcase_sender_max_sends_per_24h', (string) (int) $request->input('showcase_interest_showcase_sender_max_sends_per_24h', 0));
        AdminSetting::setValue($p.'showcase_sender_max_sends_per_7d', (string) (int) $request->input('showcase_interest_showcase_sender_max_sends_per_7d', 0));
        AdminSetting::setValue($p.'allow_showcase_sender_withdraw', $request->has('showcase_interest_allow_showcase_sender_withdraw') ? '1' : '0');
        AdminSetting::setValue($p.'stochastic_gates_enabled', $request->has('showcase_interest_stochastic_gates_enabled') ? '1' : '0');
        AdminSetting::setValue($p.'prob_send_pct', (string) max(0, min(100, (int) $request->input('showcase_interest_prob_send_pct', 100))));
        AdminSetting::setValue($p.'scale_prob_by_match_weight', $request->has('showcase_interest_scale_prob_by_match_weight') ? '1' : '0');
        AdminSetting::setValue($p.'weight_age', (string) max(0, (int) $request->input('showcase_interest_weight_age', 0)));
        AdminSetting::setValue($p.'weight_religion', (string) max(0, (int) $request->input('showcase_interest_weight_religion', 0)));
        AdminSetting::setValue($p.'weight_caste', (string) max(0, (int) $request->input('showcase_interest_weight_caste', 0)));
        AdminSetting::setValue($p.'weight_district', (string) max(0, (int) $request->input('showcase_interest_weight_district', 0)));
        AdminSetting::setValue($p.'age_match_max_year_diff', (string) max(0, (int) $request->input('showcase_interest_age_match_max_year_diff', 5)));
        AdminSetting::setValue($p.'showcase_sender_max_distinct_receivers_24h', (string) max(0, (int) $request->input('showcase_interest_showcase_sender_max_distinct_receivers_24h', 0)));
        AdminSetting::setValue($p.'incoming_auto_respond_enabled', $request->has('showcase_interest_incoming_auto_respond_enabled') ? '1' : '0');
        AdminSetting::setValue($p.'incoming_auto_accept_pct', (string) max(0, min(100, (int) $request->input('showcase_interest_incoming_auto_accept_pct', 50))));
        AdminSetting::setValue($p.'outgoing_auto_send_enabled', $request->has('showcase_interest_outgoing_auto_send_enabled') ? '1' : '0');
        AdminSetting::setValue($p.'outgoing_auto_batch_per_run', (string) max(1, min(2000, (int) $request->input('showcase_interest_outgoing_auto_batch_per_run', 50))));
        AdminSetting::setValue($p.'outgoing_auto_max_sends_per_showcase_per_run', (string) max(1, min(20, (int) $request->input('showcase_interest_outgoing_auto_max_sends_per_showcase_per_run', 1))));
        AdminSetting::setValue($p.'outgoing_auto_candidate_pool', (string) max(10, min(1000, (int) $request->input('showcase_interest_outgoing_auto_candidate_pool', 120))));

        AuditLogService::log(
            $request->user(),
            'update_showcase_interest_settings',
            'AdminSetting',
            null,
            'showcase_interest_* (showcase interest admin page) updated',
            false
        );

        return redirect()->route('admin.showcase-interest-settings.index')
            ->with('success', 'Showcase interest rules saved.');
    }
}
