<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use App\Services\Image\ProfilePhotoUrlService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Profiles whose primary photo is not approved for display (pending human review or still processing),
 * plus gallery rows (profile_photos) that are still pending review.
 */
class PhotoReviewQueueController extends Controller
{
    /**
     * Admin-only image bytes for queue preview (pending tmp file, stored matrimony photo, or a gallery row).
     */
    public function preview(Request $request, MatrimonyProfile $profile, ?ProfilePhoto $galleryPhoto = null): BinaryFileResponse
    {
        if ($galleryPhoto !== null && (int) $galleryPhoto->profile_id !== (int) $profile->id) {
            abort(404);
        }

        if ($galleryPhoto !== null) {
            $fn = ltrim((string) $galleryPhoto->file_path, '/');
            if ($fn === '') {
                abort(404);
            }
            if (ProfilePhotoUrlService::isPendingPlaceholder($fn)) {
                $abs = ProfilePhotoUrlService::resolvePendingTempAbsolutePath($fn);
                if ($abs !== null) {
                    return response()->file($abs, ['Cache-Control' => 'private, no-store']);
                }
                abort(404);
            }
            $abs = ProfilePhotoUrlService::resolveStoredPublicAbsolutePath($fn);
            if ($abs !== null) {
                return response()->file($abs, ['Cache-Control' => 'private, max-age=120']);
            }
            abort(404);
        }

        $path = trim((string) ($profile->profile_photo ?? ''));
        if ($path === '') {
            abort(404);
        }

        if (ProfilePhotoUrlService::isPendingPlaceholder($path)) {
            $abs = ProfilePhotoUrlService::resolvePendingTempAbsolutePath($path);
            if ($abs === null) {
                $abs = ProfilePhotoUrlService::resolvePendingFallbackFromPrimaryGallery($profile);
            }
            if ($abs === null) {
                abort(404);
            }

            return response()->file($abs, [
                'Cache-Control' => 'private, no-store',
            ]);
        }

        $fn = ltrim($path, '/');
        $publicAbs = ProfilePhotoUrlService::resolveStoredPublicAbsolutePath($fn);
        if ($publicAbs !== null) {
            return response()->file($publicAbs, [
                'Cache-Control' => 'private, max-age=120',
            ]);
        }

        abort(404);
    }

    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);
        $perPage = $perPage >= 5 && $perPage <= 50 ? $perPage : 20;

        $profilesPrimary = MatrimonyProfile::query()
            ->with(['user'])
            ->whereNotNull('profile_photo')
            ->where('profile_photo', '!=', '')
            ->where(function ($q) {
                $q->whereNull('photo_approved')
                    ->orWhere('photo_approved', false);
            })
            ->whereNull('photo_rejected_at')
            ->orderByDesc('updated_at')
            ->get();

        $primaryProfileIds = $profilesPrimary->pluck('id')->all();

        $items = collect();

        foreach ($profilesPrimary as $p) {
            $items->push((object) [
                'profile' => $p,
                'profilePhoto' => null,
                'kind' => 'primary',
                'sortKey' => $p->updated_at?->timestamp ?? 0,
            ]);
        }

        if (Schema::hasTable('profile_photos')) {
            $galleryPending = ProfilePhoto::query()
                ->where('approved_status', 'pending')
                ->with(['profile.user'])
                ->orderByDesc('updated_at')
                ->get();

            foreach ($galleryPending as $gp) {
                $prof = $gp->profile;
                if ($prof === null || $prof->photo_rejected_at !== null) {
                    continue;
                }
                if ($gp->is_primary && in_array((int) $gp->profile_id, $primaryProfileIds, true)) {
                    continue;
                }
                $items->push((object) [
                    'profile' => $prof,
                    'profilePhoto' => $gp,
                    'kind' => 'gallery',
                    'sortKey' => $gp->updated_at?->timestamp ?? 0,
                ]);
            }
        }

        $items = $items->sortByDesc(fn ($i) => $i->sortKey)->values();

        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $total = $items->count();
        $slice = $items->slice(($currentPage - 1) * $perPage, $perPage)->values();

        // Primary row: prefer profile snapshot; fall back to primary gallery row (ProcessProfilePhoto writes both).
        $primaryIdsOnPage = $slice->filter(fn ($i) => $i->kind === 'primary')->pluck('profile.id')->unique()->filter()->values()->all();
        $primaryGalleryScanByProfileId = collect();
        if ($primaryIdsOnPage !== [] && Schema::hasTable('profile_photos') && Schema::hasColumn('profile_photos', 'moderation_scan_json')) {
            $primaryGalleryScanByProfileId = ProfilePhoto::query()
                ->whereIn('profile_id', $primaryIdsOnPage)
                ->where('is_primary', true)
                ->get()
                ->keyBy('profile_id');
        }

        $slice = $slice->map(function ($item) use ($primaryGalleryScanByProfileId) {
            if ($item->kind === 'primary') {
                $item->primaryGalleryModerationScan = $primaryGalleryScanByProfileId->get($item->profile->id)?->moderation_scan_json;
            }

            return $item;
        });

        $paginator = new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $currentPage,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        return view('admin.photo-review-queue.index', [
            'items' => $paginator,
        ]);
    }
}
