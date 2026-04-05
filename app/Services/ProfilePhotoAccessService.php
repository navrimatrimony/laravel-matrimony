<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Models\UserDailyPhotoProfileView;
use App\Models\UserFeatureUsage;
use App\Support\PlanFeatureKeys;
use App\Support\UserFeatureUsageKeys;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Album visibility for profile show: paid full access vs free tier blur + daily distinct-profile cap
 * for users without an approved photo on {@see ProfilePhoto} / legacy primary.
 *
 * Future: multiply blur tiers or “boost” via plan features without changing call sites.
 */
class ProfilePhotoAccessService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly UserFeatureUsageService $usage,
    ) {}

    /**
     * @return array{
     *     slots: list<array{url: string, blur: bool}>,
     *     message_key: ?string,
     *     tier: 'paid'|'free_own_photo'|'free_no_photo'|'own_profile'
     * }
     */
    public function buildAlbumPresentation(
        User $viewer,
        MatrimonyProfile $subject,
        bool $isOwnProfile,
        Collection $galleryPhotos,
    ): array {
        if ($isOwnProfile) {
            return [
                'slots' => $this->buildSlotsFromSources($subject, $galleryPhotos, allBlur: false),
                'message_key' => null,
                'tier' => 'own_profile',
            ];
        }

        if ($viewer->isAnyAdmin()) {
            return [
                'slots' => $this->buildSlotsFromSources($subject, $galleryPhotos, allBlur: false),
                'message_key' => null,
                'tier' => 'paid',
            ];
        }

        $viewerProfile = $viewer->matrimonyProfile;
        if (! $viewerProfile) {
            return [
                'slots' => $this->buildSlotsFromSources($subject, $galleryPhotos, allBlur: true),
                'message_key' => 'profile.photos_upload_to_unlock_more',
                'tier' => 'free_no_photo',
            ];
        }

        $uid = (int) $viewer->id;
        if ($this->entitlements->hasFeature($uid, PlanFeatureKeys::PHOTO_FULL_ACCESS)) {
            return [
                'slots' => $this->buildSlotsFromSources($subject, $galleryPhotos, allBlur: false),
                'message_key' => null,
                'tier' => 'paid',
            ];
        }

        $hasOwnPhoto = $this->viewerHasApprovedOwnPhoto($viewerProfile);

        if ($hasOwnPhoto) {
            $slots = $this->buildSlotsWithFirstUnblurredRestBlurred($subject, $galleryPhotos);

            return [
                'slots' => $slots,
                'message_key' => $this->hasMultipleVisibleSlots($slots)
                    ? 'profile.photos_upgrade_to_view_all'
                    : null,
                'tier' => 'free_own_photo',
            ];
        }

        $maxPerDay = (int) config('photo_access.max_profiles_per_day_without_own_photo', 5);
        if ($maxPerDay < 1) {
            $maxPerDay = 5;
        }
        $today = now()->toDateString();

        $alreadyRecorded = UserDailyPhotoProfileView::query()
            ->where('user_id', $uid)
            ->where('viewed_profile_id', $subject->id)
            ->whereDate('viewed_on', $today)
            ->exists();

        $distinctToday = (int) UserDailyPhotoProfileView::query()
            ->where('user_id', $uid)
            ->whereDate('viewed_on', $today)
            ->pluck('viewed_profile_id')
            ->unique()
            ->count();

        $blockedByDailyCap = ! $alreadyRecorded && $distinctToday >= $maxPerDay;

        if ($blockedByDailyCap) {
            $slots = $this->buildSlotsFromSources($subject, $galleryPhotos, allBlur: true);

            return [
                'slots' => $slots,
                'message_key' => $slots !== [] ? 'profile.photos_upload_to_unlock_more' : null,
                'tier' => 'free_no_photo',
            ];
        }

        if (! $alreadyRecorded) {
            DB::transaction(function () use ($uid, $subject, $today) {
                $inserted = DB::table('user_daily_photo_profile_views')->insertOrIgnore([
                    'user_id' => $uid,
                    'viewed_profile_id' => $subject->id,
                    'viewed_on' => $today,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($inserted > 0) {
                    $this->usage->incrementUsage(
                        $uid,
                        UserFeatureUsageKeys::PHOTO_VIEW,
                        1,
                        UserFeatureUsage::PERIOD_DAILY,
                    );
                }
            });
        }

        $slots = $this->buildSlotsWithFirstUnblurredRestBlurred($subject, $galleryPhotos);

        return [
            'slots' => $slots,
            'message_key' => $slots !== [] ? 'profile.photos_upload_to_unlock_more' : null,
            'tier' => 'free_no_photo',
        ];
    }

    public function viewerHasApprovedOwnPhoto(MatrimonyProfile $viewerProfile): bool
    {
        if (! empty(trim((string) ($viewerProfile->profile_photo ?? ''))) && $viewerProfile->photo_approved !== false) {
            return true;
        }

        return ProfilePhoto::query()
            ->where('profile_id', $viewerProfile->id)
            ->where('approved_status', 'approved')
            ->exists();
    }

    /**
     * @return list<array{url: string, blur: bool}>
     */
    private function buildSlotsFromSources(MatrimonyProfile $subject, Collection $galleryPhotos, bool $allBlur): array
    {
        $slots = [];
        $primaryUrl = $this->primaryPhotoPublicUrl($subject);
        if ($primaryUrl !== null) {
            $slots[] = ['url' => $primaryUrl, 'blur' => $allBlur];
        }
        foreach ($galleryPhotos as $p) {
            if (empty($p->file_path)) {
                continue;
            }
            $slots[] = [
                'url' => asset('uploads/matrimony_photos/'.$p->file_path),
                'blur' => $allBlur,
            ];
        }

        return $slots;
    }

    /**
     * @return list<array{url: string, blur: bool}>
     */
    private function buildSlotsWithFirstUnblurredRestBlurred(MatrimonyProfile $subject, Collection $galleryPhotos): array
    {
        $slots = $this->buildSlotsFromSources($subject, $galleryPhotos, allBlur: true);
        if ($slots === []) {
            return [];
        }
        $slots[0]['blur'] = false;

        return $slots;
    }

    /**
     * @param  list<array{url: string, blur: bool}>  $slots
     */
    private function hasMultipleVisibleSlots(array $slots): bool
    {
        return count($slots) > 1;
    }

    private function primaryPhotoPublicUrl(MatrimonyProfile $subject): ?string
    {
        $path = trim((string) ($subject->profile_photo ?? ''));
        if ($path === '' || $subject->photo_approved === false) {
            return null;
        }

        return asset('uploads/matrimony_photos/'.$path);
    }
}
