<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Services\Maintenance\MatrimonyProfileDatabasePurger;
use App\Services\Showcase\AutoShowcaseSettings;
use App\Services\Showcase\ShowcaseBulkCreateReport;
use App\Services\Showcase\ShowcasePhotoPoolService;
use App\Services\Showcase\ShowcaseProfileCreateResult;
use App\Services\Showcase\ShowcaseProfileFactory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| ShowcaseProfileController (SSOT)
|--------------------------------------------------------------------------
| Showcase profiles are created only via the bulk UI: each profile
| gets its own system user. Core creation lives in {@see ShowcaseProfileFactory}.
*/
class ShowcaseProfileController extends Controller
{
    public function bulkCreate()
    {
        $ids = session('created_showcase_profile_ids', []);
        if (! is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $createdProfiles = collect();
        if (! empty($ids)) {
            $createdProfiles = MatrimonyProfile::query()
                ->whereIn('id', $ids)
                ->whereShowcase()
                ->orderByDesc('id')
                ->get();
        }

        $recentShowcase = MatrimonyProfile::query()
            ->whereShowcase()
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $bulkLifecycle = AutoShowcaseSettings::bulkShowcaseLifecycle();
        $bulkResult = session('showcase_bulk_result');
        if (! is_array($bulkResult)) {
            $bulkResult = null;
        }

        $noPhotoProfileIds = [];
        if (is_array($bulkResult['profile_outcomes'] ?? null)) {
            foreach ($bulkResult['profile_outcomes'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (($row['outcome'] ?? '') === ShowcaseProfileCreateResult::OUTCOME_CREATED_WITHOUT_PHOTO) {
                    $noPhotoProfileIds[(int) ($row['profile_id'] ?? 0)] = true;
                }
            }
        }

        return view('admin.showcase-profile.bulk-create', [
            'createdProfiles' => $createdProfiles,
            'recentShowcase' => $recentShowcase,
            'bulkShowcaseLifecycle' => $bulkLifecycle,
            'bulkResult' => $bulkResult,
            'photoPolicyLabels' => ShowcaseBulkCreateReport::photoPolicyLabels(),
            'noPhotoProfileIds' => array_keys(array_filter($noPhotoProfileIds)),
            'poolHealth' => app(ShowcasePhotoPoolService::class)->poolHealthSummary(),
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

        $actorUserId = (int) ($request->user()?->id ?? 0);
        $report = new ShowcaseBulkCreateReport($count);
        $factory = app(ShowcaseProfileFactory::class);
        $lifecycle = AutoShowcaseSettings::bulkShowcaseLifecycle();
        for ($i = 0; $i < $count; $i++) {
            $report->add($factory->createWithOutcome($i, $genderOverride, $actorUserId, [], $lifecycle, null, true));
        }

        $summary = $report->toSummary();
        $message = __('showcase_bulk.success_created', [
            'count' => $summary['created'],
            'lifecycle' => $lifecycle,
        ]);
        $message .= ' '.($lifecycle === 'active'
            ? __('showcase_bulk.success_lifecycle_active')
            : __('showcase_bulk.success_lifecycle_draft'));
        if ($summary['without_photo'] > 0) {
            $message .= ' '.__('showcase_bulk.success_without_photo', ['count' => $summary['without_photo']]);
        }
        if ($summary['skipped_no_photo'] > 0) {
            $message .= ' '.__('showcase_bulk.success_skipped_photo', ['count' => $summary['skipped_no_photo']]);
        }
        if ($summary['skipped_no_location'] > 0) {
            $message .= ' '.__('showcase_bulk.success_skipped_location', ['count' => $summary['skipped_no_location']]);
        }

        return redirect()
            ->route('admin.showcase-profile.bulk-create')
            ->with('success', $message)
            ->with('created_showcase_profile_ids', $report->createdProfileIds())
            ->with('showcase_bulk_result', [
                'summary' => $summary,
                'grouped_warnings' => $report->groupedPhotoWarnings(),
                'profile_outcomes' => $report->profileOutcomes(),
            ]);
    }

    public function publish(Request $request, MatrimonyProfile $profile)
    {
        if (! $profile->isShowcaseProfile()) {
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
        if (! $profile->isShowcaseProfile()) {
            abort(404);
        }

        try {
            DB::transaction(function () use ($profile) {
                $owner = $profile->user;
                MatrimonyProfileDatabasePurger::purge($profile);
                if ($owner && str_ends_with((string) $owner->email, '@system.local')) {
                    $owner->forceDelete();
                }
            });
        } catch (QueryException $e) {
            $state = $e->errorInfo[0] ?? '';
            $code = (int) ($e->errorInfo[1] ?? 0);
            if ($state === '23000' && $code === 1451) {
                $profile->delete();

                return redirect()->back()->with(
                    'info',
                    'Showcase profile archived (soft delete). A database link still blocked a full remove; the row is hidden but not purged.'
                );
            }
            throw $e;
        }

        return redirect()->back()->with('success', 'Showcase profile deleted.');
    }
}
