<?php

namespace App\Http\Controllers;

use App\Models\Caste;
use App\Models\City;
use App\Models\Country;
use App\Models\District;
use App\Models\FieldRegistry;
use App\Models\Interest;
use App\Models\MasterMaritalStatus;
use App\Models\MatrimonyProfile;
use App\Models\Profession;
use App\Models\ProfilePhoto;
use App\Models\Religion;
use App\Models\SeriousIntent;
use App\Models\Shortlist;
use App\Models\State;
use App\Models\SubCaste;
use App\Models\Taluka;
use App\Models\User;
use App\Services\Admin\AdminSettingService;
use App\Services\ContactAccessService;
use App\Services\EntitlementService;
use App\Services\ExtendedFieldService;
use App\Services\FeatureUsageService;
use App\Services\Image\ImageModerationService;
use App\Services\Image\MatrimonyPhotoStoragePathService;
use App\Services\Image\PhotoModerationScanPayload;
use App\Services\Image\PhotoUploadBatchUserMessage;
use App\Services\Image\ProfileGalleryPhotoModerationStatus;
use App\Services\Image\ProfilePhotoUrlService;
use App\Services\InterestSendLimitService;
use App\Services\MatrimonyProfileSearchQueryService;
use App\Services\ProfileCompletenessService;
use App\Services\ProfileFieldConfigurationService;
use App\Services\ProfilePhotoAccessService;
use App\Services\ProfileRotationService;
use App\Services\ProfileSearchRankingService;
use App\Services\ProfileShowReadService;
use App\Services\ProfileShowSnapshotService;
use App\Services\Showcase\AutoShowcaseEngine;
use App\Services\Showcase\AutoShowcaseSettings;
use App\Services\ViewTrackingService;
use App\Support\PlanFeatureKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| MatrimonyProfileController
|--------------------------------------------------------------------------
|
| 👉 हा controller MATRIMONY BIODATA साठी आहे
| 👉 User login / auth logic इथे येणार नाही
|
| लक्षात ठेव:
| User = authentication only
| MatrimonyProfile = full biodata
|
*/

class MatrimonyProfileController extends Controller
{
    public function __construct(
        protected ContactAccessService $contactAccessService,
        protected ProfilePhotoAccessService $profilePhotoAccessService,
        protected InterestSendLimitService $interestSendLimitService,
    ) {}

    private function resolvePhotoTargetProfile(Request $request): ?MatrimonyProfile
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        if (method_exists($user, 'isAnyAdmin') && $user->isAnyAdmin()) {
            $targetId = (int) ($request->input('profile_id') ?? $request->query('profile_id') ?? 0);
            if ($targetId <= 0) {
                $targetId = (int) (session('admin_edit_profile_id') ?? 0);
            }
            if ($targetId > 0) {
                $target = MatrimonyProfile::withTrashed()->find($targetId);
                if ($target && ($target->is_demo ?? false)) {
                    session(['admin_edit_profile_id' => (int) $target->id]);

                    return $target;
                }
            }
        }

        return $user->matrimonyProfile;
    }

    /**
     * Query params to preserve admin showcase profile photo editing across redirects (GET + POST body may omit query string).
     *
     * @return array<string, string>
     */
    private function adminShowcaseProfileQuery(MatrimonyProfile $profile): array
    {
        $user = auth()->user();
        if (! $user || ! method_exists($user, 'isAnyAdmin') || ! $user->isAnyAdmin()) {
            return [];
        }
        if (! ($profile->is_demo ?? false)) {
            return [];
        }

        return ['profile_id' => (string) $profile->id];
    }

    /**
     * @return array<string, string>
     */
    private function uploadPhotoRedirectQuery(Request $request, MatrimonyProfile $profile): array
    {
        $q = [];
        if ($request->input('from') === 'onboarding' || $request->query('from') === 'onboarding') {
            $q['from'] = 'onboarding';
        }

        return array_merge($q, $this->adminShowcaseProfileQuery($profile));
    }

    /**
     * Photo upload from XHR/fetch: avoid following a 302 inside fetch (that consumes session flash before the visible navigation).
     *
     * @param  array<string, string|null>  $flash
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    private function photoUploadRedirectResponse(Request $request, string $url, array $flash = [])
    {
        if ($request->ajax() || $request->expectsJson()) {
            foreach ($flash as $key => $value) {
                if ($value !== null && $value !== '') {
                    session()->flash($key, $value);
                }
            }

            $hasError = isset($flash['error']) && $flash['error'] !== null && $flash['error'] !== '';

            return response()->json([
                'success' => ! $hasError,
                'redirect' => $url,
            ]);
        }

        $redirect = redirect()->to($url);
        foreach ($flash as $key => $value) {
            if ($value !== null && $value !== '') {
                $redirect = $redirect->with($key, $value);
            }
        }

        return $redirect;
    }

    /**
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    private function photoUploadBackWithError(Request $request, string $message, ?MatrimonyProfile $profile = null)
    {
        if ($request->ajax() || $request->expectsJson()) {
            session()->flash('error', $message);
            $target = url()->previous();
            if ($target === '' || $target === $request->fullUrl()) {
                $target = $profile
                    ? route('matrimony.profile.upload-photo', $this->uploadPhotoRedirectQuery($request, $profile))
                    : route('matrimony.profile.wizard.section', ['section' => 'basic-info']);
            }

            return response()->json([
                'success' => false,
                'redirect' => $target,
            ]);
        }

        return redirect()->back()->with('error', $message);
    }

    /**
     * Phase-5B: Build snapshot (same schema as approval_snapshot_json) from request + profile.
     * Only includes keys present in request (or in overrides). No DB write.
     *
     * @param  array<string, mixed>  $overrides  e.g. ['profile_photo' => $path, 'photo_approved' => true]
     * @return array{core: array, contacts: array, children: array, education_history: array, career_history: array, addresses: array, property_summary: array, property_assets: array, horoscope: array, preferences: array, extended_narrative: array}
     */
    private function buildManualSnapshot(Request $request, MatrimonyProfile $profile, array $overrides = []): array
    {
        $enabledFields = ProfileFieldConfigurationService::getEnabledFieldKeys();
        $enabledMap = array_flip($enabledFields);

        $core = [];
        $coreKeys = [
            'full_name', 'date_of_birth', 'gender_id', 'marital_status_id', 'highest_education',
            'country_id', 'state_id', 'district_id', 'taluka_id', 'city_id',
            'religion_id', 'caste_id', 'sub_caste_id', 'height_cm', 'profile_photo', 'serious_intent_id',
            'photo_approved', 'photo_rejected_at', 'photo_rejection_reason', 'is_suspended',
        ];
        foreach ($coreKeys as $key) {
            if (array_key_exists($key, $overrides)) {
                $core[$key] = $overrides[$key];

                continue;
            }
            if ($key === 'gender_id' && ! $request->has('gender_id')) {
                $core[$key] = $profile->getAttribute('gender_id');

                continue;
            }
            $enabled = $key === 'location' ? isset($enabledMap['location']) : isset($enabledMap[$key]);
            if (! $enabled && ! in_array($key, ['gender_id', 'profile_photo', 'photo_approved', 'photo_rejected_at', 'photo_rejection_reason', 'is_suspended'], true)) {
                continue;
            }
            if ($request->has($key) || array_key_exists($key, $overrides)) {
                $val = $request->input($key, $overrides[$key] ?? null);
                if ($val instanceof \Carbon\Carbon) {
                    $val = $val->format('Y-m-d');
                }
                $core[$key] = $val === '' ? null : $val;
            }
        }
        if ($request->has('country_id') || $request->has('state_id') || $request->has('city_id')) {
            if (isset($enabledMap['location'])) {
                $core['country_id'] = $core['country_id'] ?? $request->input('country_id');
                $core['state_id'] = $core['state_id'] ?? $request->input('state_id');
                $core['district_id'] = $core['district_id'] ?? $request->input('district_id');
                $core['taluka_id'] = $core['taluka_id'] ?? $request->input('taluka_id');
                $core['city_id'] = $core['city_id'] ?? $request->input('city_id');
            }
        }

        $contacts = [];
        if ($request->has('primary_contact_phone') || $request->has('primary_contact_number')) {
            $phone = trim((string) ($request->input('primary_contact_phone') ?? $request->input('primary_contact_number') ?? ''));
            if ($phone !== '') {
                $contacts[] = [
                    'relation_type' => 'self',
                    'contact_name' => 'Primary',
                    'phone_number' => $phone,
                    'is_primary' => true,
                ];
            }
        }

        $children = [];
        if ($request->has('children') && is_array($request->input('children'))) {
            $currentYear = (int) date('Y');
            foreach (array_values($request->input('children')) as $row) {
                $id = ! empty($row['id']) ? (int) $row['id'] : null;
                $birthYear = ! empty($row['child_birth_year']) ? (int) $row['child_birth_year'] : null;
                $age = $birthYear > 0 ? $currentYear - $birthYear : 0;
                $custody = $row['custody_status'] ?? '';
                $children[] = [
                    'id' => $id,
                    'child_name' => trim((string) ($row['child_name'] ?? '')),
                    'gender' => trim((string) ($row['child_gender'] ?? '')),
                    'age' => $age,
                    'lives_with_parent' => $custody === 'with_me',
                ];
            }
        }

        $education_history = [];
        if ($request->has('education_history') && is_array($request->input('education_history'))) {
            foreach (array_values($request->input('education_history')) as $row) {
                $education_history[] = [
                    'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                    'degree' => trim((string) ($row['degree'] ?? '')),
                    'specialization' => trim((string) ($row['field_of_study'] ?? '')),
                    'university' => trim((string) ($row['institution'] ?? '')),
                    'year_completed' => ! empty($row['year_completed']) ? (int) $row['year_completed'] : 0,
                ];
            }
            $latest = collect($education_history)->filter(fn ($r) => ($r['year_completed'] ?? 0) > 0 && ($r['degree'] ?? '') !== '')->sortByDesc('year_completed')->first();
            if ($latest !== null) {
                $core['highest_education'] = $latest['degree'];
            }
        }

        $career_history = [];
        if ($request->has('career_history') && is_array($request->input('career_history'))) {
            foreach (array_values($request->input('career_history')) as $row) {
                $career_history[] = [
                    'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                    'designation' => trim((string) ($row['job_title'] ?? $row['designation'] ?? '')),
                    'company' => trim((string) ($row['company_name'] ?? $row['company'] ?? '')),
                    'location' => trim((string) ($row['location'] ?? '')) ?: null,
                    'city_id' => ! empty($row['city_id']) && is_numeric($row['city_id']) ? (int) $row['city_id'] : null,
                    'start_year' => ! empty($row['start_year']) ? (int) $row['start_year'] : null,
                    'end_year' => ! empty($row['end_year']) ? (int) $row['end_year'] : null,
                    'is_current' => isset($row['is_current']) && (string) $row['is_current'] === '1',
                ];
            }
        }

        $snapshot = ['core' => $core];
        if ($contacts !== []) {
            $snapshot['contacts'] = $contacts;
        }
        if ($request->has('children') && is_array($request->input('children'))) {
            $snapshot['children'] = $children;
        }
        if ($request->has('education_history') && is_array($request->input('education_history'))) {
            $snapshot['education_history'] = $education_history;
        }
        if ($request->has('career_history') && is_array($request->input('career_history'))) {
            $snapshot['career_history'] = $career_history;
        }
        // Phase-5B PART-2: Extended fields passed into snapshot; applied inside MutationService transaction.
        if ($request->has('extended_fields') && is_array($request->input('extended_fields'))) {
            $snapshot['extended_fields'] = $request->input('extended_fields');
        }

        return $snapshot;
    }

    /*
    |--------------------------------------------------------------------------
    | Edit Matrimony Profile
    |--------------------------------------------------------------------------
    |
    | 👉 Existing profile असल्यास edit form दाखवतो
    |
    */
    public function edit()
    {
        $user = auth()->user();

        // 🔒 GUARD: Profile नसेल तर edit allowed नाही
        if (! $user->matrimonyProfile) {
            return redirect()
                ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
                ->with('error', __('interest.create_profile_first'));
        }

        // Phase-5B: Single edit path = wizard; default entry is Basic info (not monolithic full).
        return redirect()->route('matrimony.profile.wizard.section', ['section' => 'basic-info']);
    }

    /**
     * Phase-5 Point 6: edit-full shows same form as wizard section=full. Redirect to wizard.
     */
    public function editFull()
    {
        $user = auth()->user();
        if (! $user->matrimonyProfile) {
            return redirect()
                ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
                ->with('error', __('profile_actions.create_profile_first'));
        }

        return redirect()->route('matrimony.profile.wizard.section', ['section' => 'basic-info']);
    }

    /**
     * Phase-5 Point 6: update-full persists via MutationService only (no direct profile->update).
     * Builds full snapshot via ManualSnapshotBuilderService, then applyManualSnapshot.
     */
    public function updateFull(Request $request)
    {
        $user = auth()->user();
        if (! $user->matrimonyProfile) {
            return redirect()
                ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
                ->with('error', __('profile_actions.create_profile_first'));
        }
        $profile = $user->matrimonyProfile;
        if (! \App\Services\ProfileLifecycleService::isEditableForManual($profile)) {
            return redirect()->route('matrimony.profile.show', $profile->id)->with('error', __('wizard.profile_not_editable_current_state'));
        }
        $snapshot = app(\App\Services\ManualSnapshotBuilderService::class)->buildFullManualSnapshot($request, $profile);
        if (empty($snapshot['core'] ?? null) && empty($snapshot['contacts'] ?? null)) {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'full', 'all' => 1])
                ->with('error', __('common.no_valid_data_to_save'))
                ->withInput();
        }
        try {
            $result = app(\App\Services\MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');
            if ($request->attributes->get('matrimony_apply_pending_photo_review')) {
                app(\App\Services\Image\ProfilePhotoPendingStateService::class)->applyPendingReviewState($profile);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'full', 'all' => 1])
                ->withErrors($e->errors())
                ->withInput();
        } catch (\RuntimeException $e) {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'full', 'all' => 1])
                ->with('error', $e->getMessage())
                ->withInput();
        }
        if ($result['conflict_detected'] ?? false) {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'full', 'all' => 1])
                ->with('warning', __('common.some_changes_conflict'))
                ->withInput();
        }

        return redirect()->route('matrimony.profiles.index')->with('success', __('common.profile_updated'));
    }

    /**
     * Phase-5B: Legacy update route removed. Use wizard only.
     */
    public function update(Request $request)
    {
        abort(404);
    }

    public function uploadPhoto(Request $request)
    {
        $profile = $this->resolvePhotoTargetProfile($request);
        if (! $profile) {
            return redirect()
                ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
                ->with('error', __('profile_actions.create_profile_first'));
        }

        $galleryPhotosQuery = ProfilePhoto::query()
            ->where('profile_id', $profile->id);

        if (\Illuminate\Support\Facades\Schema::hasColumn('profile_photos', 'sort_order')) {
            $galleryPhotosQuery->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id');
        } else {
            $galleryPhotosQuery->orderByDesc('is_primary')->orderByDesc('created_at')->orderBy('id');
        }

        $galleryPhotos = $galleryPhotosQuery->get();

        $profile->load('gender');

        $photoApprovalRequired = AdminSettingService::isPhotoApprovalRequired();
        $photoMaxPerProfile = (int) \App\Models\AdminSetting::getValue('photo_max_per_profile', '5');

        $galleryCount = $galleryPhotos->count();
        $profilePhotoCol = trim((string) ($profile->profile_photo ?? ''));
        // Primary photo often lives only on matrimony_profiles (pending/… or final filename) until ProcessProfilePhoto syncs profile_photos.
        $primaryOnlyOnCoreColumn = $galleryCount === 0 && $profilePhotoCol !== '';
        $primaryPhotoProcessing = $primaryOnlyOnCoreColumn && str_starts_with($profilePhotoCol, 'pending/');

        $currentPhotoCount = $galleryCount;
        if ($primaryOnlyOnCoreColumn) {
            $currentPhotoCount = 1;
        }
        $photoSlotsRemaining = max(0, $photoMaxPerProfile - $currentPhotoCount);
        $photoLimitReached = $currentPhotoCount >= $photoMaxPerProfile;

        $fromOnboarding = $request->query('from') === 'onboarding';

        return view('matrimony.profile.upload-photo', [
            'profile' => $profile,
            'galleryPhotos' => $galleryPhotos,
            'photoApprovalRequired' => $photoApprovalRequired,
            'photoMaxPerProfile' => $photoMaxPerProfile,
            'currentPhotoCount' => $currentPhotoCount,
            'photoSlotsRemaining' => $photoSlotsRemaining,
            'photoLimitReached' => $photoLimitReached,
            'fromOnboarding' => $fromOnboarding,
            'primaryPhotoProcessing' => $primaryPhotoProcessing,
            'primaryOnlyOnCoreColumn' => $primaryOnlyOnCoreColumn,
        ]);
    }

    public function storePhoto(Request $request)
    {
        Log::info('UPLOAD ENTRY HIT', [
            'controller' => __METHOD__,
            'user_id' => auth()->id() ?? null,
        ]);

        $maxUploadMb = (int) \App\Models\AdminSetting::getValue('photo_max_upload_mb', '8');
        $maxUploadKb = max(1, $maxUploadMb) * 1024;

        $request->validate([
            'profile_photo' => 'required|image|max:'.$maxUploadKb,
            'profile_photos' => 'sometimes|array',
            'profile_photos.*' => 'image|max:'.$maxUploadKb,
        ]);

        $user = auth()->user();
        $profile = $this->resolvePhotoTargetProfile($request);
        if (! $user || ! $profile) {
            return $this->photoUploadRedirectResponse(
                $request,
                route('matrimony.profile.wizard.section', ['section' => 'basic-info']),
                ['error' => __('profile_actions.create_profile_first')]
            );
        }
        if (! ($profile->is_demo ?? false) && (int) $profile->user_id !== (int) $user->id) {
            abort(403, __('common.unauthorized_photo_update'));
        }

        if (Schema::hasColumn('users', 'photo_uploads_suspended') && (bool) $user->photo_uploads_suspended) {
            return $this->photoUploadBackWithError($request, 'Photo uploads have been suspended for your account.', $profile);
        }

        $inOnboardingPhotoPhase = (int) ($profile->card_onboarding_resume_step ?? 0) === MatrimonyProfile::CARD_ONBOARDING_PHOTO_RESUME_STEP
            || $request->input('from') === 'onboarding'
            || $request->query('from') === 'onboarding';

        // Phase-5 PART-5: Block manual edit when lifecycle blocks it
        if (in_array($profile->lifecycle_state, [
            'intake_uploaded', 'awaiting_user_approval', 'approved_pending_mutation', 'conflict_pending',
        ], true)) {
            return $this->photoUploadBackWithError($request, __('common.profile_edit_blocked_intake_conflict'), $profile);
        }

        $maxPerProfile = (int) \App\Models\AdminSetting::getValue('photo_max_per_profile', '5');
        $maxEdgePx = (int) \App\Models\AdminSetting::getValue('photo_max_edge_px', '1200');
        $maxEdgePx = max(400, $maxEdgePx);

        $primaryFile = $request->file('profile_photo');
        $additionalFiles = $request->file('profile_photos', []);
        if (! is_array($additionalFiles)) {
            $additionalFiles = [];
        }
        $additionalFiles = array_values(array_filter($additionalFiles));

        $existingPhotosCount = ProfilePhoto::query()
            ->where('profile_id', $profile->id)
            ->count();

        $incomingCount = 1 + count($additionalFiles);
        if (($existingPhotosCount + $incomingCount) > $maxPerProfile) {
            $limitMessage = "You have already used all {$maxPerProfile} photo slots. Delete one photo before uploading a new one.";

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'message' => $limitMessage,
                    'errors' => [
                        'profile_photos' => [$limitMessage],
                    ],
                ], 422);
            }

            return redirect()->back()
                ->withErrors([
                    'profile_photos' => $limitMessage,
                ])
                ->withInput();
        }

        // If user has no existing photos, the first uploaded photo becomes primary.
        // Otherwise, new uploads are added as non-primary by default.
        $mainBecomesPrimary = $existingPhotosCount === 0;

        $targetDir = storage_path('app/public/matrimony_photos');
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $storeUploadedPhoto = function ($file, int $idx) use ($maxEdgePx, $profile): string {
            $originalName = basename((string) ($file->getClientOriginalName() ?: 'photo'));
            $slug = pathinfo($originalName, PATHINFO_FILENAME);
            $rand = bin2hex(random_bytes(3));
            $baseName = time().'_'.$idx.'_'.$rand.'_'.$slug;
            $pid = (int) $profile->id;

            // Prefer WebP + resize when GD/WebP extensions are available; otherwise fall back to original upload.
            if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
                $realPath = $file->getRealPath() ?: $file->getPathname();
                $imageData = is_string($realPath) ? @file_get_contents($realPath) : false;
                $image = $imageData !== false ? @imagecreatefromstring($imageData) : false;
                if ($image === false) {
                    throw new \RuntimeException(__('common.invalid_photo_upload_jpg_png'));
                }

                $width = imagesx($image);
                $height = imagesy($image);
                $maxEdge = $maxEdgePx;
                if ($width > $maxEdge || $height > $maxEdge) {
                    $scale = min($maxEdge / $width, $maxEdge / $height);
                    $newWidth = (int) floor($width * $scale);
                    $newHeight = (int) floor($height * $scale);
                    $resized = imagecreatetruecolor($newWidth, $newHeight);
                    imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                    imagedestroy($image);
                    $image = $resized;
                }

                $leaf = $baseName.'.webp';
                $relative = MatrimonyPhotoStoragePathService::nestedRelativePathForNewFile($pid, $leaf);
                MatrimonyPhotoStoragePathService::ensureDirectoryForRelativePath($relative);
                $webpPath = storage_path('app/public/matrimony_photos/'.$relative);
                imagewebp($image, $webpPath, 80);
                imagedestroy($image);

                // If file is still large, attempt lighter encode.
                if (is_file($webpPath) && filesize($webpPath) > 200 * 1024) {
                    $tmpImage = @imagecreatefromstring(file_get_contents($webpPath));
                    if ($tmpImage !== false) {
                        imagewebp($tmpImage, $webpPath, 70);
                        imagedestroy($tmpImage);
                    }
                }

                return $relative;
            }

            // Fallback: store original file without re-encoding (keeps old behaviour on systems without GD/WebP)
            $extension = $file->getClientOriginalExtension() ?: 'jpg';
            $filename = $baseName.'.'.$extension;
            $relative = MatrimonyPhotoStoragePathService::nestedRelativePathForNewFile($pid, $filename);
            MatrimonyPhotoStoragePathService::ensureDirectoryForRelativePath($relative);
            $destDir = pathinfo(storage_path('app/public/matrimony_photos/'.$relative), PATHINFO_DIRNAME);
            if (! is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            $file->move($destDir, basename($relative));

            return $relative;
        };

        try {
            $pendingPrimary = null;
            $primaryFilename = null;
            if ($mainBecomesPrimary) {
                $pendingPrimary = app(\App\Services\Image\ImageProcessingService::class)
                    ->enqueueProfilePhotoProcessing($primaryFile, (int) $profile->id);
            } else {
                $primaryFilename = $storeUploadedPhoto($primaryFile, 0);
            }
            $additionalFilenames = [];
            foreach ($additionalFiles as $i => $addFile) {
                $additionalFilenames[] = $storeUploadedPhoto($addFile, (int) $i + 1);
            }
        } catch (\RuntimeException $e) {
            return $this->photoUploadBackWithError($request, $e->getMessage(), $profile);
        }

        // Each stored gallery file runs NudeNet + pipeline (same rules as primary job). Stage 1 only affects
        // automated-safe "approved" path; flagged / pending_manual never go to "approved" without Stage 2.
        $galleryRowMetaForStoredFilename = function (string $filename): array {
            $path = storage_path('app/public/matrimony_photos/'.ltrim($filename, '/'));
            if (! is_file($path)) {
                return ['approved_status' => 'pending', 'moderation_scan_json' => null];
            }
            try {
                $result = app(ImageModerationService::class)->moderateProfilePhoto($path);

                return [
                    'approved_status' => ProfileGalleryPhotoModerationStatus::fromModerationResult($result),
                    'moderation_scan_json' => PhotoModerationScanPayload::fromModerationResult($result),
                ];
            } catch (\Throwable $e) {
                Log::warning('Gallery photo moderation failed', ['path' => $path, 'message' => $e->getMessage()]);

                return ['approved_status' => 'pending', 'moderation_scan_json' => null];
            }
        };

        $batchHadPending = $mainBecomesPrimary;
        $batchHadRejected = false;
        $hasModerationScanColumn = \Illuminate\Support\Facades\Schema::hasColumn('profile_photos', 'moderation_scan_json');
        /** @var list<array{approved_status: string, moderation_scan_json: mixed}> $allBatchMetas */
        $allBatchMetas = [];

        $result = ['conflict_detected' => false];
        if ($mainBecomesPrimary) {
            $snapshot = [
                'core' => [
                    'profile_photo' => $pendingPrimary,
                ],
                'contacts' => [],
                'children' => [],
                'education_history' => [],
                'career_history' => [],
                'addresses' => [],
                'property_summary' => [],
                'property_assets' => [],
                'horoscope' => [],
                'preferences' => [],
                'extended_narrative' => [],
            ];

            try {
                $result = app(\App\Services\MutationService::class)->applyManualSnapshot(
                    $profile,
                    $snapshot,
                    (int) $user->id
                );
                app(\App\Services\Image\ProfilePhotoPendingStateService::class)->applyPendingReviewState($profile);
            } catch (\RuntimeException $e) {
                return $this->photoUploadBackWithError($request, $e->getMessage(), $profile);
            }
        }

        $sortBase = -1;
        if (\Illuminate\Support\Facades\Schema::hasColumn('profile_photos', 'sort_order')) {
            $maxSortOrder = ProfilePhoto::query()
                ->where('profile_id', $profile->id)
                ->max('sort_order');
            $sortBase = $maxSortOrder !== null ? (int) $maxSortOrder : -1;
        }

        $sortFieldsMain = [];
        $sortFieldsAdditional = [];
        $hasSort = \Illuminate\Support\Facades\Schema::hasColumn('profile_photos', 'sort_order');
        if ($hasSort) {
            $sortFieldsMain['sort_order'] = $sortBase + 1;
        }

        // Insert main uploaded photo into gallery only when it isn't handled as primary profile_photo.
        if (! $mainBecomesPrimary && $primaryFilename) {
            $meta = $galleryRowMetaForStoredFilename($primaryFilename);
            $allBatchMetas[] = $meta;
            if (($meta['approved_status'] ?? '') === 'pending') {
                $batchHadPending = true;
            }
            if (($meta['approved_status'] ?? '') === 'rejected') {
                $batchHadRejected = true;
            }
            $row = [
                'profile_id' => $profile->id,
                'file_path' => $primaryFilename,
                'is_primary' => false,
                'uploaded_via' => 'user_web',
                'approved_status' => $meta['approved_status'],
                'watermark_detected' => false,
            ] + $sortFieldsMain;
            if ($hasModerationScanColumn) {
                $row['moderation_scan_json'] = $meta['moderation_scan_json'];
            }
            ProfilePhoto::create($row);
        }

        // Insert additional photos as non-primary by default.
        if (! empty($additionalFilenames)) {
            foreach (array_values($additionalFilenames) as $i => $filename) {
                $sortFieldsAdditional = [];
                if ($hasSort) {
                    $sortFieldsAdditional['sort_order'] = $sortBase + 2 + (int) $i;
                }

                $meta = $galleryRowMetaForStoredFilename($filename);
                $allBatchMetas[] = $meta;
                if (($meta['approved_status'] ?? '') === 'pending') {
                    $batchHadPending = true;
                }
                if (($meta['approved_status'] ?? '') === 'rejected') {
                    $batchHadRejected = true;
                }
                $row = [
                    'profile_id' => $profile->id,
                    'file_path' => $filename,
                    'is_primary' => false,
                    'uploaded_via' => 'user_web',
                    'approved_status' => $meta['approved_status'],
                    'watermark_detected' => false,
                ] + $sortFieldsAdditional;
                if ($hasModerationScanColumn) {
                    $row['moderation_scan_json'] = $meta['moderation_scan_json'];
                }
                ProfilePhoto::create($row);
            }
        }

        $profile->refresh();
        $this->syncCoreProfilePhotoFromPrimaryGalleryIfPendingStale($profile);

        if (! empty($result['conflict_detected'])) {
            if ($inOnboardingPhotoPhase) {
                return $this->photoUploadRedirectResponse(
                    $request,
                    route('matrimony.profile.upload-photo', $this->uploadPhotoRedirectQuery($request, $profile)),
                    ['warning' => __('onboarding.photo_upload_conflict_retry')]
                );
            }

            return $this->photoUploadRedirectResponse(
                $request,
                route('matrimony.profile.wizard.section', array_merge(
                    ['section' => 'full', 'all' => 1],
                    $this->adminShowcaseProfileQuery($profile)
                )),
                ['warning' => 'Photo uploaded but some conflicts were detected.']
            );
        }

        $additionalCount = is_array($additionalFilenames) ? count($additionalFilenames) : 0;
        $uploadedCount = 1 + $additionalCount;

        $firstEverBatch = $existingPhotosCount === 0;
        if ($inOnboardingPhotoPhase && $firstEverBatch) {
            $this->releaseCardOnboardingLock($profile);

            return $this->photoUploadRedirectResponse(
                $request,
                route('matrimony.profile.show', $profile->id),
                ['success' => __('onboarding.photo_uploaded_view_profile')]
            );
        }

        $stage1 = AdminSettingService::isPhotoApprovalRequired();
        $uploadFlash = [];
        if ($mainBecomesPrimary) {
            $uploadFlash['member_notice'] = [
                'message' => $stage1
                    ? __('photo.upload_first_pending_admin')
                    : __('photo.upload_first_pending_scan'),
                'tone' => 'danger',
            ];
        } elseif ($batchHadPending || $batchHadRejected) {
            $uploadFlash['member_notice'] = PhotoUploadBatchUserMessage::forUploadResponse($uploadedCount, $allBatchMetas);
        } else {
            $uploadFlash['success'] = trans_choice('photo.upload_all_saved_ok', $uploadedCount, ['count' => $uploadedCount]);
        }

        return $this->photoUploadRedirectResponse(
            $request,
            route('matrimony.profile.upload-photo', $this->uploadPhotoRedirectQuery($request, $profile)),
            $uploadFlash
        );
    }

    private function releaseCardOnboardingLock(MatrimonyProfile $profile): void
    {
        $profile->forceFill(['card_onboarding_resume_step' => null])->saveQuietly();
        session()->forget('wizard_minimal');
    }

    /**
     * Core column can stay pending/… while profile_photos already has the processed file (queue edge / legacy rows).
     */
    private function syncCoreProfilePhotoFromPrimaryGalleryIfPendingStale(MatrimonyProfile $profile): void
    {
        $col = trim((string) ($profile->profile_photo ?? ''));
        if ($col === '' || ! ProfilePhotoUrlService::isPendingPlaceholder($col)) {
            return;
        }
        if (ProfilePhotoUrlService::storedFileExistsForRelativePath($col)) {
            return;
        }
        $rel = ProfilePhotoUrlService::primaryNonPendingGalleryRelativePath($profile);
        if ($rel === null || ! ProfilePhotoUrlService::storedFileExistsForRelativePath($rel)) {
            return;
        }
        $profile->forceFill(['profile_photo' => $rel])->saveQuietly();
    }

    /**
     * Make a specific photo the primary photo for the logged-in profile.
     * This does not auto-approve pending/rejected photos.
     */
    public function makePrimary(ProfilePhoto $photo)
    {
        $user = auth()->user();
        $profile = $this->resolvePhotoTargetProfile(request());
        if (! $user || ! $profile) {
            abort(403);
        }
        if ((int) $photo->profile_id !== (int) $profile->id) {
            abort(403);
        }

        $targetApproved = $photo->effectiveApprovedStatus() === 'approved';

        $priorBypass = \App\Models\MatrimonyProfile::$bypassGovernanceEnforcement;
        \App\Models\MatrimonyProfile::$bypassGovernanceEnforcement = true;
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($profile, $photo, $targetApproved): void {
                ProfilePhoto::query()
                    ->where('profile_id', $profile->id)
                    ->update(['is_primary' => false]);

                $photo->is_primary = true;
                $photo->save();

                // Legacy sync: legacy views depend on matrimony_profiles.profile_photo.
                $profile->profile_photo = $photo->file_path;
                $profile->photo_approved = $targetApproved;
                $profile->photo_rejected_at = null;
                $profile->photo_rejection_reason = null;
                $profile->save();
            });
        } finally {
            \App\Models\MatrimonyProfile::$bypassGovernanceEnforcement = $priorBypass;
        }

        return redirect()->route('matrimony.profile.upload-photo', $this->uploadPhotoRedirectQuery(request(), $profile))
            ->with('success', 'Selected photo updated.');
    }

    /**
     * Reorder photos by updating sort_order sequentially (does not change is_primary).
     */
    public function reorderPhotos(Request $request)
    {
        $user = auth()->user();
        $profile = $this->resolvePhotoTargetProfile($request);
        if (! $user || ! $profile) {
            abort(403);
        }

        $request->validate([
            'photo_ids' => ['required', 'array'],
            'photo_ids.*' => ['integer'],
        ]);

        $photoIds = array_values(array_unique(array_map('intval', (array) $request->input('photo_ids', []))));
        if ($photoIds === []) {
            return redirect()->back()->withErrors(['photo_ids' => 'Invalid photo order.'])->withInput();
        }

        $totalPhotos = (int) ProfilePhoto::query()
            ->where('profile_id', $profile->id)
            ->count();

        // Require full set so we can set sort_order sequentially from 0..n-1.
        if (count($photoIds) !== $totalPhotos) {
            return redirect()->back()->withErrors(['photo_ids' => 'Invalid photo order.'])->withInput();
        }

        $countOwned = (int) ProfilePhoto::query()
            ->where('profile_id', $profile->id)
            ->whereIn('id', $photoIds)
            ->count();

        if ($countOwned !== $totalPhotos) {
            return redirect()->back()->withErrors(['photo_ids' => 'Invalid photo order.'])->withInput();
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($profile, $photoIds): void {
            foreach ($photoIds as $idx => $id) {
                ProfilePhoto::query()
                    ->where('profile_id', $profile->id)
                    ->where('id', (int) $id)
                    ->update(['sort_order' => (int) $idx]);
            }
        });

        return redirect()->route('matrimony.profile.upload-photo', $this->uploadPhotoRedirectQuery($request, $profile))
            ->with('success', 'Photo order updated.');
    }

    /**
     * Delete a photo and keep primary + legacy profile_photo consistent.
     */
    public function destroy(ProfilePhoto $photo)
    {
        $user = auth()->user();
        $profile = $this->resolvePhotoTargetProfile(request());
        if (! $user || ! $profile) {
            abort(403);
        }
        if ((int) $photo->profile_id !== (int) $profile->id) {
            abort(403);
        }

        $wasPrimary = (bool) $photo->is_primary;
        $fileToDeleteLegacy = public_path('uploads/matrimony_photos/'.$photo->file_path);
        $fileToDeleteNew = storage_path('app/public/matrimony_photos/'.$photo->file_path);

        $priorBypass = \App\Models\MatrimonyProfile::$bypassGovernanceEnforcement;
        \App\Models\MatrimonyProfile::$bypassGovernanceEnforcement = true;
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($profile, $photo, $wasPrimary): void {
                // Delete record.
                $photo->delete();

                // Resequence sort_order sequentially.
                $remaining = ProfilePhoto::query()
                    ->where('profile_id', $profile->id)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get(['id']);

                foreach ($remaining as $idx => $row) {
                    ProfilePhoto::query()
                        ->where('profile_id', $profile->id)
                        ->where('id', (int) $row->id)
                        ->update(['sort_order' => (int) $idx]);
                }

                if (! $wasPrimary) {
                    return;
                }

                if ($remaining->count() === 0) {
                    $profile->profile_photo = null;
                    $profile->photo_approved = false;
                    $profile->photo_rejected_at = null;
                    $profile->photo_rejection_reason = null;
                    $profile->save();

                    return;
                }

                // Choose replacement primary: first effectively approved, else first remaining by sort_order.
                $replacement = ProfilePhoto::query()
                    ->where('profile_id', $profile->id)
                    ->effectivelyApproved()
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->first();

                if (! $replacement) {
                    $replacement = ProfilePhoto::query()
                        ->where('profile_id', $profile->id)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->first();
                }

                if (! $replacement) {
                    return;
                }

                ProfilePhoto::query()
                    ->where('profile_id', $profile->id)
                    ->update(['is_primary' => false]);

                $replacement->is_primary = true;
                $replacement->save();

                $replacementApproved = $replacement->effectiveApprovedStatus() === 'approved';
                $profile->profile_photo = $replacement->file_path;
                $profile->photo_approved = $replacementApproved;
                $profile->photo_rejected_at = null;
                $profile->photo_rejection_reason = null;
                $profile->save();
            });
        } finally {
            \App\Models\MatrimonyProfile::$bypassGovernanceEnforcement = $priorBypass;
        }

        foreach ([$fileToDeleteNew, $fileToDeleteLegacy] as $fileToDelete) {
            if (is_string($fileToDelete) && $fileToDelete !== '' && is_file($fileToDelete)) {
                @unlink($fileToDelete);
            }
        }

        return redirect()->route('matrimony.profile.upload-photo', $this->uploadPhotoRedirectQuery(request(), $profile))
            ->with('success', 'Photo deleted.');
    }

    /*
    |--------------------------------------------------------------------------
    | Show Single Matrimony Profile
    |--------------------------------------------------------------------------
    |
    | 👉 Public / logged-in users साठी profile view
    |
    | ⚠️ Interest logic इथे तात्पुरता आहे
    | पुढच्या step मध्ये refactor होईल
    |
    */

    // Route param: {matrimony_profile_id} (profile id)
    public function show($matrimony_profile_id)
    {
        $profile = \App\Models\MatrimonyProfile::with([
            'gender',
            'maritalStatus',
            'complexion',
            'physicalBuild',
            'bloodGroup',
            'familyType',
            'incomeCurrency',
            'horoscope',
            'children.childLivingWith',
            'educationHistory',
            'career',
            'addresses.village',
            'relatives.city',
            'relatives.state',
            'allianceNetworks.city',
            'allianceNetworks.state',
            'allianceNetworks.district',
            'allianceNetworks.taluka',
            'birthCity',
            'birthState',
            'birthDistrict',
            'birthTaluka',
            'nativeCity',
            'nativeState',
            'nativeDistrict',
            'nativeTaluka',
            'siblings.city',
            'religion',
            'caste',
            'subCaste',
            'city',
            'taluka',
            'district',
            'state',
            'country',
            'profession',
            'workingWithType',
            'motherTongue',
            'marriages',
            'seriousIntent',
            'user',
        ])->findOrFail($matrimony_profile_id);

        $extendedAttributes = \Illuminate\Support\Facades\DB::table('profile_extended_attributes')->where('profile_id', $profile->id)->first();
        $preferenceCriteria = \Illuminate\Support\Facades\DB::table('profile_preference_criteria')->where('profile_id', $profile->id)->first();
        $preferredReligionIds = \Illuminate\Support\Facades\DB::table('profile_preferred_religions')->where('profile_id', $profile->id)->pluck('religion_id')->all();
        $preferredCasteIds = \Illuminate\Support\Facades\DB::table('profile_preferred_castes')->where('profile_id', $profile->id)->pluck('caste_id')->all();
        $preferredDistrictIds = \Illuminate\Support\Facades\DB::table('profile_preferred_districts')->where('profile_id', $profile->id)->pluck('district_id')->all();
        $preferredMasterEducationIds = Schema::hasTable('profile_preferred_master_education')
            ? \Illuminate\Support\Facades\DB::table('profile_preferred_master_education')->where('profile_id', $profile->id)->pluck('master_education_id')->all()
            : [];
        $preferredDietIds = Schema::hasTable('profile_preferred_diets')
            ? \Illuminate\Support\Facades\DB::table('profile_preferred_diets')->where('profile_id', $profile->id)->pluck('diet_id')->all()
            : [];
        $preferredProfessionIds = Schema::hasTable('profile_preferred_professions')
            ? \Illuminate\Support\Facades\DB::table('profile_preferred_professions')->where('profile_id', $profile->id)->pluck('profession_id')->all()
            : [];
        $preferredWorkingWithTypeIds = Schema::hasTable('profile_preferred_working_with_types')
            ? \Illuminate\Support\Facades\DB::table('profile_preferred_working_with_types')->where('profile_id', $profile->id)->pluck('working_with_type_id')->all()
            : [];
        $preferredMaritalStatusIds = Schema::hasTable('profile_preferred_marital_statuses')
            ? \Illuminate\Support\Facades\DB::table('profile_preferred_marital_statuses')->where('profile_id', $profile->id)->pluck('marital_status_id')->map(fn ($id) => (int) $id)->all()
            : [];
        if ($preferredMaritalStatusIds === [] && $preferenceCriteria && ($preferenceCriteria->preferred_marital_status_id ?? null)) {
            $preferredMaritalStatusIds = [(int) $preferenceCriteria->preferred_marital_status_id];
        }

        // 🔒 GUARD: Guest users are NOT allowed to view single profiles
        if (! auth()->check()) {
            return redirect()
                ->route('login')
                ->with('error', __('common.login_required_to_view_matrimony_profiles'));
        }

        $user = auth()->user();

        // 🔒 Logged-in but no profile
        if (! $user->matrimonyProfile) {
            return redirect()
                ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
                ->with('error', __('interest.create_profile_first'));
        }

        $isOwnProfile = (int) $user->matrimonyProfile->id === (int) $profile->id;

        // 🔒 GUARD: Day 7 lifecycle — Archived/Suspended not visible to others (backward compat: is_suspended, trashed)
        if (! $isOwnProfile && ! \App\Services\ProfileLifecycleService::isVisibleToOthers($profile)) {
            abort(404, __('common.profile_not_found'));
        }

        // 🔒 GUARD: Block excludes profile view (either direction)
        if (! $isOwnProfile && $user->matrimonyProfile) {
            if (ViewTrackingService::isBlocked($user->matrimonyProfile->id, $profile->id)) {
                abort(404, __('common.profile_not_found'));
            }
        }

        // 🔒 GUARD: Phase-4 Day-10 — Women-First Safety visibility policy
        if (! $isOwnProfile && ! \App\Services\ProfileVisibilityPolicyService::canViewProfile($profile, $user)) {
            abort(404, __('common.profile_not_found'));
        }

        // Plan interest-view limit: block direct open when pending incoming interest is outside reveal slots
        if (! $isOwnProfile) {
            $incomingPending = Interest::query()
                ->where('sender_profile_id', $profile->id)
                ->where('receiver_profile_id', $user->matrimonyProfile->id)
                ->where('status', 'pending')
                ->first();
            if ($incomingPending && ! $this->interestSendLimitService->isIncomingInterestUnlocked($user, $incomingPending)) {
                return redirect()
                    ->route('interests.index', ['tab' => 'received'])
                    ->with('info', __('interests.profile_open_locked_use_inbox'));
            }
        }

        $interestAlreadySent = false;

        $interestAlreadySent = \App\Models\Interest::where(
            'sender_profile_id',
            $user->matrimonyProfile->id
        )
            ->where('receiver_profile_id', $profile->id)
            ->exists();

        // Check if user has already submitted an open abuse report for this profile
        $hasAlreadyReported = false;
        if (! $isOwnProfile) {
            $hasAlreadyReported = \App\Models\AbuseReport::where('reporter_user_id', $user->id)
                ->where('reported_profile_id', $profile->id)
                ->where('status', 'open')
                ->exists();
        }

        $inShortlist = false;
        if (! $isOwnProfile && $user->matrimonyProfile) {
            $inShortlist = Shortlist::where('owner_profile_id', $user->matrimonyProfile->id)
                ->where('shortlisted_profile_id', $profile->id)
                ->exists();
        }

        if (! $isOwnProfile && $user->matrimonyProfile) {
            $featureUsage = app(FeatureUsageService::class);
            $userId = (int) $user->id;
            $dailyViewKey = FeatureUsageService::FEATURE_DAILY_PROFILE_VIEW_LIMIT;

            if (! $featureUsage->canUse($userId, $dailyViewKey)) {
                return redirect()
                    ->route('matrimony.profiles.index')
                    ->with('error', __('subscriptions.profile_view_daily_limit'));
            }

            if (ViewTrackingService::recordView($user->matrimonyProfile, $profile)) {
                $featureUsage->consume($userId, $dailyViewKey);
            }
            ViewTrackingService::maybeTriggerViewBack($user->matrimonyProfile, $profile);
        }

        // Detailed section coverage for own-profile show (core % not shown — redundant post-registration)
        $completion = ProfileCompletenessService::breakdown($profile);
        $completenessDetailedPct = $completion['detailed'];

        // Profile show: full stored biodata for every viewer (parity with wizard / DB; no field hiding).
        $profilePhotoVisible = true;
        $dateOfBirthVisible = true;
        $maritalStatusVisible = true;
        $educationVisible = true;
        $locationVisible = true;
        $casteVisible = true;
        $heightVisible = true;

        // Match explanation data (rule-based comparison)
        $matchData = null;
        if (! $isOwnProfile && $user->matrimonyProfile) {
            $matchData = self::calculateMatchExplanation($user->matrimonyProfile, $profile);
        }

        $interestAllowsContact = false;
        if (! $isOwnProfile && $user->matrimonyProfile) {
            $vp = (int) $user->matrimonyProfile->id;
            $interestAllowsContact = Interest::query()
                ->where('status', 'accepted')
                ->where(function ($q) use ($vp, $profile) {
                    $q->where(function ($q2) use ($vp, $profile) {
                        $q2->where('sender_profile_id', $vp)
                            ->where('receiver_profile_id', (int) $profile->id);
                    })->orWhere(function ($q2) use ($vp, $profile) {
                        $q2->where('sender_profile_id', (int) $profile->id)
                            ->where('receiver_profile_id', $vp);
                    });
                })
                ->exists();
        }

        $visibilitySettings = \Illuminate\Support\Facades\DB::table('profile_visibility_settings')
            ->where('profile_id', $profile->id)
            ->first();

        $extendedValues = ExtendedFieldService::getValuesForProfile($profile);
        $extendedMeta = FieldRegistry::where('field_type', 'EXTENDED')
            ->whereIn('field_key', array_keys($extendedValues))
            ->pluck('display_label', 'field_key')
            ->toArray();

        $profile->loadMissing('user');
        $primaryContactPhone = $profile->primary_contact_number;

        $hasBlockingConflicts = \App\Services\ProfileLifecycleService::hasBlockingUnresolvedConflicts($profile);

        $conflictRecords = collect();
        if ($isOwnProfile && ($profile->lifecycle_state ?? null) === 'conflict_pending') {
            $conflictRecords = \App\Models\ConflictRecord::where('profile_id', $profile->id)
                ->where('resolution_status', 'PENDING')
                ->orderBy('field_name')
                ->get();
        }

        $enableRelativesSection = true;

        $profilePropertySummary = \Illuminate\Support\Facades\DB::table('profile_property_summary')
            ->where('profile_id', $profile->id)
            ->first();

        // Preferences: aggregate for view (view also uses $preferenceCriteria, $preferredReligionIds, $preferredCasteIds, $preferredDistrictIds)
        $preferences = [];

        // Day-32: Contact request state for viewer (sender) vs profile owner (receiver)
        $contactRequestState = null;
        $contactRequestDisabled = true;
        $contactGrantReveal = null; // [ 'phone' => ... ] when viewer has valid grant
        $canSendContactRequest = false;
        if (auth()->check() && ! $isOwnProfile && $user->matrimonyProfile) {
            $contactRequestService = app(\App\Services\ContactRequestService::class);
            $contactRequestDisabled = $contactRequestService->isContactRequestDisabled();
            $receiver = $profile->user;
            if ($receiver) {
                $contactRequestState = $contactRequestService->getSenderState($user, $receiver);
                $canSendContactRequest = $contactRequestService->canSendContactRequest($user, $receiver);
                if (($contactRequestState['state'] ?? '') === 'accepted' && ! empty($contactRequestState['grant']) && $contactRequestState['grant']->isValid()) {
                    // Same resolution as public profile display: primary profile_contacts row, else account mobile.
                    // Grant UI shows primary phone only (scopes may include email/WhatsApp for ops; not duplicated here).
                    $phone = $profile->primary_contact_number;
                    $contactGrantReveal = $phone !== null && $phone !== '' ? ['phone' => $phone] : null;
                }
            }
        }

        // Contact gating: visibility + interest — quota via {@see FeatureUsageService} / {@see ContactAccessService::resolveViewerContext}.
        $contactAccess = $isOwnProfile
            ? ContactAccessService::neutralForOwner()
            : $this->contactAccessService->resolveViewerContext(
                $user,
                $profile,
                $interestAllowsContact,
                $visibilitySettings,
                $contactGrantReveal,
            );

        if (! ($contactAccess['has_contact_unlock'] ?? false)) {
            $contactGrantReveal = null;
        }

        // Profile show gates: precompute once for Blade (no direct service calls in views).
        $featureUsage = app(FeatureUsageService::class);
        $gateStates = [
            'contact_view_limit' => $featureUsage->getFeatureState($user, 'contact_view_limit'),
            'daily_profile_view_limit' => $featureUsage->getFeatureState($user, 'daily_profile_view_limit'),
            'chat_send_limit' => $featureUsage->getFeatureState($user, 'chat_send_limit'),
            'chat_can_read' => $featureUsage->getFeatureState($user, 'chat_can_read'),
            'who_viewed_me_access' => $featureUsage->getFeatureState($user, 'who_viewed_me_access'),
            'interest_send_limit' => $featureUsage->getFeatureState($user, 'interest_send_limit'),
        ];
        $showGateSoftLimitWarning = false;
        foreach (['contact_view_limit', 'interest_send_limit', 'chat_send_limit', 'daily_profile_view_limit'] as $_gk) {
            if (($gateStates[$_gk]['reason'] ?? null) === 'soft_limit_warning') {
                $showGateSoftLimitWarning = true;
                break;
            }
        }
        $contactUsageSnapshot = $featureUsage->getContactViewUsageSnapshot($user);
        $canUseContact = ! $isOwnProfile && ($gateStates['contact_view_limit']['allowed'] ?? false);

        $whoViewedEligibleDistinctCount = $isOwnProfile
            ? ViewTrackingService::countEligibleDistinctViewersForTeaser((int) $profile->id)
            : 0;

        $canViewContact = $isOwnProfile
            || (trim((string) ($contactAccess['paid_contact_phone'] ?? '')) !== '')
            || (trim((string) ($contactAccess['paid_contact_email'] ?? '')) !== '');

        $canProfileWhatsappDirect = ! $isOwnProfile
            && app(EntitlementService::class)->hasAccess((int) $user->id, PlanFeatureKeys::PROFILE_WHATSAPP_DIRECT);
        $whatsappWaMeHref = null;
        if ($canProfileWhatsappDirect && $canViewContact) {
            $phoneRaw = trim((string) ($contactAccess['paid_contact_phone'] ?? ''));
            if ($phoneRaw === '') {
                $phoneRaw = trim((string) ($primaryContactPhone ?? ''));
            }
            $digits = preg_replace('/\D+/', '', $phoneRaw);
            if (strlen($digits) === 10) {
                $whatsappWaMeHref = 'https://wa.me/91'.$digits;
            } elseif (strlen($digits) === 12 && str_starts_with($digits, '91')) {
                $whatsappWaMeHref = 'https://wa.me/'.$digits;
            }
        }

        // Profile show: always show gallery / primary photo (no blur lock for other viewers).
        $showPhotoTo = optional($visibilitySettings)->show_photo_to ?? 'all';
        $photoViewAllowed = true;
        $photoLocked = false;

        $verificationPanel = ProfileShowReadService::buildVerificationPanel($profile, $user, $isOwnProfile);

        $galleryPhotos = collect();
        if ($profilePhotoVisible) {
            $galleryQuery = ProfilePhoto::query()
                ->where('profile_id', $profile->id)
                ->where('is_primary', false)
                ->effectivelyApproved();
            if (Schema::hasColumn('profile_photos', 'sort_order')) {
                $galleryQuery->orderBy('sort_order')->orderBy('id');
            } else {
                $galleryQuery->orderByDesc('created_at')->orderBy('id');
            }
            $galleryPhotos = $galleryQuery->take(12)->get();
        }

        $photoAlbumPresentation = $this->profilePhotoAccessService->buildAlbumPresentation(
            $user,
            $profile,
            $isOwnProfile,
            $galleryPhotos
        );

        $reportablePhotoSummary = ! $isOwnProfile ? self::buildReportablePhotoSummary($profile) : null;

        $profileShowSnapshot = app(ProfileShowSnapshotService::class)->build($profile, [
            'is_own_profile' => $isOwnProfile,
            'date_of_birth_visible' => $dateOfBirthVisible,
            'marital_status_visible' => $maritalStatusVisible,
            'education_visible' => $educationVisible,
            'location_visible' => $locationVisible,
            'caste_visible' => $casteVisible,
            'height_visible' => $heightVisible,
            'enable_relatives_section' => $enableRelativesSection,
            'profile_property_summary' => $profilePropertySummary,
            'preference_criteria' => $preferenceCriteria,
            'preferred_religion_ids' => $preferredReligionIds,
            'preferred_caste_ids' => $preferredCasteIds,
            'preferred_district_ids' => $preferredDistrictIds,
            'preferred_master_education_ids' => $preferredMasterEducationIds,
            'preferred_diet_ids' => $preferredDietIds,
            'preferred_profession_ids' => $preferredProfessionIds,
            'preferred_working_with_type_ids' => $preferredWorkingWithTypeIds,
            'preferred_marital_status_ids' => $preferredMaritalStatusIds,
            'extended_attributes' => $extendedAttributes,
            'extended_values' => $extendedValues,
            'extended_meta' => $extendedMeta,
        ]);

        return view(
            'matrimony.profile.show',
            [
                'profile' => $profile,
                'profileShowSnapshot' => $profileShowSnapshot,
                'profilePropertySummary' => $profilePropertySummary,
                'enableRelativesSection' => $enableRelativesSection,
                'isOwnProfile' => $isOwnProfile,
                'interestAlreadySent' => $interestAlreadySent,
                'hasAlreadyReported' => $hasAlreadyReported,
                'inShortlist' => $inShortlist,
                'extendedValues' => $extendedValues,
                'extendedMeta' => $extendedMeta,
                'extendedAttributes' => $extendedAttributes,
                'preferences' => $preferences,
                'preferenceCriteria' => $preferenceCriteria,
                'preferredReligionIds' => $preferredReligionIds,
                'preferredCasteIds' => $preferredCasteIds,
                'preferredDistrictIds' => $preferredDistrictIds,
                'preferredMasterEducationIds' => $preferredMasterEducationIds,
                'preferredDietIds' => $preferredDietIds,
                'preferredProfessionIds' => $preferredProfessionIds,
                'preferredWorkingWithTypeIds' => $preferredWorkingWithTypeIds,
                'preferredMaritalStatusIds' => $preferredMaritalStatusIds,
                'completenessDetailedPct' => $completenessDetailedPct,
                'profilePhotoVisible' => $profilePhotoVisible,
                'dateOfBirthVisible' => $dateOfBirthVisible,
                'maritalStatusVisible' => $maritalStatusVisible,
                'educationVisible' => $educationVisible,
                'locationVisible' => $locationVisible,
                'casteVisible' => $casteVisible,
                'heightVisible' => $heightVisible,
                'matchData' => $matchData,
                'canViewContact' => $canViewContact,
                'primaryContactPhone' => $primaryContactPhone,
                'contactAccess' => $contactAccess,
                'canUseContact' => $canUseContact,
                'contactUsageSnapshot' => $contactUsageSnapshot,
                'gateStates' => $gateStates,
                'whoViewedEligibleDistinctCount' => $whoViewedEligibleDistinctCount,
                'showGateSoftLimitWarning' => $showGateSoftLimitWarning,
                'hasBlockingConflicts' => $hasBlockingConflicts,
                'conflictRecords' => $conflictRecords,
                'contactRequestState' => $contactRequestState,
                'contactRequestDisabled' => $contactRequestDisabled,
                'contactGrantReveal' => $contactGrantReveal,
                'canSendContactRequest' => $canSendContactRequest,
                'interestAllowsContact' => $interestAllowsContact,
                'photoLocked' => $photoLocked,
                'photoLockMode' => $showPhotoTo ?? 'all',
                'verificationPanel' => $verificationPanel,
                'galleryPhotos' => $galleryPhotos,
                'photoAlbumPresentation' => $photoAlbumPresentation,
                'reportablePhotoSummary' => $reportablePhotoSummary,
                'canProfileWhatsappDirect' => $canProfileWhatsappDirect,
                'whatsappWaMeHref' => $whatsappWaMeHref,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | List & Search Matrimony Profiles
    |--------------------------------------------------------------------------
    |
    | 👉 Search + listing साठी
    | 👉 Only MatrimonyProfile model वापरतो
    |
    */
    public function index(Request $request)
    {
        $viewerOwnProfileId = auth()->user()?->matrimonyProfile?->id;

        $request->attributes->set(
            'advanced_profile_search',
            auth()->check()
                && app(EntitlementService::class)->hasAccess((int) auth()->id(), PlanFeatureKeys::ADVANCED_PROFILE_SEARCH)
        );

        $filterQuery = MatrimonyProfileSearchQueryService::newFilteredListingQuery($request, $viewerOwnProfileId);
        $totalCount = (clone $filterQuery)->count();

        $strictKeys = AutoShowcaseSettings::strictDimensionKeys();
        $strictCount = MatrimonyProfileSearchQueryService::countStrictMatches($request, $viewerOwnProfileId, $strictKeys);

        if (auth()->check()) {
            app(AutoShowcaseEngine::class)->evaluateAfterSearchCounts(
                $request,
                auth()->user(),
                $totalCount,
                $strictCount
            );
        }

        $query = MatrimonyProfileSearchQueryService::newFilteredListingQuery($request, $viewerOwnProfileId);

        $myId = auth()->user()?->matrimonyProfile?->id;
        $viewerUserId = auth()->id();

        ProfileSearchRankingService::applySpotlightFirst($query);

        $defaultSort = ($viewerOwnProfileId && ProfileRotationService::isEnabled()) ? 'discover' : 'latest';
        $sort = (string) $request->input('sort', $defaultSort);
        $allowedSorts = ['latest', 'age_asc', 'age_desc', 'height_asc', 'height_desc', 'discover'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'latest';
        }

        if ($sort === 'discover' && (! $myId || ! ProfileRotationService::isEnabled())) {
            $sort = 'latest';
        }

        if ($sort === 'discover' && $myId && ProfileRotationService::isEnabled()) {
            ProfileRotationService::applyDiscoverScope($query, (int) $myId, $viewerUserId);
        }

        if ($sort === 'discover' && $myId && ProfileRotationService::isEnabled()) {
            ProfileRotationService::applyDiscoverOrdering(
                $query,
                (int) $myId,
                ProfileRotationService::stableSeedForSession()
            );
        } else {
            switch ($sort) {
                case 'age_asc':
                    // Younger first: more recent date_of_birth first; nulls last
                    $query->orderByRaw('CASE WHEN date_of_birth IS NULL THEN 1 ELSE 0 END ASC')
                        ->orderBy('date_of_birth', 'desc');
                    break;
                case 'age_desc':
                    // Older first: older date_of_birth first; nulls last
                    $query->orderByRaw('CASE WHEN date_of_birth IS NULL THEN 1 ELSE 0 END ASC')
                        ->orderBy('date_of_birth', 'asc');
                    break;
                case 'height_asc':
                    $query->orderByRaw('CASE WHEN height_cm IS NULL THEN 1 ELSE 0 END ASC')
                        ->orderBy('height_cm', 'asc');
                    break;
                case 'height_desc':
                    $query->orderByRaw('CASE WHEN height_cm IS NULL THEN 1 ELSE 0 END ASC')
                        ->orderBy('height_cm', 'desc');
                    break;
                case 'latest':
                default:
                    $query->latest();
                    break;
            }
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = $perPage >= 1 && $perPage <= 100 ? $perPage : 15;
        $profiles = $query->with([
            'country',
            'state',
            'district',
            'taluka',
            'city',
            'gender',
            'maritalStatus',
            'religion',
            'caste',
            'subCaste',
            'profession',
            'seriousIntent',
            'user:id,email_verified_at,last_seen_at',
            'photos' => function ($q) {
                $q->effectivelyApproved();
            },
        ])->paginate($perPage)->withQueryString();

        $viewerProfile = auth()->user()?->matrimonyProfile;
        $profiles->setCollection($profiles->getCollection()->map(function (MatrimonyProfile $listedProfile) use ($viewerProfile) {
            $listedProfile->compatibility_summary = null;
            $listedProfile->online_status_summary = self::buildOnlineStatusSummaryForUser($listedProfile->user);
            $listedProfile->reportable_photo_summary = self::buildReportablePhotoSummary($listedProfile);

            if ($viewerProfile && (int) $listedProfile->id !== (int) $viewerProfile->id) {
                $matchData = self::calculateMatchExplanation($viewerProfile, $listedProfile);
                $matchedCount = (int) ($matchData['matchedCount'] ?? 0);
                $totalCount = (int) ($matchData['totalCount'] ?? 0);
                $label = $matchedCount >= 3
                    ? 'Strong match'
                    : ($matchedCount >= 1 ? 'Good match' : 'Explore match');
                $listedProfile->compatibility_summary = [
                    'matched_count' => $matchedCount,
                    'total_count' => $totalCount,
                    'label' => $label,
                ];
            }

            return $listedProfile;
        }));

        // Lookup lists for filter controls (read-only; same keys as request inputs above)
        $locationLookups = $this->buildProfileSearchLocationLookups($request);
        $religions = Religion::query()
            ->where(function ($q) {
                $q->where('is_active', true)->orWhereNull('is_active');
            })
            ->orderBy('label_en')
            ->orderBy('label')
            ->get();
        $castes = Caste::query()
            ->where(function ($q) {
                $q->where('is_active', true)->orWhereNull('is_active');
            })
            ->orderBy('label_en')
            ->orderBy('label')
            ->get();
        $subCastes = SubCaste::query()
            ->where('is_active', true)
            ->where('status', 'approved')
            ->orderBy('label_en')
            ->orderBy('label')
            ->get();
        $professions = Profession::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $seriousIntents = SeriousIntent::query()
            ->orderBy('name')
            ->get();
        $maritalStatuses = MasterMaritalStatus::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $resolvedMaritalStatusId = $request->input('marital_status_id');
        if (! $resolvedMaritalStatusId && $request->filled('marital_status')) {
            $resolvedMaritalStatusId = $request->input('marital_status') === 'single'
                ? MasterMaritalStatus::where('key', 'never_married')->value('id')
                : MasterMaritalStatus::where('key', $request->input('marital_status'))->value('id');
        }

        return view('matrimony.profile.index', array_merge(compact(
            'profiles',
            'religions',
            'castes',
            'subCastes',
            'professions',
            'seriousIntents',
            'maritalStatuses',
            'resolvedMaritalStatusId',
            'sort'
        ), $locationLookups, [
            'canAdvancedProfileSearch' => (bool) $request->attributes->get('advanced_profile_search', false),
        ]));

    }

    /**
     * Truthful presence label from user.last_seen_at (runtime-only for listing cards).
     *
     * @return array{is_online: bool, label: string}|null
     */
    private static function buildOnlineStatusSummaryForUser(?User $user): ?array
    {
        if ($user === null || $user->last_seen_at === null) {
            return null;
        }

        $ls = $user->last_seen_at;
        $onlineThresholdMinutes = 5;
        if ($ls->greaterThanOrEqualTo(now()->subMinutes($onlineThresholdMinutes))) {
            return ['is_online' => true, 'label' => 'Online now'];
        }

        $m = (int) floor($ls->diffInMinutes(now()));

        if ($m < 60) {
            $n = max(1, $m);
            $label = $n === 1
                ? "Online {$n} minute ago"
                : "Online {$n} minutes ago";

            return ['is_online' => false, 'label' => $label];
        }

        if ($m < 1440) {
            $n = (int) max(1, floor($m / 60));
            $label = $n === 1
                ? "Online {$n} hour ago"
                : "Online {$n} hours ago";

            return ['is_online' => false, 'label' => $label];
        }

        $n = (int) max(1, floor($m / 1440));
        $label = $n === 1
            ? "Online {$n} day ago"
            : "Online {$n} days ago";

        return ['is_online' => false, 'label' => $label];
    }

    /**
     * Gallery row id for the photo shown on the search card (approved only).
     *
     * @return array{profile_photo_id: int, file_path: string}|null
     */
    private static function buildReportablePhotoSummary(MatrimonyProfile $listedProfile): ?array
    {
        $path = trim((string) ($listedProfile->profile_photo ?? ''));
        if ($path === '' || $listedProfile->photo_approved === false) {
            return null;
        }

        $photos = $listedProfile->relationLoaded('photos') ? $listedProfile->photos : collect();
        $record = $photos->firstWhere('file_path', $path)
            ?? $photos->firstWhere('is_primary', true)
            ?? $photos->first();

        if ($record === null) {
            return null;
        }

        return [
            'profile_photo_id' => (int) $record->id,
            'file_path' => (string) $record->file_path,
        ];
    }

    /**
     * Minimal location dropdown data for /profiles search (Phase-6).
     *
     * @return array<string, mixed>
     */
    private function buildProfileSearchLocationLookups(Request $request): array
    {
        $hintCountry = $request->filled('country_id') ? (int) $request->country_id : null;
        $hintState = $request->filled('state_id') ? (int) $request->state_id : null;
        $hintDistrict = $request->filled('district_id') ? (int) $request->district_id : null;
        $hintTaluka = $request->filled('taluka_id') ? (int) $request->taluka_id : null;
        $hintCity = $request->filled('city_id') ? (int) $request->city_id : null;

        if ($hintCity) {
            $cityRow = City::query()->with('taluka.district.state')->find($hintCity);
            if ($cityRow?->taluka) {
                $hintTaluka = $hintTaluka ?? (int) $cityRow->taluka_id;
                $d = $cityRow->taluka->district;
                if ($d) {
                    $hintDistrict = $hintDistrict ?? (int) $d->id;
                    $s = $d->state;
                    if ($s) {
                        $hintState = $hintState ?? (int) $s->id;
                        $hintCountry = $hintCountry ?? (int) $s->country_id;
                    }
                }
            }
        } elseif ($hintTaluka) {
            $t = Taluka::query()->with('district.state')->find($hintTaluka);
            if ($t?->district) {
                $hintDistrict = $hintDistrict ?? (int) $t->district_id;
                $s = $t->district->state;
                if ($s) {
                    $hintState = $hintState ?? (int) $s->id;
                    $hintCountry = $hintCountry ?? (int) $s->country_id;
                }
            }
        } elseif ($hintDistrict) {
            $d = District::query()->with('state')->find($hintDistrict);
            if ($d?->state) {
                $hintState = $hintState ?? (int) $d->state_id;
                $hintCountry = $hintCountry ?? (int) $d->state->country_id;
            }
        } elseif ($hintState) {
            $s = State::query()->find($hintState);
            if ($s) {
                $hintCountry = $hintCountry ?? (int) $s->country_id;
            }
        }

        $displayCountryId = $request->filled('country_id') ? (int) $request->country_id : $hintCountry;
        $displayStateId = $request->filled('state_id') ? (int) $request->state_id : $hintState;
        $displayDistrictId = $request->filled('district_id') ? (int) $request->district_id : $hintDistrict;
        $displayTalukaId = $request->filled('taluka_id') ? (int) $request->taluka_id : $hintTaluka;
        $displayCityId = $request->filled('city_id') ? (int) $request->city_id : $hintCity;

        $listCountryId = $hintCountry;
        $listStateId = $hintState;
        $listDistrictId = $hintDistrict;
        $listTalukaId = $hintTaluka;

        $countries = Country::query()->orderBy('name')->get();

        $states = collect();
        if ($listCountryId) {
            $states = State::query()->where('country_id', $listCountryId)->orderBy('name')->get();
        } elseif ($listStateId) {
            $st = State::query()->find($listStateId);
            if ($st) {
                $states = State::query()->where('country_id', $st->country_id)->orderBy('name')->get();
            }
        }

        $districts = collect();
        if ($listStateId) {
            $districts = District::query()->where('state_id', $listStateId)->orderBy('name')->get();
        } elseif ($listDistrictId) {
            $d = District::query()->find($listDistrictId);
            if ($d) {
                $districts = District::query()->where('state_id', $d->state_id)->orderBy('name')->get();
            }
        }

        $talukas = collect();
        if ($listDistrictId) {
            $talukas = Taluka::query()->where('district_id', $listDistrictId)->orderBy('name')->get();
        } elseif ($listTalukaId) {
            $t = Taluka::query()->find($listTalukaId);
            if ($t) {
                $talukas = Taluka::query()->where('district_id', $t->district_id)->orderBy('name')->get();
            }
        }

        $cities = collect();
        if ($listTalukaId) {
            $cities = City::query()->where('taluka_id', $listTalukaId)->orderBy('name')->get();
        } elseif ($hintCity) {
            $c = City::query()->find($hintCity);
            if ($c) {
                $cities = City::query()->where('taluka_id', $c->taluka_id)->orderBy('name')->get();
            }
        }

        return [
            'countries' => $countries,
            'states' => $states,
            'districts' => $districts,
            'talukas' => $talukas,
            'cities' => $cities,
            'locationDisplayCountryId' => $displayCountryId,
            'locationDisplayStateId' => $displayStateId,
            'locationDisplayDistrictId' => $displayDistrictId,
            'locationDisplayTalukaId' => $displayTalukaId,
            'locationDisplayCityId' => $displayCityId,
        ];
    }

    /**
     * Calculate match explanation between viewer's profile and viewed profile.
     * Rule-based comparison, no AI/ML. Returns match data for UI display.
     *
     * @param  MatrimonyProfile  $viewerProfile  Viewer's own profile
     * @param  MatrimonyProfile  $viewedProfile  Profile being viewed
     * @return array|null Match explanation data or null if own profile
     */
    private static function calculateMatchExplanation(MatrimonyProfile $viewerProfile, MatrimonyProfile $viewedProfile): array
    {
        $matches = [];
        $commonGround = [];

        // Define comparison fields (deterministic, stored values only) — location handled separately via hierarchy.
        $preferenceFields = [
            'highest_education' => ['label' => 'Education', 'icon' => '🎓'],
            'caste_id' => ['label' => 'Caste', 'icon' => '🗣️'],
            'marital_status_id' => ['label' => 'Marital status', 'icon' => '💑'],
        ];

        // Location comparison (hierarchy: city_id = exact match, state_id = partial)
        $viewerCityId = $viewerProfile->city_id;
        $viewedCityId = $viewedProfile->city_id;
        $viewerStateId = $viewerProfile->state_id;
        $viewedStateId = $viewedProfile->state_id;
        if ($viewerCityId || $viewedCityId || $viewerStateId || $viewedStateId) {
            $locationMatched = false;
            if ($viewerCityId && $viewedCityId && (int) $viewerCityId === (int) $viewedCityId) {
                $locationMatched = true;
            } elseif ($viewerStateId && $viewedStateId && (int) $viewerStateId === (int) $viewedStateId) {
                $locationMatched = true; // partial (same state)
            }
            $matches[] = [
                'field' => 'location',
                'label' => 'Location',
                'icon' => '📍',
                'matched' => $locationMatched,
            ];
            if ($locationMatched) {
                $commonGround[] = [
                    'field' => 'location',
                    'label' => 'Location',
                    'icon' => '📍',
                    'value' => $viewedProfile->city_id ? ($viewedProfile->city?->name ?? '—') : ($viewedProfile->state?->name ?? '—'),
                ];
            }
        }

        // Age comparison (from date_of_birth)
        if ($viewerProfile->date_of_birth && $viewedProfile->date_of_birth) {
            $viewerAge = now()->diffInYears($viewerProfile->date_of_birth);
            $viewedAge = now()->diffInYears($viewedProfile->date_of_birth);
            $ageDiff = abs($viewerAge - $viewedAge);

            // Consider age match if within 5 years (flexible)
            if ($ageDiff <= 5) {
                $matches[] = [
                    'field' => 'age',
                    'label' => 'Age',
                    'icon' => '🎂',
                    'matched' => true,
                ];
            } else {
                $matches[] = [
                    'field' => 'age',
                    'label' => 'Age',
                    'icon' => '🎂',
                    'matched' => false,
                ];
            }
        }

        // Compare other preference fields
        foreach ($preferenceFields as $fieldKey => $fieldInfo) {
            $viewerValue = $viewerProfile->getAttribute($fieldKey);
            $viewedValue = $viewedProfile->getAttribute($fieldKey);

            if ($viewerValue !== null && $viewerValue !== '' && $viewedValue !== null && $viewedValue !== '') {
                $isMatch = false;
                if ($fieldKey === 'highest_education') {
                    $isMatch = strcasecmp(trim((string) $viewerValue), trim((string) $viewedValue)) === 0;
                } else {
                    $isMatch = (int) $viewerValue === (int) $viewedValue;
                }

                $matches[] = [
                    'field' => $fieldKey,
                    'label' => $fieldInfo['label'],
                    'icon' => $fieldInfo['icon'],
                    'matched' => $isMatch,
                ];

                // Add to common ground if matched
                if ($isMatch) {
                    $displayValue = (string) $viewedValue;
                    if ($fieldKey === 'caste_id') {
                        $displayValue = (string) ($viewedProfile->caste?->display_label ?? $displayValue);
                    } elseif ($fieldKey === 'marital_status_id') {
                        $displayValue = (string) ($viewedProfile->maritalStatus?->label ?? $displayValue);
                    } elseif ($fieldKey === 'highest_education') {
                        $displayValue = (string) $viewedValue;
                    }

                    $commonGround[] = [
                        'field' => $fieldKey,
                        'label' => $fieldInfo['label'],
                        'icon' => $fieldInfo['icon'],
                        'value' => $displayValue,
                    ];
                }
            }
        }

        // Calculate match summary
        $matchedCount = count(array_filter($matches, fn ($m) => $m['matched']));
        $totalCount = count($matches);

        // Generate summary text (translated)
        if ($totalCount > 0) {
            if ($matchedCount > 0) {
                $summaryText = __('Your profile matches :matched of :total expectations', ['matched' => $matchedCount, 'total' => $totalCount]);
            } else {
                $summaryText = __('Some match with this profile.');
            }
        } else {
            $summaryText = __('Some match with this profile.');
        }

        // Celebration text (translated)
        $celebrationText = null;
        if ($matchedCount >= 3) {
            $celebrationText = __('Many things match!');
        } elseif ($matchedCount > 0) {
            $celebrationText = __('Good start 👍');
        }

        return [
            'matches' => $matches,
            'commonGround' => $commonGround,
            'matchedCount' => $matchedCount,
            'totalCount' => $totalCount,
            'summaryText' => $summaryText,
            'celebrationText' => $celebrationText,
        ];
    }

    /**
     * Phase-4 Day-8: Validate location hierarchy integrity
     * Ensures child location references correct parent in hierarchy
     */
    private function validateLocationHierarchy(Request $request): void
    {
        // If city provided, validate it belongs to the selected taluka (if provided)
        if ($request->filled('city_id') && $request->filled('taluka_id')) {
            $city = \App\Models\City::find($request->city_id);
            if ($city && $city->taluka_id != $request->taluka_id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'city_id' => 'Selected city does not belong to the selected taluka.',
                ]);
            }
        }

        // If taluka provided, validate it belongs to the selected district (if provided)
        if ($request->filled('taluka_id') && $request->filled('district_id')) {
            $taluka = \App\Models\Taluka::find($request->taluka_id);
            if ($taluka && $taluka->district_id != $request->district_id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'taluka_id' => 'Selected taluka does not belong to the selected district.',
                ]);
            }
        }

        // If district provided, validate it belongs to the selected state
        if ($request->filled('district_id') && $request->filled('state_id')) {
            $district = \App\Models\District::find($request->district_id);
            if ($district && $district->state_id != $request->state_id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'district_id' => 'Selected district does not belong to the selected state.',
                ]);
            }
        }

        // State must belong to the selected country
        if ($request->filled('state_id') && $request->filled('country_id')) {
            $state = \App\Models\State::find($request->state_id);
            if ($state && $state->country_id != $request->country_id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'state_id' => 'Selected state does not belong to the selected country.',
                ]);
            }
        }
    }
}
