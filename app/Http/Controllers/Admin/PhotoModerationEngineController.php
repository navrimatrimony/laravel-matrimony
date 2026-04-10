<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\PhotoModerationLog;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Models\UserModerationStat;
use App\Services\Admin\ModerationAlertService;
use App\Services\Admin\PhotoModerationAiReasonPresenter;
use App\Services\Admin\PhotoModerationAdminService;
use App\Services\Admin\PhotoModerationAuditTrailPresenter;
use App\Services\Admin\PhotoModerationIndexFilterApplier;
use App\Services\Admin\PhotoModerationStoredScan;
use App\Services\Admin\PhotoModerationRejectReasonSuggest;
use App\Services\Admin\PhotoModerationScanPresenter;
use App\Services\Image\ProfilePhotoUrlService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PhotoModerationEngineController extends Controller
{
    public function __construct(
        private readonly PhotoModerationAdminService $moderationAdmin,
    ) {}

    /**
     * Admin-only image bytes (pending tmp, stored matrimony photo, or gallery row).
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
        if (! Schema::hasTable('profile_photos')) {
            abort(503, 'Gallery table not available.');
        }

        $perPage = (int) $request->input('per_page', 30);
        $perPage = $perPage >= 10 && $perPage <= 100 ? $perPage : 30;
        $includeApproved = $request->boolean('include_approved');
        $flaggedUsersOnly = $request->boolean('flagged_users');

        $query = ProfilePhoto::query()
            ->with(['profile.user'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        PhotoModerationIndexFilterApplier::apply($query, $request);

        $photos = $query->paginate($perPage)->withQueryString();

        $statsByUserId = collect();
        if (Schema::hasTable('user_moderation_stats')) {
            $userIds = $photos->getCollection()
                ->pluck('profile.user_id')
                ->filter()
                ->unique()
                ->values();
            if ($userIds->isNotEmpty()) {
                $statsByUserId = UserModerationStat::query()
                    ->whereIn('user_id', $userIds->all())
                    ->get()
                    ->keyBy('user_id');
            }
        }

        $flaggedUsersOnPage = 0;
        if ($statsByUserId->isNotEmpty()) {
            $flaggedUsersOnPage = $photos->getCollection()
                ->pluck('profile.user_id')
                ->filter()
                ->unique()
                ->filter(fn ($uid) => (bool) ($statsByUserId->get($uid)?->is_flagged))
                ->count();
        }

        $moderationListRiskMessage = app(ModerationAlertService::class)
            ->moderationListHighRiskMessage($flaggedUsersOnPage);

        $logsByPhotoId = collect();
        if (Schema::hasTable('photo_moderation_logs') && $photos->isNotEmpty()) {
            $ids = $photos->getCollection()->pluck('id')->all();
            $logsByPhotoId = PhotoModerationLog::query()
                ->whereIn('photo_id', $ids)
                ->orderByDesc('id')
                ->get()
                ->groupBy('photo_id')
                ->map(fn (Collection $g) => $g->take(12)->values());
        }

        return view('admin.photo-moderation.index', [
            'photos' => $photos,
            'includeApproved' => $includeApproved,
            'flaggedUsersOnly' => $flaggedUsersOnly,
            'statsByUserId' => $statsByUserId,
            'moderationListRiskMessage' => $moderationListRiskMessage,
            'logsByPhotoId' => $logsByPhotoId,
            'indexPreserveQuery' => $this->moderationIndexPreserveQuery($request),
        ]);
    }

    public function panelFragment(ProfilePhoto $profilePhoto): View
    {
        $profilePhoto->load(['profile.user']);
        $profile = $profilePhoto->profile;
        abort_if($profile === null, 404);

        $scan = PhotoModerationStoredScan::asArray($profilePhoto->moderation_scan_json);
        $headline = PhotoModerationScanPresenter::headline($scan);
        $aiExplain = PhotoModerationAiReasonPresenter::explain($scan);
        $unsafe = PhotoModerationAdminService::moderationScanIndicatesUnsafe($scan);

        $logs = collect();
        if (Schema::hasTable('photo_moderation_logs')) {
            $logs = PhotoModerationLog::query()
                ->where('photo_id', $profilePhoto->id)
                ->orderByDesc('id')
                ->limit(30)
                ->get();
        }

        $timeline = PhotoModerationAuditTrailPresenter::timeline($profilePhoto, $logs);
        $previewUrl = route('admin.photo-moderation.preview', ['profile' => $profile, 'galleryPhoto' => $profilePhoto]);
        $detections = PhotoModerationScanPresenter::detectionSummary($scan);

        return view('admin.photo-moderation.panel', [
            'photo' => $profilePhoto,
            'headline' => $headline,
            'aiExplain' => $aiExplain,
            'unsafe' => $unsafe,
            'logs' => $logs,
            'timeline' => $timeline,
            'previewUrl' => $previewUrl,
            'detections' => $detections,
        ]);
    }

    public function show(ProfilePhoto $profilePhoto)
    {
        $profilePhoto->load(['profile.user']);
        $scan = PhotoModerationStoredScan::asArray($profilePhoto->moderation_scan_json);
        $headline = PhotoModerationScanPresenter::headline($scan);
        $detections = PhotoModerationScanPresenter::detectionSummary($scan);
        $unsafe = PhotoModerationAdminService::moderationScanIndicatesUnsafe($scan);
        $aiExplain = PhotoModerationAiReasonPresenter::explain($scan);

        $logs = collect();
        if (Schema::hasTable('photo_moderation_logs')) {
            $logs = PhotoModerationLog::query()
                ->where('photo_id', $profilePhoto->id)
                ->orderByDesc('id')
                ->limit(40)
                ->get();
        }

        $profile = $profilePhoto->profile;
        abort_if($profile === null, 404);
        $previewUrl = route('admin.photo-moderation.preview', ['profile' => $profile, 'galleryPhoto' => $profilePhoto]);
        $rejectSuggestion = PhotoModerationRejectReasonSuggest::fromScan($scan);

        return view('admin.photo-moderation.show', [
            'photo' => $profilePhoto,
            'headline' => $headline,
            'detections' => $detections,
            'unsafe' => $unsafe,
            'logs' => $logs,
            'previewUrl' => $previewUrl,
            'rejectSuggestion' => $rejectSuggestion,
            'aiExplain' => $aiExplain,
        ]);
    }

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'photo_ids' => ['required', 'array', 'min:1'],
            'photo_ids.*' => ['integer'],
            'action' => ['required', 'in:approve,move_to_review,reject,delete'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $warnings = $this->moderationAdmin->applyBulk(
            $data['photo_ids'],
            $data['action'],
            $request->user(),
            $data['reason'],
        );

        $msg = 'Bulk moderation applied.';
        if ($warnings !== []) {
            $msg .= ' Notes: '.implode(' ', $warnings);
        }

        return redirect()
            ->route('admin.photo-moderation.index', $this->moderationIndexPreserveQuery($request))
            ->with($warnings !== [] ? 'warning' : 'success', $msg);
    }

    public function suspendUserPhotoUploads(User $user): RedirectResponse
    {
        if (! Schema::hasColumn('users', 'photo_uploads_suspended')) {
            return redirect()->back()->with('error', 'Upload suspension is not available.');
        }

        $user->forceFill(['photo_uploads_suspended' => true])->save();

        return redirect()->back()->with('success', 'Photo uploads suspended for this user.');
    }

    public function singleAction(Request $request, ProfilePhoto $profilePhoto): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,move_to_review,reject,delete'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            '_return' => ['nullable', 'string', 'max:2048'],
        ]);

        $returnTo = $this->safeInternalUrl($data['_return'] ?? null);

        try {
            $this->moderationAdmin->applyPhotoAction(
                $profilePhoto,
                $data['action'],
                $request->user(),
                $data['reason'],
            );
        } catch (ValidationException $e) {
            if ($returnTo !== null) {
                return redirect()
                    ->to($returnTo)
                    ->withErrors($e->errors())
                    ->withInput();
            }

            return redirect()
                ->route('admin.photo-moderation.show', $profilePhoto)
                ->withErrors($e->errors())
                ->withInput();
        }

        if ($data['action'] === 'delete') {
            if ($returnTo !== null) {
                return redirect()
                    ->to($returnTo)
                    ->with('success', 'Photo deleted.');
            }

            return redirect()
                ->route('admin.photo-moderation.index', $this->moderationIndexPreserveQuery($request))
                ->with('success', 'Photo deleted.');
        }

        if ($returnTo !== null) {
            return redirect()
                ->to($returnTo)
                ->with('success', 'Photo updated.');
        }

        return redirect()
            ->route('admin.photo-moderation.show', $profilePhoto->fresh())
            ->with('success', 'Photo updated.');
    }

    /**
     * @return array<string, string|int>
     */
    private function moderationIndexPreserveQuery(Request $request): array
    {
        $out = [
            'per_page' => (int) $request->input('per_page', 30),
        ];
        if ($request->boolean('include_approved')) {
            $out['include_approved'] = '1';
        }
        if ($request->boolean('flagged_users')) {
            $out['flagged_users'] = '1';
        }
        if ($request->boolean('new_only')) {
            $out['new_only'] = '1';
        }
        if ($request->boolean('old_only')) {
            $out['old_only'] = '1';
        }
        foreach (['eff_status', 'ai_result', 'risk_band', 'date_preset', 'date_from', 'date_to'] as $key) {
            $v = $request->input($key);
            if ($v !== null && $v !== '') {
                $out[$key] = (string) $v;
            }
        }

        return $out;
    }

    private function safeInternalUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $base = rtrim((string) url('/'), '/');
        if (! str_starts_with($url, $base.'/') && $url !== $base) {
            return null;
        }

        return $url;
    }
}
