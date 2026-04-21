<?php

use App\Http\Controllers\AbuseReportController;
use App\Http\Controllers\Admin\AdminCapabilityController;
use App\Http\Controllers\Admin\AdminCasteController;
use App\Http\Controllers\Admin\AdminConflictRecordController;
use App\Http\Controllers\Admin\AdminCouponController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminFieldRegistryController;
use App\Http\Controllers\Admin\AdminIntakeController;
use App\Http\Controllers\Admin\AdminKycController;
use App\Http\Controllers\Admin\AdminOcrSimulationController;
use App\Http\Controllers\Admin\AdminProfileModerationController;
use App\Http\Controllers\Admin\AdminProfileTagController;
use App\Http\Controllers\Admin\AdminReligionController;
use App\Http\Controllers\Admin\AdminSeriousIntentController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminSuggestionReviewController;
use App\Http\Controllers\Admin\AdminUserNotificationsController;
use App\Http\Controllers\Admin\AdminVerificationTagController;
use App\Http\Controllers\Admin\AutoShowcaseSettingsController;
use App\Http\Controllers\Admin\CommerceAnalyticsController;
use App\Http\Controllers\Admin\CommerceMemberOverrideController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\DuplicatePhoneController;
use App\Http\Controllers\Admin\GovernanceDashboardController;
use App\Http\Controllers\Admin\HomepageImageController;
use App\Http\Controllers\Admin\HelpCentreTicketController;
use App\Http\Controllers\Admin\IntakeReviewController;
use App\Http\Controllers\Admin\LocationSuggestionWebController;
use App\Http\Controllers\Admin\MatchBoostController;
use App\Http\Controllers\Admin\MatchingEngineController;
use App\Http\Controllers\Admin\ModerationLearningController;
use App\Http\Controllers\Admin\OcrPatternController;
use App\Http\Controllers\Admin\PhotoModerationEngineController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\PlanFeatureController;
use App\Http\Controllers\Admin\ProfileBoostController;
use App\Http\Controllers\Admin\ReferralController;
use App\Http\Controllers\Admin\ShowcaseChatDebugController;
use App\Http\Controllers\Admin\ShowcaseChatSettingsController;
use App\Http\Controllers\Admin\ShowcaseConversationController;
use App\Http\Controllers\Admin\ShowcaseEngineDashboardController;
use App\Http\Controllers\Admin\ShowcaseProfileController;
use App\Http\Controllers\Admin\SubCasteAdminController;
use App\Http\Controllers\Admin\TranslationController;
use App\Http\Controllers\Admin\UserWalletController;
use App\Http\Controllers\Internal\Admin\CityAliasAdminController;
use App\Http\Controllers\Internal\Admin\LocationSuggestionAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web — admin surface (auth + admin middleware)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::redirect('/', '/admin/dashboard', 302);
    Route::get('/dashboard', function () {
        $totalProfiles = \App\Models\MatrimonyProfile::count();
        $activeProfiles = \App\Models\MatrimonyProfile::where('is_suspended', false)->count();
        $suspendedProfiles = \App\Models\MatrimonyProfile::where('is_suspended', true)->count();
        $showcaseProfilesCount = \App\Models\MatrimonyProfile::query()->whereShowcase()->count();
        $pendingAbuseReports = \App\Models\AbuseReport::where('status', 'open')->count();
        $totalBiodataIntakes = \App\Models\BiodataIntake::count();

        $intakeQuery = \App\Models\BiodataIntake::query();
        $last7Start = now()->subDays(7);
        $last30Start = now()->subDays(30);

        $last7Count = (clone $intakeQuery)->where('created_at', '>=', $last7Start)->count();
        $last30Count = (clone $intakeQuery)->where('created_at', '>=', $last30Start)->count();

        $last30Parsed = (clone $intakeQuery)
            ->where('created_at', '>=', $last30Start)
            ->where('parse_status', 'parsed')
            ->count();
        $last30Errors = (clone $intakeQuery)
            ->where('created_at', '>=', $last30Start)
            ->where('parse_status', 'error')
            ->count();

        $hasMetricsColumns = \Illuminate\Support\Facades\Schema::hasColumn('biodata_intakes', 'parse_duration_ms');
        $avgParseMs = 0;
        $avgManualEdits = 0.0;
        $avgAutoFilled = 0.0;
        if ($hasMetricsColumns) {
            $avgParseMs = (clone $intakeQuery)
                ->whereNotNull('parse_duration_ms')
                ->where('created_at', '>=', $last30Start)
                ->avg('parse_duration_ms') ?: 0;
            $avgManualEdits = (clone $intakeQuery)
                ->whereNotNull('fields_manually_edited_count')
                ->where('created_at', '>=', $last30Start)
                ->avg('fields_manually_edited_count') ?: 0;
            $avgAutoFilled = (clone $intakeQuery)
                ->whereNotNull('fields_auto_filled_count')
                ->where('created_at', '>=', $last30Start)
                ->avg('fields_auto_filled_count') ?: 0;
        }

        return view('admin.dashboard', [
            'totalProfiles' => $totalProfiles,
            'activeProfiles' => $activeProfiles,
            'suspendedProfiles' => $suspendedProfiles,
            'showcaseProfilesCount' => $showcaseProfilesCount,
            'pendingAbuseReports' => $pendingAbuseReports,
            'totalBiodataIntakes' => $totalBiodataIntakes,
            'intakeLast7Count' => $last7Count,
            'intakeLast30Count' => $last30Count,
            'intakeLast30Parsed' => $last30Parsed,
            'intakeLast30Errors' => $last30Errors,
            'intakeAvgParseMs' => (int) round($avgParseMs),
            'intakeAvgManualEdits' => (float) $avgManualEdits,
            'intakeAvgAutoFilled' => (float) $avgAutoFilled,
        ]);
    })->name('dashboard');

    /*
    | Help centre tickets (Phase 3 support queue)
    */
    Route::get('/help-centre/tickets', [HelpCentreTicketController::class, 'index'])->name('help-centre.tickets.index');
    Route::get('/help-centre/tickets/{ticket}', [HelpCentreTicketController::class, 'show'])->name('help-centre.tickets.show');
    Route::post('/help-centre/tickets/{ticket}/assign', [HelpCentreTicketController::class, 'assign'])->name('help-centre.tickets.assign');
    Route::post('/help-centre/tickets/{ticket}/notes', [HelpCentreTicketController::class, 'addNote'])->name('help-centre.tickets.notes');
    Route::post('/help-centre/tickets/{ticket}/resolve', [HelpCentreTicketController::class, 'resolve'])->name('help-centre.tickets.resolve');

    Route::get('/duplicate-phones', [DuplicatePhoneController::class, 'index'])->name('duplicate-phones.index');
    Route::post('/duplicate-phones/{user}/mobile', [DuplicatePhoneController::class, 'updateMobile'])->name('duplicate-phones.update-mobile');

    Route::prefix('dashboard-metrics')->name('dashboard-metrics.')->group(function () {
        Route::get('/overview', [AdminDashboardController::class, 'getOverviewStats'])->name('overview');
        Route::get('/activity', [AdminDashboardController::class, 'getUserActivityStats'])->name('activity');
        Route::get('/revenue', [AdminDashboardController::class, 'getRevenueStats'])->name('revenue');
        Route::get('/funnel', [AdminDashboardController::class, 'getFunnelStats'])->name('funnel');
        Route::get('/timeseries', [AdminDashboardController::class, 'getTimeSeriesData'])->name('timeseries');
        Route::get('/insights', [AdminDashboardController::class, 'getInsights'])->name('insights');
        Route::post('/insights/action-click', [AdminDashboardController::class, 'postInsightActionClick'])->name('insights.action-click');
        Route::post('/insights/feedback', [AdminDashboardController::class, 'postInsightFeedback'])->name('insights.feedback');
        Route::get('/risk', [AdminDashboardController::class, 'getRiskAlerts'])->name('risk');
        Route::get('/live', [AdminDashboardController::class, 'getLiveActions'])->name('live');
      	Route::get('/ai-health', [AdminDashboardController::class, 'getAiHealth'])->name('ai-health');
    });

    Route::get('/showcase', function () {
        return redirect()->route('admin.showcase-dashboard.index');
    })->name('showcase.index');

    Route::get('/showcase-dashboard', [ShowcaseEngineDashboardController::class, 'index'])->name('showcase-dashboard.index');

    /*
    | Profiles List (Admin)
    */
    Route::get('/profiles', [AdminProfileModerationController::class, 'profilesIndex'])->name('profiles.index');
    Route::get('/photo-moderation', [PhotoModerationEngineController::class, 'index'])->name('photo-moderation.index');
    Route::get('/photo-moderation/{profilePhoto}/panel', [PhotoModerationEngineController::class, 'panelFragment'])
        ->name('photo-moderation.panel');
    Route::post('/photo-moderation/bulk', [PhotoModerationEngineController::class, 'bulk'])->name('photo-moderation.bulk');
    Route::post('/photo-moderation/users/{user}/suspend-uploads', [PhotoModerationEngineController::class, 'suspendUserPhotoUploads'])
        ->name('photo-moderation.suspend-user-uploads');
    Route::get('/photo-moderation/preview/{profile}/{galleryPhoto?}', [PhotoModerationEngineController::class, 'preview'])
        ->name('photo-moderation.preview');
    Route::get('/photo-moderation/{profilePhoto}', [PhotoModerationEngineController::class, 'show'])->name('photo-moderation.show');
    Route::post('/photo-moderation/{profilePhoto}/action', [PhotoModerationEngineController::class, 'singleAction'])->name('photo-moderation.action');

    Route::get('/moderation-learning', [ModerationLearningController::class, 'index'])->name('moderation-learning.index');

    Route::post('/profiles/{profile}/tags/assign', [AdminProfileTagController::class, 'assign'])
        ->name('profiles.tags.assign');
    Route::delete('/profiles/{profile}/tags/{tag}/remove', [AdminProfileTagController::class, 'remove'])
        ->name('profiles.tags.remove');

    /*
    | Profile View (Admin - bypasses suspension checks)
    */
    Route::get('/profiles/{id}', [AdminProfileModerationController::class, 'showProfile'])
        ->name('profiles.show');

    /*
    | Profile Edit (Admin)
    */
    Route::put('/profiles/{profile}', [AdminProfileModerationController::class, 'updateProfile'])
        ->name('profiles.update');

    /*
    | Profile Moderation
    */
    Route::post('/profiles/{profile}/suspend', [AdminProfileModerationController::class, 'suspendProfile'])
        ->name('profiles.suspend');

    Route::post('/profiles/{profile}/unsuspend', [AdminProfileModerationController::class, 'unsuspendProfile'])
        ->name('profiles.unsuspend');

    Route::post('/profiles/{profile}/soft-delete', [AdminProfileModerationController::class, 'softDeleteProfile'])
        ->name('profiles.soft-delete');

    /*
    | Image Moderation
    */
    Route::post('/profiles/{profile}/approve-image', [AdminProfileModerationController::class, 'approveImage'])
        ->name('profiles.approve-image');

    Route::post('/profiles/{profile}/reject-image', [AdminProfileModerationController::class, 'rejectImage'])
        ->name('profiles.reject-image');

    Route::post('/profiles/{profile}/delete-primary-photo', [AdminProfileModerationController::class, 'deletePrimaryPhoto'])
        ->name('profiles.delete-primary-photo');

    Route::post('/profile-photos/{profilePhoto}/approve', [AdminProfileModerationController::class, 'approveGalleryPhoto'])
        ->name('profile-photos.approve');
    Route::post('/profile-photos/{profilePhoto}/reject', [AdminProfileModerationController::class, 'rejectGalleryPhoto'])
        ->name('profile-photos.reject');
    Route::post('/profile-photos/{profilePhoto}/delete', [AdminProfileModerationController::class, 'deleteGalleryPhoto'])
        ->name('profile-photos.delete');

    Route::get('/profiles/{profile}/kyc/{submission}/file', [AdminKycController::class, 'stream'])
        ->name('profiles.kyc.file');
    Route::post('/profiles/{profile}/kyc/{submission}/approve', [AdminKycController::class, 'approve'])
        ->name('profiles.kyc.approve');
    Route::post('/profiles/{profile}/kyc/{submission}/reject', [AdminKycController::class, 'reject'])
        ->name('profiles.kyc.reject');

    Route::post('/profiles/{profile}/override-visibility', [AdminProfileModerationController::class, 'overrideVisibility'])
        ->name('profiles.override-visibility');

    Route::post('/profiles/{profile}/lifecycle-state', [AdminProfileModerationController::class, 'updateLifecycleState'])
        ->name('profiles.lifecycle-state');

    /*
    | Day-13: Manual conflict detection (no profile mutation)
    */
    Route::post('/profiles/{profile}/detect-conflicts', [AdminProfileModerationController::class, 'detectConflicts'])
        ->name('profiles.detect-conflicts');

    /*
    | Day-14: OCR mode simulation (governance testing only, no OCR engine)
    */
    Route::get('/ocr-simulation', [AdminOcrSimulationController::class, 'ocrSimulation'])
        ->name('ocr-simulation.index');
    Route::post('/ocr-simulation/execute', [AdminOcrSimulationController::class, 'ocrSimulationExecute'])
        ->name('ocr-simulation.execute');

    /*
    | Day-30: OCR Patterns Governance (view, filter, toggle is_active)
    */
    Route::get('/ocr-patterns', [OcrPatternController::class, 'index'])->name('ocr-patterns.index');
    Route::post('/ocr-patterns/{pattern}/toggle-active', [OcrPatternController::class, 'toggleActive'])->name('ocr-patterns.toggle-active');

    /*
    | Day-32: Communication & Contact Request Policy (admin governance)
    */
    Route::get('/communication-policy', [\App\Http\Controllers\Admin\CommunicationPolicyController::class, 'index'])->name('communication-policy.index');
    Route::put('/communication-policy', [\App\Http\Controllers\Admin\CommunicationPolicyController::class, 'update'])->name('communication-policy.update');

    /*
    | Abuse Reports
    */
    Route::get('/abuse-reports', [AbuseReportController::class, 'index'])
        ->name('abuse-reports.index');

    Route::post('/abuse-reports/{report}/resolve', [AbuseReportController::class, 'resolve'])
        ->name('abuse-reports.resolve');

    Route::get('/location-suggestions', [LocationSuggestionWebController::class, 'index'])
        ->name('location-suggestions.index');

    Route::get('/governance-dashboard', [GovernanceDashboardController::class, 'index'])
        ->name('governance-dashboard');

    /*
    | Master Data (Religions, Castes, Sub-castes) — layout expects admin.master.*.index
    */
    Route::get('/master/religions', [AdminReligionController::class, 'index'])->name('master.religions.index');
    Route::get('/master/religions/create', [AdminReligionController::class, 'create'])->name('master.religions.create');
    Route::post('/master/religions', [AdminReligionController::class, 'store'])->name('master.religions.store');
    Route::get('/master/religions/{religion}/edit', [AdminReligionController::class, 'edit'])->name('master.religions.edit');
    Route::put('/master/religions/{religion}', [AdminReligionController::class, 'update'])->name('master.religions.update');
    Route::post('/master/religions/{religion}/disable', [AdminReligionController::class, 'disable'])->name('master.religions.disable');
    Route::post('/master/religions/{religion}/enable', [AdminReligionController::class, 'enable'])->name('master.religions.enable');

    Route::get('/master/castes', [AdminCasteController::class, 'index'])->name('master.castes.index');
    Route::get('/master/castes/create', [AdminCasteController::class, 'create'])->name('master.castes.create');
    Route::post('/master/castes', [AdminCasteController::class, 'store'])->name('master.castes.store');
    Route::get('/master/castes/{caste}/edit', [AdminCasteController::class, 'edit'])->name('master.castes.edit');
    Route::put('/master/castes/{caste}', [AdminCasteController::class, 'update'])->name('master.castes.update');
    Route::post('/master/castes/{caste}/disable', [AdminCasteController::class, 'disable'])->name('master.castes.disable');
    Route::post('/master/castes/{caste}/enable', [AdminCasteController::class, 'enable'])->name('master.castes.enable');

    Route::get('/master/sub-castes', [SubCasteAdminController::class, 'index'])->name('master.sub-castes.index');
    Route::get('/master/sub-castes/{sub_caste}/edit', [SubCasteAdminController::class, 'edit'])->name('master.sub-castes.edit');
    Route::put('/master/sub-castes/{sub_caste}', [SubCasteAdminController::class, 'update'])->name('master.sub-castes.update');
    Route::post('/master/sub-castes/{subCaste}/merge', [SubCasteAdminController::class, 'merge'])->name('master.sub-castes.merge');
    Route::post('/master/sub-castes/{subCaste}/disable', [SubCasteAdminController::class, 'disable'])->name('master.sub-castes.disable');
    Route::post('/master/sub-castes/{subCaste}/enable', [SubCasteAdminController::class, 'enable'])->name('master.sub-castes.enable');

    Route::prefix('internal')->group(function () {
        Route::get('/location-suggestions', [LocationSuggestionAdminController::class, 'index']);
        Route::post('/location-suggestions/{id}/approve', [LocationSuggestionAdminController::class, 'approve']);
        Route::post('/location-suggestions/{id}/reject', [LocationSuggestionAdminController::class, 'reject']);
        Route::post('/cities/{cityId}/aliases', [CityAliasAdminController::class, 'store']);
    });

    Route::get('/auto-showcase-settings', [AutoShowcaseSettingsController::class, 'edit'])->name('auto-showcase-settings.edit');
    Route::post('/auto-showcase-settings', [AutoShowcaseSettingsController::class, 'update'])->name('auto-showcase-settings.update');
    Route::post('/auto-showcase-settings/fill-city-population', [AutoShowcaseSettingsController::class, 'fillCityPopulation'])->name('auto-showcase-settings.fill-city-population');
    Route::post('/auto-showcase-settings/reset-ai-population-locks', [AutoShowcaseSettingsController::class, 'resetAiPopulationDistrictLocks'])->name('auto-showcase-settings.reset-ai-population-locks');

    Route::get('/showcase-profile/bulk-create', [ShowcaseProfileController::class, 'bulkCreate'])->name('showcase-profile.bulk-create');
    Route::post('/showcase-profiles/bulk', [ShowcaseProfileController::class, 'bulkStore'])->name('showcase-profile.bulk-store');
    Route::post('/showcase-profiles/{profile}/publish', [ShowcaseProfileController::class, 'publish'])->name('showcase-profile.publish');
    Route::post('/showcase-profiles/{profile}/delete', [ShowcaseProfileController::class, 'delete'])->name('showcase-profile.delete');

    /*
    | Showcase Chat Orchestration (production-safe)
    */
    Route::get('/showcase-chat-settings', [ShowcaseChatSettingsController::class, 'index'])->name('showcase-chat-settings.index');
    Route::get('/showcase-chat-settings/{profile}', [ShowcaseChatSettingsController::class, 'show'])->name('showcase-chat-settings.show');
    Route::put('/showcase-chat-settings/{profile}', [ShowcaseChatSettingsController::class, 'update'])->name('showcase-chat-settings.update');

    Route::get('/showcase-conversations', [ShowcaseConversationController::class, 'index'])->name('showcase-conversations.index');
    Route::get('/showcase-chat/debug/{conversation}', [ShowcaseChatDebugController::class, 'show'])->name('showcase-chat.debug');
    Route::get('/showcase-conversations/{conversation}', [ShowcaseConversationController::class, 'show'])->name('showcase-conversations.show');
    Route::post('/showcase-conversations/{conversation}/pause', [ShowcaseConversationController::class, 'pause'])->name('showcase-conversations.pause');
    Route::post('/showcase-conversations/{conversation}/resume', [ShowcaseConversationController::class, 'resume'])->name('showcase-conversations.resume');
    Route::post('/showcase-conversations/{conversation}/reply', [ShowcaseConversationController::class, 'replyAsShowcase'])->name('showcase-conversations.reply');

    /*
    | Verification Tags
    */
    Route::get('/verification-tags', [AdminVerificationTagController::class, 'index'])->name('verification-tags.index');
    Route::post('/verification-tags', [AdminVerificationTagController::class, 'store'])->name('verification-tags.store');
    Route::put('/verification-tags/{id}', [AdminVerificationTagController::class, 'update'])->name('verification-tags.update');
    Route::delete('/verification-tags/{id}', [AdminVerificationTagController::class, 'destroy'])->name('verification-tags.destroy');
    Route::get('/verification-tags/{id}/restore-confirm', [AdminVerificationTagController::class, 'restoreConfirm'])->name('verification-tags.restore-confirm');
    Route::post('/verification-tags/{id}/restore', [AdminVerificationTagController::class, 'restore'])->name('verification-tags.restore');

    /*
    | Serious Intents
    */
    Route::get('/serious-intents', [AdminSeriousIntentController::class, 'index'])->name('serious-intents.index');
    Route::post('/serious-intents', [AdminSeriousIntentController::class, 'store'])->name('serious-intents.store');
    Route::put('/serious-intents/{id}', [AdminSeriousIntentController::class, 'update'])->name('serious-intents.update');
    Route::delete('/serious-intents/{id}', [AdminSeriousIntentController::class, 'destroy'])->name('serious-intents.destroy');
    Route::get('/serious-intents/{id}/restore-confirm', [AdminSeriousIntentController::class, 'restoreConfirm'])->name('serious-intents.restore-confirm');
    Route::post('/serious-intents/{id}/restore', [AdminSeriousIntentController::class, 'restore'])->name('serious-intents.restore');

    /*
    | Admin Capabilities
    */
    Route::get('/admin-capabilities', [AdminCapabilityController::class, 'index'])->name('admin-capabilities.index');
    Route::post('/admin-capabilities/{admin}/update', [AdminCapabilityController::class, 'update'])->name('admin-capabilities.update');

    /*
    | Translations (EN / MR) — admin edits display values only; key is read-only. Add alias = new key.
    */
    Route::get('/translations', [TranslationController::class, 'index'])->name('translations.index');
    Route::get('/translations/edit', [TranslationController::class, 'edit'])->name('translations.edit');
    Route::put('/translations', [TranslationController::class, 'update'])->name('translations.update');
    Route::get('/translations/create', [TranslationController::class, 'create'])->name('translations.create');
    Route::post('/translations', [TranslationController::class, 'store'])->name('translations.store');

    Route::get('/app-settings', [AdminSettingsController::class, 'appSettings'])->name('app-settings.index');
    Route::post('/app-settings', [AdminSettingsController::class, 'updateAppSettings'])->name('app-settings.update');

    Route::get('/view-back-settings', [AdminSettingsController::class, 'viewBackSettings'])->name('view-back-settings.index');
    Route::post('/view-back-settings', [AdminSettingsController::class, 'updateViewBackSettings'])->name('view-back-settings.update');
    Route::post('/view-back-settings/random-views', [AdminSettingsController::class, 'updateShowcaseRandomViewSettings'])->name('view-back-settings.random-views-update');
    Route::get('/showcase-interest-settings', [AdminSettingsController::class, 'showcaseInterestSettings'])->name('showcase-interest-settings.index');
    Route::post('/showcase-interest-settings', [AdminSettingsController::class, 'updateShowcaseInterestSettings'])->name('showcase-interest-settings.update');

    Route::get('/showcase-search-settings', [AdminSettingsController::class, 'showcaseSearchSettings'])->name('showcase-search-settings.index');
    Route::post('/showcase-search-settings', [AdminSettingsController::class, 'updateShowcaseSearchSettings'])->name('showcase-search-settings.update');

    Route::get('/photo-approval-settings', [AdminSettingsController::class, 'photoApprovalSettings'])->name('photo-approval-settings.index');
    Route::post('/photo-approval-settings', [AdminSettingsController::class, 'updatePhotoApprovalSettings'])->name('photo-approval-settings.update');

    Route::get('/moderation-engine-settings', [AdminSettingsController::class, 'moderationEngineSettings'])->name('moderation-engine-settings.index');
    Route::post('/moderation-engine-settings', [AdminSettingsController::class, 'updateModerationEngineSettings'])->name('moderation-engine-settings.update');

    Route::get('/intake-settings', [AdminSettingsController::class, 'intakeSettings'])->name('intake-settings.index');
    Route::post('/intake-settings', [AdminSettingsController::class, 'updateIntakeSettings'])->name('intake-settings.update');

    Route::get('/mobile-verification-settings', [AdminSettingsController::class, 'mobileVerificationSettings'])->name('mobile-verification-settings.index');
    Route::post('/mobile-verification-settings', [AdminSettingsController::class, 'updateMobileVerificationSettings'])->name('mobile-verification-settings.update');

    Route::get('/plans', [PlanController::class, 'index'])->name('plans.index');
    Route::get('/plans/create', [PlanController::class, 'create'])->name('plans.create');
    Route::post('/plans', [PlanController::class, 'store'])->name('plans.store');
    Route::get('/plans/{plan}/edit', [PlanController::class, 'edit'])->name('plans.edit');
    Route::put('/plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
    Route::patch('/plans/{plan}/toggle', [PlanController::class, 'toggle'])->name('plans.toggle');
    Route::delete('/plans/{plan}', [PlanController::class, 'destroy'])->name('plans.destroy');

    Route::patch('coupons/{coupon}/toggle-active', [CouponController::class, 'toggleActive'])->name('coupons.toggle-active');
    Route::resource('coupons', CouponController::class)->except(['show']);

    Route::get('wallets', [UserWalletController::class, 'index'])->name('wallets.index');
    Route::post('wallets/credit', [UserWalletController::class, 'credit'])->name('wallets.credit');

    Route::get('boosts', [ProfileBoostController::class, 'index'])->name('boosts.index');
    Route::post('boosts/start', [ProfileBoostController::class, 'start'])->name('boosts.start');

    Route::get('referrals', [ReferralController::class, 'index'])->name('referrals.index');

    Route::prefix('commerce')->name('commerce.')->group(function () {
        Route::get('analytics', CommerceAnalyticsController::class)->name('analytics.index');
        Route::resource('coupons', AdminCouponController::class)->except(['show']);
        Route::get('overrides', [CommerceMemberOverrideController::class, 'index'])->name('overrides.index');
        Route::post('overrides/lookup', [CommerceMemberOverrideController::class, 'lookup'])->name('overrides.lookup');
        Route::get('overrides/members/{user}', [CommerceMemberOverrideController::class, 'show'])->name('overrides.show');
        Route::post('overrides/members/{user}/extend', [CommerceMemberOverrideController::class, 'extendSubscription'])->name('overrides.extend');
        Route::post('overrides/members/{user}/grant', [CommerceMemberOverrideController::class, 'grantEntitlement'])->name('overrides.grant');
        Route::post('overrides/members/{user}/revoke', [CommerceMemberOverrideController::class, 'revokeEntitlement'])->name('overrides.revoke');
    });

    Route::get('/match-boost', [MatchBoostController::class, 'edit'])->name('match-boost.edit');
    Route::put('/match-boost', [MatchBoostController::class, 'update'])->name('match-boost.update');

    Route::prefix('matching-engine')->name('matching-engine.')->group(function () {
        Route::get('/', [MatchingEngineController::class, 'overview'])->name('overview');
        Route::post('/runtime', [MatchingEngineController::class, 'runtime'])->name('runtime');
        Route::get('/fields', [MatchingEngineController::class, 'fields'])->name('fields');
        Route::post('/fields', [MatchingEngineController::class, 'saveFields'])->name('fields.save');
        Route::get('/filters', [MatchingEngineController::class, 'filters'])->name('filters');
        Route::post('/filters', [MatchingEngineController::class, 'saveFilters'])->name('filters.save');
        Route::get('/behavior', [MatchingEngineController::class, 'behavior'])->name('behavior');
        Route::post('/behavior', [MatchingEngineController::class, 'saveBehavior'])->name('behavior.save');
        Route::get('/boosts', [MatchingEngineController::class, 'boosts'])->name('boosts');
        Route::post('/boosts', [MatchingEngineController::class, 'saveBoosts'])->name('boosts.save');
        Route::get('/ai', [MatchingEngineController::class, 'ai'])->name('ai');
        Route::get('/preview', [MatchingEngineController::class, 'preview'])->name('preview');
        Route::get('/audit', [MatchingEngineController::class, 'audit'])->name('audit');
        Route::post('/audit/{matching_config_version}/rollback', [MatchingEngineController::class, 'rollback'])->name('audit.rollback');
    });

    Route::get('/profile-field-config', [AdminSettingsController::class, 'profileFieldConfigIndex'])->name('profile-field-config.index');
    Route::post('/profile-field-config', [AdminSettingsController::class, 'profileFieldConfigUpdate'])->name('profile-field-config.update');

    Route::get('/field-registry', [AdminFieldRegistryController::class, 'fieldRegistryIndex'])->name('field-registry.index');

    Route::get('/field-registry/extended', [AdminFieldRegistryController::class, 'extendedFieldsIndex'])->name('field-registry.extended.index');
    Route::get('/field-registry/extended/create', [AdminFieldRegistryController::class, 'extendedFieldsCreate'])->name('field-registry.extended.create');
    Route::post('/field-registry/extended', [AdminFieldRegistryController::class, 'extendedFieldsStore'])->name('field-registry.extended.store');
    Route::post('/field-registry/extended/update-bulk', [AdminFieldRegistryController::class, 'extendedFieldsUpdateBulk'])->name('field-registry.extended.update-bulk');
    Route::post('/field-registry/{field}/archive', [AdminFieldRegistryController::class, 'archiveFieldRegistry'])->name('field-registry.archive');
    Route::post('/field-registry/{field}/unarchive', [AdminFieldRegistryController::class, 'unarchiveFieldRegistry'])->name('field-registry.unarchive');

    Route::get('/notifications', [AdminUserNotificationsController::class, 'userNotificationsIndex'])->name('notifications.index');
    Route::get('/notifications/user', [AdminUserNotificationsController::class, 'userNotificationsShow'])->name('notifications.user.show');

    Route::get('/homepage-images', [HomepageImageController::class, 'index'])->name('homepage-images.index');
    Route::post('/homepage-images', [HomepageImageController::class, 'store'])->name('homepage-images.store');
    Route::post('/homepage-images/clear', [HomepageImageController::class, 'clear'])->name('homepage-images.clear');

    /*
    | Pending intake suggestions queue (profile-centric; no intake attachment required)
    */
    Route::prefix('intake')->name('intake.')->group(function () {
        Route::get('/', [IntakeReviewController::class, 'index'])->name('index');
        Route::get('/{profile}', [IntakeReviewController::class, 'show'])->name('show');
        Route::post('/{profile}/approve', [IntakeReviewController::class, 'approve'])->name('approve');
        Route::post('/{profile}/reject', [IntakeReviewController::class, 'reject'])->name('reject');
        Route::post('/{profile}/approve-all', [IntakeReviewController::class, 'approveAll'])->name('approve-all');
        Route::post('/{profile}/clear', [IntakeReviewController::class, 'clearAll'])->name('clear');
    });

    /*
    | Phase-4 Day-4: Biodata Intake Sandbox & Attach (admin only)
    */
    Route::get('/biodata-intakes', [AdminIntakeController::class, 'biodataIntakesIndex'])->name('biodata-intakes.index');
    Route::get('/biodata-intakes/{intake}', [AdminIntakeController::class, 'showBiodataIntake'])->name('biodata-intakes.show');
    Route::patch('/biodata-intakes/{intake}/attach', [AdminIntakeController::class, 'attachBiodataIntake'])->name('biodata-intakes.attach');
    Route::post('/biodata-intakes/{intake}/reparse', [AdminIntakeController::class, 'reparse'])->name('biodata-intakes.reparse');
    Route::post('/biodata-intakes/{intake}/re-extract', [AdminIntakeController::class, 'reExtract'])->name('biodata-intakes.re-extract');
    Route::post('/biodata-intakes/{intake}/apply', [AdminIntakeController::class, 'applyToProfile'])->name('biodata-intakes.apply');
    Route::get('/biodata-intakes/{intake}/suggestions-review', [AdminSuggestionReviewController::class, 'show'])->name('suggestions.review');
    Route::post('/biodata-intakes/{intake}/suggestions-review/apply', [AdminSuggestionReviewController::class, 'apply'])->name('suggestions.review.apply');

    /*
    | Conflict Records (Phase-3 Day-4/5 — list, create, resolve)
    */
    Route::get('/conflict-records', [AdminConflictRecordController::class, 'conflictRecordsIndex'])->name('conflict-records.index');
    Route::get('/conflict-records/create', [AdminConflictRecordController::class, 'conflictRecordsCreate'])->name('conflict-records.create');
    Route::get('/conflict-records/{record}', [AdminConflictRecordController::class, 'conflictRecordShow'])->name('conflict-records.show');
    Route::post('/conflict-records', [AdminConflictRecordController::class, 'conflictRecordsStore'])->name('conflict-records.store');
    Route::post('/conflict-records/{record}/approve', [AdminConflictRecordController::class, 'conflictRecordApprove'])->name('conflict-records.approve');
    Route::post('/conflict-records/{record}/reject', [AdminConflictRecordController::class, 'conflictRecordReject'])->name('conflict-records.reject');
    Route::post('/conflict-records/{record}/override', [AdminConflictRecordController::class, 'conflictRecordOverride'])->name('conflict-records.override');
});

/*
|--------------------------------------------------------------------------
| DEV ONLY — Plan feature API (testing): no auth / admin middleware
| Restore: move PUT /admin/plans/{plan}/features back inside the group above.
|--------------------------------------------------------------------------
*/
Route::put('/admin/plans/{plan}/features', [PlanFeatureController::class, 'update'])
    ->name('admin.plans.features.update');
