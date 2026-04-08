<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Services\DemoProfileDefaultsService;
use App\Services\ExtendedFieldService;
use App\Services\FieldValueHistoryService;
use App\Services\MutationService;
use App\Services\ProfileCompletenessService;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| DemoProfileController (SSOT)
|--------------------------------------------------------------------------
| Single + bulk demo create. All profile fields filled with realistic data.
| No "demo" labels; data looks like real users for manual testing.
*/
class DemoProfileController extends Controller
{
    public function create()
    {
        return view('admin.demo-profile.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'demo_profile' => 'required|accepted',
            'gender' => 'nullable|in:male,female',
        ]);

        $demoUser = User::firstOrCreate(
            ['email' => 'demo-profiles@system.local'],
            [
                'name' => 'Showcase Profiles',
                'password' => bcrypt(Str::random(32)),
                'gender' => 'other',
            ]
        );

        $genderOverride = $request->filled('gender') ? $request->gender : null;
        $attrs = DemoProfileDefaultsService::fullAttributesForDemoProfile(0, $genderOverride);
        if (empty($attrs['district_id'] ?? null) || empty($attrs['city_id'] ?? null)) {
            return back()->with('error', 'Cannot create showcase profile: no eligible district/city found from real-user districts.');
        }
        $attrs['user_id'] = $demoUser->id;
        $attrs['is_demo'] = true;
        $attrs['is_suspended'] = false;
        $attrs['lifecycle_state'] = 'draft';

        $profile = MatrimonyProfile::create($attrs);
        self::addPrimaryContact($profile);
        self::autofillExtendedAndHistory($profile);
        self::applyWizardLikeNarrativeAndPreferences($profile, (int) ($request->user()?->id ?? 0));
        self::recordHistoryForDemo($profile);

        return redirect()
            ->route('admin.demo-profile.bulk-create')
            ->with('success', 'Showcase profile created as draft. Publish it to make it visible in member search.')
            ->with('created_demo_profile_ids', [$profile->id]);
    }

    public function bulkCreate()
    {
        $ids = session('created_demo_profile_ids', []);
        if (! is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $createdProfiles = collect();
        if (! empty($ids)) {
            $createdProfiles = MatrimonyProfile::query()
                ->whereIn('id', $ids)
                ->where('is_demo', true)
                ->orderByDesc('id')
                ->get();
        }

        $recentDrafts = MatrimonyProfile::query()
            ->where('is_demo', true)
            ->where('lifecycle_state', 'draft')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('admin.demo-profile.bulk-create', [
            'createdProfiles' => $createdProfiles,
            'recentDrafts' => $recentDrafts,
        ]);
    }

    public function bulkStore(Request $request)
    {
        $request->validate([
            'count' => 'required|integer|min:1|max:50',
            'gender' => 'nullable|in:male,female,random',
        ]);
        $count = (int) $request->count;
        $genderChoice = $request->input('gender', 'random');
        $genderOverride = ($genderChoice !== 'random' && $genderChoice !== null && $genderChoice !== '')
            ? $genderChoice
            : null;

        $created = 0;
        $createdIds = [];
        for ($i = 0; $i < $count; $i++) {
            $email = 'demo-profile-' . Str::random(8) . '@system.local';
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => 'Showcase ' . ($i + 1),
                    'password' => bcrypt(Str::random(32)),
                    'gender' => 'other',
                ]
            );
            if ($user->matrimonyProfile) {
                continue;
            }
            $attrs = DemoProfileDefaultsService::fullAttributesForDemoProfile($i, $genderOverride);
            if (empty($attrs['district_id'] ?? null) || empty($attrs['city_id'] ?? null)) {
                continue;
            }
            $attrs['user_id'] = $user->id;
            $attrs['is_demo'] = true;
            $attrs['is_suspended'] = false;
            $attrs['lifecycle_state'] = 'draft';
            $profile = MatrimonyProfile::create($attrs);
            self::addPrimaryContact($profile);
            self::autofillExtendedAndHistory($profile);
            self::applyWizardLikeNarrativeAndPreferences($profile, (int) ($request->user()?->id ?? 0));
            self::recordHistoryForDemo($profile);
            $created++;
            $createdIds[] = (int) $profile->id;
        }

        return redirect()
            ->route('admin.demo-profile.bulk-create')
            ->with('success', "Created {$created} showcase profile(s) as draft. Publish them to make visible in member search.")
            ->with('created_demo_profile_ids', $createdIds);
    }

    public function publish(Request $request, MatrimonyProfile $profile)
    {
        if (! ($profile->is_demo ?? false)) {
            abort(404);
        }

        DB::table('matrimony_profiles')
            ->where('id', $profile->id)
            ->update([
                'lifecycle_state' => 'active',
                'is_suspended' => 0,
            ]);

        return redirect()->back()->with('success', 'Showcase profile published (now visible in member search).');
    }

    public function delete(Request $request, MatrimonyProfile $profile)
    {
        if (! ($profile->is_demo ?? false)) {
            abort(404);
        }

        $profile->delete();

        return redirect()->back()->with('success', 'Showcase profile deleted.');
    }

    public function photos(Request $request, MatrimonyProfile $profile)
    {
        if (! ($profile->is_demo ?? false)) {
            abort(404);
        }

        $galleryPhotosQuery = ProfilePhoto::query()->where('profile_id', $profile->id);
        if (Schema::hasColumn('profile_photos', 'sort_order')) {
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

        return view('admin.demo-profile.photos', [
            'profile' => $profile,
            'galleryPhotos' => $galleryPhotos,
            'photoApprovalRequired' => $photoApprovalRequired,
            'photoMaxPerProfile' => $photoMaxPerProfile,
            'currentPhotoCount' => $currentPhotoCount,
            'photoSlotsRemaining' => $photoSlotsRemaining,
            'photoLimitReached' => $photoLimitReached,
        ]);
    }

    public function storePhotos(Request $request, MatrimonyProfile $profile)
    {
        if (! ($profile->is_demo ?? false)) {
            abort(404);
        }

        $maxUploadMb = (int) \App\Models\AdminSetting::getValue('photo_max_upload_mb', '8');
        $maxUploadKb = max(1, $maxUploadMb) * 1024;
        $request->validate([
            'profile_photo' => 'required|image|max:'.$maxUploadKb,
            'profile_photos' => 'sometimes|array',
            'profile_photos.*' => 'image|max:'.$maxUploadKb,
        ]);

        if (in_array($profile->lifecycle_state, [
            'intake_uploaded', 'awaiting_user_approval', 'approved_pending_mutation', 'conflict_pending',
        ], true)) {
            return redirect()->back()->with('error', __('common.profile_edit_blocked_intake_conflict'));
        }

        $maxPerProfile = (int) \App\Models\AdminSetting::getValue('photo_max_per_profile', '5');
        $maxEdgePx = (int) \App\Models\AdminSetting::getValue('photo_max_edge_px', '1200');
        $maxEdgePx = max(400, $maxEdgePx);

        /** @var UploadedFile $primaryFile */
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
            return redirect()->back()
                ->withErrors(['profile_photos' => "You have already used all {$maxPerProfile} photo slots. Delete one photo before uploading a new one."])
                ->withInput();
        }

        $mainBecomesPrimary = $existingPhotosCount === 0;

        $targetDir = public_path('uploads/matrimony_photos');
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $storeUploadedPhoto = function (UploadedFile $file, int $idx) use ($targetDir, $maxEdgePx): string {
            $originalName = basename((string) ($file->getClientOriginalName() ?: 'photo'));
            $slug = pathinfo($originalName, PATHINFO_FILENAME);
            $rand = bin2hex(random_bytes(3));
            $baseName = time().'_'.$idx.'_'.$rand.'_'.$slug;

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

                if (is_file($webpPath) && filesize($webpPath) > 200 * 1024) {
                    $tmpImage = @imagecreatefromstring(file_get_contents($webpPath));
                    if ($tmpImage !== false) {
                        imagewebp($tmpImage, $webpPath, 70);
                        imagedestroy($tmpImage);
                    }
                }

                return $webpFilename;
            }

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

        $sortBase = -1;
        if (Schema::hasColumn('profile_photos', 'sort_order')) {
            $maxSortOrder = ProfilePhoto::query()
                ->where('profile_id', $profile->id)
                ->max('sort_order');
            $sortBase = $maxSortOrder !== null ? (int) $maxSortOrder : -1;
        }

        $hasSort = Schema::hasColumn('profile_photos', 'sort_order');
        $sortFieldsMain = $hasSort ? ['sort_order' => $sortBase + 1] : [];

        ProfilePhoto::create([
            'profile_id' => $profile->id,
            'file_path' => $primaryFilename,
            'is_primary' => $mainBecomesPrimary,
            'uploaded_via' => 'admin_showcase',
            'approved_status' => $approvedStatus,
            'watermark_detected' => false,
        ] + $sortFieldsMain);

        if (! empty($additionalFilenames)) {
            foreach (array_values($additionalFilenames) as $i => $filename) {
                $sortFieldsAdditional = $hasSort ? ['sort_order' => $sortBase + 2 + (int) $i] : [];
                ProfilePhoto::create([
                    'profile_id' => $profile->id,
                    'file_path' => $filename,
                    'is_primary' => false,
                    'uploaded_via' => 'admin_showcase',
                    'approved_status' => $approvedStatus,
                    'watermark_detected' => false,
                ] + $sortFieldsAdditional);
            }
        }

        if ($mainBecomesPrimary) {
            $priorBypass = \App\Models\MatrimonyProfile::$bypassGovernanceEnforcement;
            \App\Models\MatrimonyProfile::$bypassGovernanceEnforcement = true;
            try {
                $profile->profile_photo = $primaryFilename;
                $profile->photo_approved = $photoApproved;
                $profile->photo_rejected_at = null;
                $profile->photo_rejection_reason = null;
                $profile->save();
            } finally {
                \App\Models\MatrimonyProfile::$bypassGovernanceEnforcement = $priorBypass;
            }
        }

        $uploadedCount = 1 + (is_array($additionalFiles) ? count($additionalFiles) : 0);

        return redirect()->route('admin.demo-profile.photos', $profile->id)
            ->with('success', "Photos uploaded successfully ({$uploadedCount}).");
    }

    /**
     * Add primary profile_contact (realistic Indian mobile) for the demo profile.
     * Uses contact_relation_id (master_contact_relations) when relation_type column was replaced.
     */
    private static function addPrimaryContact(MatrimonyProfile $profile): void
    {
        $phone = DemoProfileDefaultsService::randomPrimaryPhone();
        $contactRelationId = null;
        if (Schema::hasColumn('profile_contacts', 'contact_relation_id')) {
            $contactRelationId = DB::table('master_contact_relations')->where('key', 'self')->value('id');
        }
        $row = [
            'profile_id' => $profile->id,
            'contact_name' => $profile->full_name,
            'phone_number' => $phone,
            'is_primary' => true,
            'visibility_rule' => 'unlock_only',
            'verified_status' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if ($contactRelationId !== null) {
            $row['contact_relation_id'] = $contactRelationId;
        }
        if (Schema::hasColumn('profile_contacts', 'relation_type')) {
            $row['relation_type'] = 'self';
        }
        DB::table('profile_contacts')->insert($row);
    }

    /**
     * Record core field history for demo profile (system change).
     */
    private static function recordHistoryForDemo(MatrimonyProfile $profile): void
    {
        $coreKeys = [
            'full_name', 'gender_id', 'date_of_birth', 'marital_status_id', 'highest_education',
            'religion_id', 'caste_id', 'sub_caste_id', 'height_cm', 'profile_photo', 'photo_approved',
            'is_demo', 'is_suspended', 'specialization', 'occupation_title', 'company_name',
            'annual_income', 'family_income', 'father_name', 'mother_name',
        ];
        foreach ($coreKeys as $fieldKey) {
            if (!isset($profile->$fieldKey)) {
                continue;
            }
            $newVal = $profile->$fieldKey;
            if ($newVal instanceof \Carbon\Carbon) {
                $newVal = $newVal->format('Y-m-d');
            }
            $newVal = $newVal === '' || $newVal === null ? null : (string) $newVal;
            if (in_array($fieldKey, ['photo_approved', 'is_demo', 'is_suspended'], true)) {
                $newVal = $newVal === null ? null : ($newVal ? '1' : '0');
            }
            FieldValueHistoryService::record($profile->id, $fieldKey, 'CORE', null, $newVal, FieldValueHistoryService::CHANGED_BY_SYSTEM);
        }
    }

    /**
     * Ensure demo profiles look like "Step 1–7" completion (excluding location/address for now):
     * - about-me narrative (profile_extended_attributes)
     * - partner preferences (profile_preference_criteria + pivots)
     */
    private static function applyWizardLikeNarrativeAndPreferences(MatrimonyProfile $profile, int $actorUserId): void
    {
        $snapshot = DemoProfileDefaultsService::postCreateSnapshotForDemoProfile($profile->fresh());

        // Apply in one snapshot so MutationService syncs tables consistently.
        app(MutationService::class)->applyManualSnapshot(
            $profile->fresh(),
            [
                'extended_narrative' => $snapshot['extended_narrative'] ?? [],
                'preferences' => $snapshot['preferences'] ?? [],
            ],
            $actorUserId > 0 ? $actorUserId : 0,
            'manual'
        );
    }

    /**
     * Phase-4: After demo profile create – fill extended fields from registry, then ensure completeness.
     */
    private static function autofillExtendedAndHistory(MatrimonyProfile $profile): void
    {
        $extended = DemoProfileDefaultsService::extendedDefaultsForProfile();
        if (!empty($extended)) {
            ExtendedFieldService::saveValuesForProfile($profile, $extended, null);
        }
        $pct = ProfileCompletenessService::percentage($profile);
        if ($pct < 80) {
            \Log::info('Demo profile autofill: completeness ' . $pct . '% for profile ' . $profile->id . ' (target ≥80%).');
        }
    }
}
