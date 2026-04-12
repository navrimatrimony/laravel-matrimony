<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Services\Showcase\AutoShowcaseSettings;
use App\Services\Showcase\ShowcaseProfileFactory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| DemoProfileController (SSOT)
|--------------------------------------------------------------------------
| Showcase profiles are created only via the bulk UI: each profile
| gets its own system user. Core creation lives in {@see ShowcaseProfileFactory}.
*/
class DemoProfileController extends Controller
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
                ->where('is_demo', true)
                ->orderByDesc('id')
                ->get();
        }

        $recentShowcase = MatrimonyProfile::query()
            ->where('is_demo', true)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $bulkLifecycle = AutoShowcaseSettings::bulkShowcaseLifecycle();

        return view('admin.demo-profile.bulk-create', [
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
            ->route('admin.demo-profile.bulk-create')
            ->with('success', "Created {$created} showcase profile(s) ({$lifecycle}). {$lifecycleHint}")
            ->with('created_showcase_profile_ids', $createdIds);
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

        try {
            DB::transaction(function () use ($profile) {
                $pid = (int) $profile->id;

                // Chats
                $conversationIds = DB::table('conversations')
                    ->where('profile_one_id', $pid)
                    ->orWhere('profile_two_id', $pid)
                    ->pluck('id')
                    ->map(fn ($v) => (int) $v)
                    ->all();

                if (! empty($conversationIds)) {
                    DB::table('messages')->whereIn('conversation_id', $conversationIds)->delete();
                    DB::table('conversations')->whereIn('id', $conversationIds)->delete();
                }

                // Common engagement tables (best-effort cleanup)
                if (Schema::hasTable('interests')) {
                    DB::table('interests')->where('sender_profile_id', $pid)->orWhere('receiver_profile_id', $pid)->delete();
                }
                if (Schema::hasTable('shortlists')) {
                    DB::table('shortlists')->where('owner_profile_id', $pid)->orWhere('shortlisted_profile_id', $pid)->delete();
                }
                if (Schema::hasTable('blocks')) {
                    DB::table('blocks')->where('blocker_profile_id', $pid)->orWhere('blocked_profile_id', $pid)->delete();
                }
                if (Schema::hasTable('profile_views')) {
                    DB::table('profile_views')->where('viewer_profile_id', $pid)->orWhere('viewed_profile_id', $pid)->delete();
                }
                if (Schema::hasTable('hidden_profiles')) {
                    DB::table('hidden_profiles')->where('owner_profile_id', $pid)->orWhere('hidden_profile_id', $pid)->delete();
                }

                // Profile-owned child tables (best-effort)
                if (Schema::hasTable('profile_photos')) {
                    DB::table('profile_photos')->where('profile_id', $pid)->delete();
                }
                if (Schema::hasTable('profile_contacts')) {
                    DB::table('profile_contacts')->where('profile_id', $pid)->delete();
                }
                if (Schema::hasTable('profile_preference_criteria')) {
                    DB::table('profile_preference_criteria')->where('profile_id', $pid)->delete();
                }
                foreach ([
                    'profile_preferred_religions',
                    'profile_preferred_castes',
                    'profile_preferred_districts',
                    'profile_preferred_talukas',
                    'profile_preferred_cities',
                    'profile_preferred_states',
                    'profile_preferred_educations',
                ] as $tbl) {
                    if (Schema::hasTable($tbl)) {
                        DB::table($tbl)->where('profile_id', $pid)->delete();
                    }
                }
                if (Schema::hasTable('profile_extended_attributes')) {
                    DB::table('profile_extended_attributes')->where('profile_id', $pid)->delete();
                }
                if (Schema::hasTable('profile_marriages')) {
                    DB::table('profile_marriages')->where('profile_id', $pid)->delete();
                }
                if (Schema::hasTable('profile_siblings')) {
                    DB::table('profile_siblings')->where('profile_id', $pid)->delete();
                }
                if (Schema::hasTable('profile_relatives')) {
                    DB::table('profile_relatives')->where('profile_id', $pid)->delete();
                }
                if (Schema::hasTable('profile_properties')) {
                    DB::table('profile_properties')->where('profile_id', $pid)->delete();
                }
                if (Schema::hasTable('profile_horoscopes')) {
                    DB::table('profile_horoscopes')->where('profile_id', $pid)->delete();
                }
                // Phase-5 / schema: FK restrictOnDelete on matrimony_profiles — clear before forceDelete().
                // Table => FK column (some tables use matrimony_profile_id, not profile_id).
                foreach ([
                    ['profile_change_history', 'profile_id'],
                    ['profile_field_locks', 'profile_id'],
                    ['profile_visibility_settings', 'profile_id'],
                    ['profile_preferences', 'profile_id'],
                    ['profile_education', 'profile_id'],
                    ['profile_career', 'profile_id'],
                    ['profile_children', 'profile_id'],
                    ['profile_addresses', 'profile_id'],
                    ['profile_property_summary', 'profile_id'],
                    ['profile_property_assets', 'profile_id'],
                    ['profile_horoscope_data', 'profile_id'],
                    ['profile_legal_cases', 'profile_id'],
                    ['profile_alliance_networks', 'profile_id'],
                    ['profile_kyc_submissions', 'matrimony_profile_id'],
                    ['profile_verification_tag', 'matrimony_profile_id'],
                    ['profile_verification_tag_audits', 'matrimony_profile_id'],
                ] as [$tbl, $col]) {
                    if (Schema::hasTable($tbl) && Schema::hasColumn($tbl, $col)) {
                        DB::table($tbl)->where($col, $pid)->delete();
                    }
                }

                // Finally: hard delete profile row.
                $profile->forceDelete();

                // If it is a system showcase user (@system.local), hard delete the user too.
                $owner = $profile->user;
                if ($owner && str_ends_with((string) $owner->email, '@system.local')) {
                    $owner->forceDelete();
                }
            });
        } catch (QueryException $e) {
            $state = $e->errorInfo[0] ?? '';
            $code = (int) ($e->errorInfo[1] ?? 0);
            // MySQL 1451: cannot delete parent (child FK still references row).
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
