<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessProfilePhoto;
use App\Models\AdminSetting;
use App\Models\MatrimonyProfile;
use App\Models\PhotoModerationLog;
use App\Models\ProfileKycSubmission;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Services\Image\ImageProcessingService;
use App\Services\Image\ProfileGalleryPhotoDeletionService;
use App\Services\Image\ProfilePhotoPendingStateService;
use App\Services\Image\ProfilePhotoUrlService;
use App\Services\MutationService;
use App\Services\ProfileShowReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MobileProfilePhotoApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $profile = $this->ownProfile($request);
        if (! $profile instanceof MatrimonyProfile) {
            return $this->error('Profile not found.', 404);
        }

        return response()->json($this->galleryPayload($profile, 'Photos loaded.'));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $this->ownProfile($request);
        if (! $user instanceof User || ! $profile instanceof MatrimonyProfile) {
            return $this->error('Profile not found.', 404);
        }

        if (! Schema::hasTable('profile_photos')) {
            return $this->error('Photo gallery is not available.', 422, [
                'can_upload' => false,
            ]);
        }

        if (Schema::hasColumn('users', 'photo_uploads_suspended') && (bool) $user->photo_uploads_suspended) {
            return $this->error('Photo uploads have been suspended for your account.', 403, [
                'can_upload' => false,
            ]);
        }

        if ($this->profileLifecycleBlocksPhotoUpload($profile)) {
            return $this->error('Profile is locked for photo changes right now.', 422, [
                'can_upload' => false,
            ]);
        }

        $maxUploadMb = max(1, (int) AdminSetting::getValue('photo_max_upload_mb', '8'));
        $maxUploadKb = $maxUploadMb * 1024;
        $request->validate([
            'profile_photo' => ['sometimes', 'image', 'max:'.$maxUploadKb],
            'profile_photos' => ['sometimes', 'array'],
            'profile_photos.*' => ['image', 'max:'.$maxUploadKb],
        ]);

        $files = $this->uploadedPhotos($request);
        if ($files === []) {
            return $this->error('Please select at least one photo.', 422);
        }

        $maxPerProfile = max(1, (int) AdminSetting::getValue('photo_max_per_profile', '5'));
        $currentCount = $this->currentPhotoCount($profile);
        if (($currentCount + count($files)) > $maxPerProfile) {
            return $this->error(
                "You can upload up to {$maxPerProfile} photos. Delete one photo before uploading a new one.",
                422,
                [
                    'max_photos' => $maxPerProfile,
                    'current_photo_count' => $currentCount,
                ],
            );
        }

        $queued = [];
        foreach ($files as $index => $file) {
            $pending = app(ImageProcessingService::class)->enqueueProfilePhotoProcessing(
                $file,
                (int) $profile->id,
                'user_mobile',
                ProcessProfilePhoto::PRIMARY_MODE_INTAKE_CROP_PRIMARY_IF_NONE,
            );
            $queued[] = $pending;

            if ($index === 0 && $currentCount === 0) {
                $this->applyFirstPhotoPendingState($profile, $user, $pending);
            }
        }

        $profile->refresh();

        return response()->json($this->galleryPayload(
            $profile,
            count($queued) === 1
                ? 'Photo uploaded. Review is in progress.'
                : 'Photos uploaded. Review is in progress.',
            [
                'queued' => $queued,
            ],
        ));
    }

    public function makePrimary(Request $request, int $photo): JsonResponse
    {
        $profile = $this->ownProfile($request);
        if (! $profile instanceof MatrimonyProfile) {
            return $this->error('Profile not found.', 404);
        }

        $row = ProfilePhoto::query()
            ->where('id', $photo)
            ->where('profile_id', $profile->id)
            ->first();
        if (! $row instanceof ProfilePhoto) {
            return $this->error('Photo not found.', 404);
        }

        $targetApproved = $row->effectiveApprovedStatus() === 'approved';

        $priorBypass = MatrimonyProfile::$bypassGovernanceEnforcement;
        MatrimonyProfile::$bypassGovernanceEnforcement = true;
        try {
            DB::transaction(function () use ($profile, $row, $targetApproved): void {
                ProfilePhoto::query()
                    ->where('profile_id', $profile->id)
                    ->update(['is_primary' => false]);

                $row->is_primary = true;
                $row->save();

                $profile->profile_photo = $row->file_path;
                $profile->photo_approved = $targetApproved;
                $profile->photo_rejected_at = null;
                $profile->photo_rejection_reason = null;
                $profile->save();
            });
        } finally {
            MatrimonyProfile::$bypassGovernanceEnforcement = $priorBypass;
        }

        $profile->refresh();

        return response()->json($this->galleryPayload($profile, 'Primary photo updated.'));
    }

    public function destroy(Request $request, int $photo): JsonResponse
    {
        $profile = $this->ownProfile($request);
        if (! $profile instanceof MatrimonyProfile) {
            return $this->error('Profile not found.', 404);
        }

        $row = ProfilePhoto::query()
            ->where('id', $photo)
            ->where('profile_id', $profile->id)
            ->first();
        if (! $row instanceof ProfilePhoto) {
            return $this->error('Photo not found.', 404);
        }

        app(ProfileGalleryPhotoDeletionService::class)->deleteWithPrimaryResync($profile, $row);
        $profile->refresh();

        return response()->json($this->galleryPayload($profile, 'Photo deleted.'));
    }

    public function reorder(Request $request): JsonResponse
    {
        $profile = $this->ownProfile($request);
        if (! $profile instanceof MatrimonyProfile) {
            return $this->error('Profile not found.', 404);
        }

        if (! Schema::hasTable('profile_photos') || ! Schema::hasColumn('profile_photos', 'sort_order')) {
            return $this->error('Photo reorder is not available.', 422, [
                'can_reorder' => false,
            ]);
        }

        $validated = $request->validate([
            'photo_ids' => ['required', 'array'],
            'photo_ids.*' => ['integer'],
        ]);

        $photoIds = array_values(array_unique(array_map('intval', (array) $validated['photo_ids'])));
        if ($photoIds === []) {
            return $this->error('Invalid photo order.', 422);
        }

        $totalPhotos = (int) ProfilePhoto::query()
            ->where('profile_id', $profile->id)
            ->count();
        $ownedCount = (int) ProfilePhoto::query()
            ->where('profile_id', $profile->id)
            ->whereIn('id', $photoIds)
            ->count();

        if (count($photoIds) !== $totalPhotos || $ownedCount !== $totalPhotos) {
            return $this->error('Invalid photo order.', 422);
        }

        DB::transaction(function () use ($profile, $photoIds): void {
            foreach ($photoIds as $index => $id) {
                ProfilePhoto::query()
                    ->where('profile_id', $profile->id)
                    ->where('id', $id)
                    ->update(['sort_order' => $index]);
            }
        });

        return response()->json($this->galleryPayload($profile, 'Photo order updated.'));
    }

    public function verificationStatus(Request $request): JsonResponse
    {
        $profile = $this->ownProfile($request);
        if (! $profile instanceof MatrimonyProfile) {
            return $this->error('Profile not found.', 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Verification status loaded.',
            'verification' => $this->verificationPayload($profile),
        ]);
    }

    private function galleryPayload(MatrimonyProfile $profile, string $message, array $extra = []): array
    {
        $photos = $this->photoRows($profile);
        $maxPerProfile = max(1, (int) AdminSetting::getValue('photo_max_per_profile', '5'));
        $canReorder = Schema::hasTable('profile_photos')
            && Schema::hasColumn('profile_photos', 'sort_order')
            && count(array_filter($photos, fn ($photo) => $photo['id'] !== null)) > 1;

        return array_merge([
            'success' => true,
            'message' => $message,
            'photos' => $photos,
            'meta' => [
                'max_photos' => $maxPerProfile,
                'photo_count' => count(array_filter($photos, fn ($photo) => $photo['id'] !== null)),
                'remaining_slots' => max(0, $maxPerProfile - $this->currentPhotoCount($profile)),
                'can_upload' => ! $this->profileLifecycleBlocksPhotoUpload($profile),
                'can_reorder' => $canReorder,
                'approval_required' => \App\Services\Admin\AdminSettingService::isPhotoApprovalRequired(),
            ],
            'verification' => $this->verificationPayload($profile),
            'profile' => $this->profilePhotoSummary($profile),
        ], $extra);
    }

    private function photoRows(MatrimonyProfile $profile): array
    {
        $rows = [];
        if (Schema::hasTable('profile_photos')) {
            $query = ProfilePhoto::query()
                ->where('profile_id', $profile->id)
                ->orderByDesc('is_primary');

            if (Schema::hasColumn('profile_photos', 'sort_order')) {
                $query->orderBy('sort_order');
            } else {
                $query->orderByDesc('created_at');
            }

            $photos = $query->orderBy('id')->get();

            foreach ($photos as $photo) {
                $rows[] = $this->photoPayload($profile, $photo);
            }
        }

        $corePhoto = trim((string) ($profile->profile_photo ?? ''));
        if ($corePhoto !== '' && ProfilePhotoUrlService::isPendingPlaceholder($corePhoto)) {
            $hasPendingCore = collect($rows)->contains(
                fn ($row) => (string) ($row['file_path'] ?? '') === $corePhoto,
            );
            if (! $hasPendingCore) {
                array_unshift($rows, [
                    'id' => null,
                    'url' => null,
                    'thumbnail_url' => null,
                    'file_path' => $corePhoto,
                    'status' => 'pending',
                    'is_primary' => true,
                    'sort_order' => null,
                    'rejection_reason' => null,
                    'uploaded_at' => $profile->updated_at?->toIso8601String(),
                    'can_set_primary' => false,
                    'can_delete' => false,
                    'can_reorder' => false,
                    'message' => 'Photo processing is in progress.',
                ]);
            }
        }

        return $rows;
    }

    private function photoPayload(MatrimonyProfile $profile, ProfilePhoto $photo): array
    {
        $status = $photo->effectiveApprovedStatus();
        $url = null;
        $path = ProfilePhotoUrlService::normalizeMatrimonyPhotoPath((string) $photo->file_path);
        if ($path !== null && ! ProfilePhotoUrlService::isPendingPlaceholder($path)) {
            $url = app(ProfilePhotoUrlService::class)->publicUrl($path, $profile);
        }

        return [
            'id' => (int) $photo->id,
            'url' => $url,
            'thumbnail_url' => $url,
            'file_path' => $photo->file_path,
            'status' => $status,
            'is_primary' => (bool) $photo->is_primary,
            'sort_order' => Schema::hasColumn('profile_photos', 'sort_order') ? (int) ($photo->sort_order ?? 0) : null,
            'rejection_reason' => $this->rejectionReason($profile, $photo, $status),
            'uploaded_at' => $photo->created_at?->toIso8601String(),
            'can_set_primary' => ! (bool) $photo->is_primary,
            'can_delete' => true,
            'can_reorder' => Schema::hasColumn('profile_photos', 'sort_order'),
        ];
    }

    private function verificationPayload(MatrimonyProfile $profile): array
    {
        $user = $profile->user ?: User::query()->find($profile->user_id);
        $panel = ProfileShowReadService::buildVerificationPanel($profile, $user, true);

        return [
            'profile' => [
                'profile_id' => (int) $profile->id,
                'lifecycle_state' => $profile->lifecycle_state,
                'photo_status' => $this->profilePhotoStatus($profile),
                'photo_approved' => (bool) ($profile->photo_approved ?? false),
                'photo_rejection_reason' => $profile->photo_rejection_reason,
            ],
            'account' => [
                'email_verified' => $user?->email_verified_at !== null,
                'mobile_verified' => $user?->mobile_verified_at !== null,
            ],
            'photo_summary' => $this->photoSummary($profile),
            'kyc' => $this->kycPayload($profile),
            'verification_tags' => [
                'verified' => $this->panelRows($panel['verified'] ?? []),
                'unverified' => $this->panelRows($panel['unverified'] ?? []),
            ],
        ];
    }

    private function profilePhotoSummary(MatrimonyProfile $profile): array
    {
        $url = null;
        $path = ProfilePhotoUrlService::normalizeMatrimonyPhotoPath((string) ($profile->profile_photo ?? ''));
        if ($path !== null && ! ProfilePhotoUrlService::isPendingPlaceholder($path)) {
            $url = app(ProfilePhotoUrlService::class)->publicUrl($path, $profile);
        }

        return [
            'profile_photo' => $profile->profile_photo,
            'profile_photo_url' => $url,
            'photo_status' => $this->profilePhotoStatus($profile),
            'photo_approved' => (bool) ($profile->photo_approved ?? false),
            'photo_rejection_reason' => $profile->photo_rejection_reason,
        ];
    }

    private function photoSummary(MatrimonyProfile $profile): array
    {
        $counts = [
            'approved' => 0,
            'pending' => 0,
            'rejected' => 0,
            'total' => 0,
        ];

        if (Schema::hasTable('profile_photos')) {
            ProfilePhoto::query()
                ->where('profile_id', $profile->id)
                ->get()
                ->each(function (ProfilePhoto $photo) use (&$counts): void {
                    $status = $photo->effectiveApprovedStatus();
                    $counts['total']++;
                    if (isset($counts[$status])) {
                        $counts[$status]++;
                    }
                });
        }

        return $counts;
    }

    private function kycPayload(MatrimonyProfile $profile): array
    {
        if (! Schema::hasTable('profile_kyc_submissions')) {
            return [
                'available' => false,
                'editable' => false,
                'status' => null,
                'message' => 'KYC status is not available.',
            ];
        }

        $latest = ProfileKycSubmission::query()
            ->where('matrimony_profile_id', $profile->id)
            ->orderByDesc('id')
            ->first();

        if (! $latest instanceof ProfileKycSubmission) {
            return [
                'available' => true,
                'editable' => false,
                'status' => 'not_submitted',
                'message' => 'KYC has not been submitted.',
            ];
        }

        return [
            'available' => true,
            'editable' => false,
            'status' => $latest->status,
            'submitted_at' => $latest->created_at?->toIso8601String(),
            'reviewed_at' => $latest->reviewed_at?->toIso8601String(),
            'message' => $this->kycMessage((string) $latest->status),
        ];
    }

    private function uploadedPhotos(Request $request): array
    {
        $files = [];
        $primary = $request->file('profile_photo');
        if ($primary instanceof UploadedFile) {
            $files[] = $primary;
        }

        $additional = $request->file('profile_photos', []);
        if ($additional instanceof UploadedFile) {
            $files[] = $additional;
        } elseif (is_array($additional)) {
            foreach ($additional as $file) {
                if ($file instanceof UploadedFile) {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    private function applyFirstPhotoPendingState(MatrimonyProfile $profile, User $user, string $pending): void
    {
        $snapshot = [
            'core' => [
                'profile_photo' => $pending,
            ],
        ];
        app(MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');
        app(ProfilePhotoPendingStateService::class)->applyPendingReviewState($profile);
    }

    private function currentPhotoCount(MatrimonyProfile $profile): int
    {
        $count = Schema::hasTable('profile_photos')
            ? (int) ProfilePhoto::query()->where('profile_id', $profile->id)->count()
            : 0;

        $corePhoto = trim((string) ($profile->profile_photo ?? ''));
        if ($count === 0 && $corePhoto !== '') {
            return 1;
        }

        return $count;
    }

    private function ownProfile(Request $request): ?MatrimonyProfile
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return null;
        }

        return MatrimonyProfile::query()
            ->where('user_id', $user->id)
            ->first();
    }

    private function profileLifecycleBlocksPhotoUpload(MatrimonyProfile $profile): bool
    {
        return in_array($profile->lifecycle_state, [
            'intake_uploaded',
            'awaiting_user_approval',
            'approved_pending_mutation',
            'conflict_pending',
        ], true);
    }

    private function profilePhotoStatus(MatrimonyProfile $profile): string
    {
        if ($profile->photo_rejected_at !== null || trim((string) $profile->photo_rejection_reason) !== '') {
            return 'rejected';
        }
        if (trim((string) ($profile->profile_photo ?? '')) === '') {
            return 'missing';
        }
        if ((bool) ($profile->photo_approved ?? false)) {
            return 'approved';
        }

        return 'pending';
    }

    private function rejectionReason(MatrimonyProfile $profile, ProfilePhoto $photo, string $status): ?string
    {
        if ($status !== 'rejected') {
            return null;
        }

        if ((bool) $photo->is_primary && trim((string) $profile->photo_rejection_reason) !== '') {
            return (string) $profile->photo_rejection_reason;
        }

        if (! Schema::hasTable('photo_moderation_logs')) {
            return null;
        }

        $reason = PhotoModerationLog::query()
            ->where('photo_id', $photo->id)
            ->where('new_status', 'rejected')
            ->orderByDesc('created_at')
            ->value('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }

    private function kycMessage(string $status): string
    {
        return match ($status) {
            ProfileKycSubmission::STATUS_APPROVED => 'KYC is approved.',
            ProfileKycSubmission::STATUS_REJECTED => 'KYC was rejected. Please use the website for resubmission.',
            ProfileKycSubmission::STATUS_PENDING => 'KYC review is pending.',
            default => 'KYC status is available.',
        };
    }

    private function panelRows(array $rows): array
    {
        return array_values(array_map(
            fn ($row) => [
                'key' => (string) ($row['key'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
            ],
            $rows,
        ));
    }

    private function error(string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'success' => false,
            'message' => $message,
        ], $extra), $status);
    }
}
