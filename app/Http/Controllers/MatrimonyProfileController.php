<?php

namespace App\Http\Controllers;

use App\Models\Caste;
use App\Models\City;
use App\Models\Country;
use App\Models\District;
use App\Models\FieldRegistry;
use App\Models\HiddenProfile;
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
use App\Services\ExtendedFieldService;
use App\Services\ProfileCompletenessService;
use App\Services\ProfileFieldConfigurationService;
use App\Services\ProfileShowReadService;
use App\Services\ViewTrackingService;
use Illuminate\Http\Request;
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

        // Phase-5B: Single edit path = wizard. Redirect to wizard (full section).
        return redirect()->route('matrimony.profile.wizard.section', ['section' => 'full']);
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

        return redirect()->route('matrimony.profile.wizard.section', ['section' => 'full']);
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
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'full'])
                ->with('error', __('common.no_valid_data_to_save'))
                ->withInput();
        }
        try {
            $result = app(\App\Services\MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'full'])
                ->withErrors($e->errors())
                ->withInput();
        } catch (\RuntimeException $e) {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'full'])
                ->with('error', $e->getMessage())
                ->withInput();
        }
        if ($result['conflict_detected'] ?? false) {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'full'])
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
        $user = auth()->user();

        if (! $user || ! $user->matrimonyProfile) {
            return redirect()
                ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
                ->with('error', __('profile_actions.create_profile_first'));
        }

        $profile = $user->matrimonyProfile;

        $galleryPhotosQuery = ProfilePhoto::query()
            ->where('profile_id', $profile->id);

        if (\Illuminate\Support\Facades\Schema::hasColumn('profile_photos', 'sort_order')) {
            $galleryPhotosQuery->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id');
        } else {
            $galleryPhotosQuery->orderByDesc('is_primary')->orderByDesc('created_at')->orderBy('id');
        }

        $galleryPhotos = $galleryPhotosQuery->get();

        $photoApprovalRequired = \App\Services\Admin\AdminSettingService::isPhotoApprovalRequired();
        $photoMaxPerProfile = (int) \App\Models\AdminSetting::getValue('photo_max_per_profile', '5');

        $currentPhotoCount = $galleryPhotos->count();
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
        ]);
    }

    public function storePhoto(Request $request)
    {
        $maxUploadMb = (int) \App\Models\AdminSetting::getValue('photo_max_upload_mb', '8');
        $maxUploadKb = max(1, $maxUploadMb) * 1024;

        $request->validate([
            'profile_photo' => 'required|image|max:'.$maxUploadKb,
            'profile_photos' => 'sometimes|array',
            'profile_photos.*' => 'image|max:'.$maxUploadKb,
        ]);

        $user = auth()->user();

        // 🔒 Guard: MatrimonyProfile must exist
        if (! $user->matrimonyProfile) {
            return redirect()
                ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
                ->with('error', __('profile_actions.create_profile_first'));
        }

        $profile = $user->matrimonyProfile;
        if ($profile->user_id !== $user->id) {
            abort(403, __('common.unauthorized_photo_update'));
        }

        // Phase-5 PART-5: Block manual edit when lifecycle blocks it
        if (in_array($profile->lifecycle_state, [
            'intake_uploaded', 'awaiting_user_approval', 'approved_pending_mutation', 'conflict_pending',
        ], true)) {
            return redirect()->back()->with('error', __('common.profile_edit_blocked_intake_conflict'));
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

        $targetDir = public_path('uploads/matrimony_photos');
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $storeUploadedPhoto = function ($file, int $idx) use ($targetDir, $maxEdgePx): string {
            $originalName = basename((string) ($file->getClientOriginalName() ?: 'photo'));
            $slug = pathinfo($originalName, PATHINFO_FILENAME);
            $rand = bin2hex(random_bytes(3));
            $baseName = time().'_'.$idx.'_'.$rand.'_'.$slug;

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

                $webpFilename = $baseName.'.webp';
                $webpPath = $targetDir.DIRECTORY_SEPARATOR.$webpFilename;
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

                return $webpFilename;
            }

            // Fallback: store original file without re-encoding (keeps old behaviour on systems without GD/WebP)
            $extension = $file->getClientOriginalExtension() ?: 'jpg';
            $filename = $baseName.'.'.$extension;
            $file->move($targetDir, $filename);

            return $filename;
        };

        try {
            $primaryFilename = $storeUploadedPhoto($primaryFile, 0);
            $additionalFilenames = [];
            foreach ($additionalFiles as $i => $addFile) {
                $additionalFilenames[] = $storeUploadedPhoto($addFile, (int) $i + 1);
            }
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $photoApprovalRequired = \App\Services\Admin\AdminSettingService::isPhotoApprovalRequired();
        $photoApproved = ! $photoApprovalRequired;

        $approvedStatus = $photoApproved ? 'approved' : 'pending';

        $result = ['conflict_detected' => false];
        if ($mainBecomesPrimary) {
            $snapshot = [
                'core' => [
                    'profile_photo' => $primaryFilename,
                    'photo_approved' => $photoApproved,
                    'photo_rejected_at' => null,
                    'photo_rejection_reason' => null,
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
            } catch (\RuntimeException $e) {
                return redirect()->back()->with('error', $e->getMessage());
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

        // Insert main uploaded photo.
        ProfilePhoto::create([
            'profile_id' => $profile->id,
            'file_path' => $primaryFilename,
            'is_primary' => $mainBecomesPrimary,
            'uploaded_via' => 'user_web',
            'approved_status' => $approvedStatus,
            'watermark_detected' => false,
        ] + $sortFieldsMain);

        // Insert additional photos as non-primary by default.
        if (! empty($additionalFilenames)) {
            foreach (array_values($additionalFilenames) as $i => $filename) {
                $sortFieldsAdditional = [];
                if ($hasSort) {
                    $sortFieldsAdditional['sort_order'] = $sortBase + 2 + (int) $i;
                }

                ProfilePhoto::create([
                    'profile_id' => $profile->id,
                    'file_path' => $filename,
                    'is_primary' => false,
                    'uploaded_via' => 'user_web',
                    'approved_status' => $approvedStatus,
                    'watermark_detected' => false,
                ] + $sortFieldsAdditional);
            }
        }

        if (! empty($result['conflict_detected'])) {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'full'])->with('warning', 'Photo uploaded but some conflicts were detected.');
        }

        $additionalCount = is_array($additionalFilenames) ? count($additionalFilenames) : 0;
        $uploadedCount = 1 + $additionalCount;

        return redirect()->route('matrimony.profile.upload-photo')->with(
            'success',
            "Photos uploaded successfully ({$uploadedCount}). You can add more photos below."
        );
    }

    /**
     * Make a specific photo the primary photo for the logged-in profile.
     * This does not auto-approve pending/rejected photos.
     */
    public function makePrimary(ProfilePhoto $photo)
    {
        $user = auth()->user();
        if (! $user || ! $user->matrimonyProfile) {
            abort(403);
        }

        $profile = $user->matrimonyProfile;
        if ((int) $photo->profile_id !== (int) $profile->id) {
            abort(403);
        }

        $targetApproved = ((string) $photo->approved_status) === 'approved';

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

        return redirect()->route('matrimony.profile.upload-photo')
            ->with('success', 'Selected photo updated.');
    }

    /**
     * Reorder photos by updating sort_order sequentially (does not change is_primary).
     */
    public function reorderPhotos(Request $request)
    {
        $user = auth()->user();
        if (! $user || ! $user->matrimonyProfile) {
            abort(403);
        }

        $profile = $user->matrimonyProfile;

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

        return redirect()->route('matrimony.profile.upload-photo')
            ->with('success', 'Photo order updated.');
    }

    /**
     * Delete a photo and keep primary + legacy profile_photo consistent.
     */
    public function destroy(ProfilePhoto $photo)
    {
        $user = auth()->user();
        if (! $user || ! $user->matrimonyProfile) {
            abort(403);
        }

        $profile = $user->matrimonyProfile;
        if ((int) $photo->profile_id !== (int) $profile->id) {
            abort(403);
        }

        $wasPrimary = (bool) $photo->is_primary;
        $fileToDelete = public_path('uploads/matrimony_photos/'.$photo->file_path);

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

                // Choose replacement primary: first approved, else first remaining by sort_order.
                $replacement = ProfilePhoto::query()
                    ->where('profile_id', $profile->id)
                    ->where('approved_status', 'approved')
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

                $replacementApproved = ((string) $replacement->approved_status) === 'approved';
                $profile->profile_photo = $replacement->file_path;
                $profile->photo_approved = $replacementApproved;
                $profile->photo_rejected_at = null;
                $profile->photo_rejection_reason = null;
                $profile->save();
            });
        } finally {
            \App\Models\MatrimonyProfile::$bypassGovernanceEnforcement = $priorBypass;
        }

        if (is_string($fileToDelete) && $fileToDelete !== '' && is_file($fileToDelete)) {
            @unlink($fileToDelete);
        }

        return redirect()->route('matrimony.profile.upload-photo')
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

        // 🔒 GUARD: Guest users are NOT allowed to view single profiles
        if (! auth()->check()) {
            return redirect()
                ->route('login')
                ->with('error', __('common.login_required_to_view_matrimony_profiles'));
        }

        $authUser = auth()->user();

        // 🔒 Logged-in but no profile
        if (! $authUser->matrimonyProfile) {
            return redirect()
                ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
                ->with('error', __('interest.create_profile_first'));
        }

        $viewer = auth()->user(); // logged-in user
        $isOwnProfile = $viewer && (
            $viewer->matrimonyProfile->id === $profile->id
        );

        // 🔒 GUARD: Day 7 lifecycle — Archived/Suspended not visible to others (backward compat: is_suspended, trashed)
        if (! $isOwnProfile && ! \App\Services\ProfileLifecycleService::isVisibleToOthers($profile)) {
            abort(404, __('common.profile_not_found'));
        }

        // 🔒 GUARD: Block excludes profile view (either direction)
        if (! $isOwnProfile && $viewer->matrimonyProfile) {
            if (ViewTrackingService::isBlocked($viewer->matrimonyProfile->id, $profile->id)) {
                abort(404, __('common.profile_not_found'));
            }
        }

        // 🔒 GUARD: Phase-4 Day-10 — Women-First Safety visibility policy
        if (! $isOwnProfile && ! \App\Services\ProfileVisibilityPolicyService::canViewProfile($profile, $viewer)) {
            abort(404, __('common.profile_not_found'));
        }

        $interestAlreadySent = false;

        if (auth()->check()) {
            $interestAlreadySent = \App\Models\Interest::where(
                'sender_profile_id',
                auth()->user()->matrimonyProfile->id
            )
                ->where('receiver_profile_id', $profile->id)
                ->exists();
        }

        // Check if user has already submitted an open abuse report for this profile
        $hasAlreadyReported = false;
        if (auth()->check() && ! $isOwnProfile) {
            $hasAlreadyReported = \App\Models\AbuseReport::where('reporter_user_id', auth()->id())
                ->where('reported_profile_id', $profile->id)
                ->where('status', 'open')
                ->exists();
        }

        $inShortlist = false;
        if (! $isOwnProfile && $viewer->matrimonyProfile) {
            $inShortlist = Shortlist::where('owner_profile_id', $viewer->matrimonyProfile->id)
                ->where('shortlisted_profile_id', $profile->id)
                ->exists();
        }

        if (! $isOwnProfile && $viewer->matrimonyProfile && ! ($viewer->is_admin ?? false)) {
            ViewTrackingService::recordView($viewer->matrimonyProfile, $profile);
            ViewTrackingService::maybeTriggerViewBack($viewer->matrimonyProfile, $profile);
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
        if (! $isOwnProfile && $viewer->matrimonyProfile) {
            $matchData = self::calculateMatchExplanation($viewer->matrimonyProfile, $profile);
        }

        $canViewContact = true;

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

        $visibilitySettings = \Illuminate\Support\Facades\DB::table('profile_visibility_settings')
            ->where('profile_id', $profile->id)
            ->first();
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
        if (auth()->check() && ! $isOwnProfile && $viewer && $viewer->matrimonyProfile) {
            $contactRequestService = app(\App\Services\ContactRequestService::class);
            $contactRequestDisabled = $contactRequestService->isContactRequestDisabled();
            $receiver = $profile->user;
            if ($receiver) {
                $contactRequestState = $contactRequestService->getSenderState($viewer, $receiver);
                $canSendContactRequest = $contactRequestService->canSendContactRequest($viewer, $receiver);
                if (($contactRequestState['state'] ?? '') === 'accepted' && ! empty($contactRequestState['grant']) && $contactRequestState['grant']->isValid()) {
                    // Same resolution as public profile display: primary profile_contacts row, else account mobile.
                    $phone = $profile->primary_contact_number;
                    $contactGrantReveal = $phone !== null && $phone !== '' ? ['phone' => $phone] : null;
                }
            }
        }

        // Profile show: always show gallery / primary photo (no blur lock for other viewers).
        $showPhotoTo = optional($visibilitySettings)->show_photo_to ?? 'all';
        $photoViewAllowed = true;
        $photoLocked = false;

        $verificationPanel = ProfileShowReadService::buildVerificationPanel($profile, $viewer, $isOwnProfile);

        return view(
            'matrimony.profile.show',
            [
                'profile' => $profile,
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
                'hasBlockingConflicts' => $hasBlockingConflicts,
                'conflictRecords' => $conflictRecords,
                'contactRequestState' => $contactRequestState,
                'contactRequestDisabled' => $contactRequestDisabled,
                'contactGrantReveal' => $contactGrantReveal,
                'canSendContactRequest' => $canSendContactRequest,
                'photoLocked' => $photoLocked,
                'photoLockMode' => $showPhotoTo ?? 'all',
                'verificationPanel' => $verificationPanel,
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
        $query = MatrimonyProfile::query();

        // Day 7: Only active profiles searchable; NULL treated as active (backward compat)
        $query->where(function ($q) {
            $q->where('lifecycle_state', 'active')->orWhereNull('lifecycle_state');
        })->where('is_suspended', false);
        // Soft deletes are automatically excluded by Laravel's SoftDeletes trait

        // Day-18: Only use enabled AND searchable fields for search
        $searchableFields = ProfileFieldConfigurationService::getSearchableFieldKeys();
        $enabledFields = ProfileFieldConfigurationService::getEnabledFieldKeys();

        // Intersection: fields that are both enabled and searchable
        $enabledSearchableFields = array_intersect($searchableFields, $enabledFields);

        // Helper: check if field is enabled and searchable
        $isSearchable = fn (string $fieldKey) => in_array($fieldKey, $enabledSearchableFields, true);

        // Religion filter (only if searchable)
        if ($isSearchable('religion_id') && $request->filled('religion_id')) {
            $query->where('religion_id', $request->input('religion_id'));
        }

        // Caste filter (only if searchable) — normalized: use caste_id
        if ($isSearchable('caste') && $request->filled('caste_id')) {
            $query->where('caste_id', $request->input('caste_id'));
        }

        // Sub-caste filter (only if searchable)
        if ($isSearchable('sub_caste_id') && $request->filled('sub_caste_id')) {
            $query->where('sub_caste_id', $request->input('sub_caste_id'));
        }

        // Phase-6: Location hierarchy filters (only if searchable)
        if ($isSearchable('location')) {
            if ($request->filled('country_id')) {
                $query->where('country_id', (int) $request->country_id);
            }
            if ($request->filled('state_id')) {
                $query->where('state_id', (int) $request->state_id);
            }
            if ($request->filled('district_id')) {
                $query->where('district_id', (int) $request->district_id);
            }
            if ($request->filled('taluka_id')) {
                $query->where('taluka_id', (int) $request->taluka_id);
            }
            if ($request->filled('city_id')) {
                $query->where('city_id', (int) $request->city_id);
            }
        }

        // Age filter from date_of_birth (only if searchable)
        if ($isSearchable('date_of_birth') && ($request->filled('age_from') || $request->filled('age_to'))) {
            $query->whereNotNull('date_of_birth');
            if ($request->filled('age_from')) {
                $minDate = now()->subYears((int) $request->age_from)->format('Y-m-d');
                $query->whereDate('date_of_birth', '<=', $minDate);
            }
            if ($request->filled('age_to')) {
                $maxDate = now()->subYears((int) $request->age_to + 1)->addDay()->format('Y-m-d');
                $query->whereDate('date_of_birth', '>=', $maxDate);
            }
        }

        // Height filter (only if searchable)
        if ($isSearchable('height_cm')) {
            if ($request->filled('height_from')) {
                $query->whereNotNull('height_cm')->where('height_cm', '>=', (int) $request->height_from);
            }
            if ($request->filled('height_to')) {
                $query->whereNotNull('height_cm')->where('height_cm', '<=', (int) $request->height_to);
            }
        }

        // Marital status filter (Phase-5: marital_status_id)
        if ($isSearchable('marital_status_id') && ($request->filled('marital_status_id') || $request->filled('marital_status'))) {
            $msId = $request->input('marital_status_id') ?: ($request->input('marital_status') === 'single'
                ? \App\Models\MasterMaritalStatus::where('key', 'never_married')->value('id')
                : \App\Models\MasterMaritalStatus::where('key', $request->input('marital_status'))->value('id'));
            if ($msId) {
                $query->where('marital_status_id', $msId);
            }
        }

        // Education filter (only if searchable) — column: highest_education
        if ($isSearchable('education') && $request->filled('education')) {
            $query->where('highest_education', $request->input('education'));
        }

        // Profession filter (only if searchable)
        if ($isSearchable('profession_id') && $request->filled('profession_id')) {
            $query->where('profession_id', $request->input('profession_id'));
        }

        // Serious intent filter (only if searchable)
        if ($isSearchable('serious_intent_id') && $request->filled('serious_intent_id')) {
            $query->where('serious_intent_id', $request->input('serious_intent_id'));
        }

        // Photo-only filter (truthful: uses core profile_photo + photo_approved flag)
        if ($request->boolean('has_photo')) {
            $query->whereNotNull('profile_photo')
                ->where(function ($q) {
                    $q->whereNull('photo_approved')->orWhere('photo_approved', 1);
                });
        }

        // Verified-only filter (truthful: email verification only)
        if ($request->boolean('verified_only')) {
            $query->whereHas('user', function ($q) {
                $q->whereNotNull('email_verified_at');
            });
        }

        // 70% completeness or admin override (search visibility only)
        $query->whereRaw(ProfileCompletenessService::sqlSearchVisible('matrimony_profiles'));

        // Admin global toggle: hide demo profiles from search when OFF (Day-8)
        $demoVisible = \App\Models\AdminSetting::getBool('demo_profiles_visible_in_search', true);
        if (! $demoVisible) {
            $query->where(function ($q) {
                $q->where('is_demo', false)->orWhereNull('is_demo');
            });
        }

        // Exclude blocked profiles (either direction) and viewer-hidden profiles when viewer has profile
        $myId = auth()->user()?->matrimonyProfile?->id;
        if ($myId) {
            $blockedIds = ViewTrackingService::getBlockedProfileIds($myId);
            $hiddenIds = HiddenProfile::query()
                ->where('owner_profile_id', $myId)
                ->pluck('hidden_profile_id');
            $excludeIds = $blockedIds->merge($hiddenIds)->unique()->values();
            if ($excludeIds->isNotEmpty()) {
                $query->whereNotIn('id', $excludeIds);
            }
        }

        $sort = (string) $request->input('sort', 'latest');
        $allowedSorts = ['latest', 'age_asc', 'age_desc', 'height_asc', 'height_desc'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'latest';
        }

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
                $q->select('id', 'profile_id', 'file_path', 'is_primary', 'approved_status')
                    ->where('approved_status', 'approved');
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
        ), $locationLookups));

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
