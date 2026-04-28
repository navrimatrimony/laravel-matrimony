<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Services\Maintenance\MatrimonyProfileDatabasePurger;
use App\Services\Showcase\AutoShowcaseSettings;
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

        return view('admin.showcase-profile.bulk-create', [
            'createdProfiles' => $createdProfiles,
            'recentShowcase' => $recentShowcase,
            'bulkShowcaseLifecycle' => $bulkLifecycle,
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
        $created = 0;
        $createdIds = [];
        $factory = app(ShowcaseProfileFactory::class);
        $lifecycle = AutoShowcaseSettings::bulkShowcaseLifecycle();
        for ($i = 0; $i < $count; $i++) {
            $newId = $factory->create($i, $genderOverride, $actorUserId, [], $lifecycle, null, true);
            if ($newId !== null) {
                $created++;
                $createdIds[] = $newId;
            }
        }

        $lifecycleHint = $lifecycle === 'active'
            ? 'Profiles are active (visible in member search if other visibility rules pass).'
            : 'Profiles are draft — publish from Showcase profiles to make them visible in member search.';

        return redirect()
            ->route('admin.showcase-profile.bulk-create')
            ->with('success', "Created {$created} showcase profile(s) ({$lifecycle}). {$lifecycleHint}")
            ->with('created_showcase_profile_ids', $createdIds);
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
