<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\DemoProfileDefaultsService;
use App\Services\ExtendedFieldService;
use App\Services\FieldValueHistoryService;
use App\Services\MutationService;
use App\Services\ProfileCompletenessService;
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

            // Finally: hard delete profile row.
            $profile->forceDelete();

            // If it is a system demo user, hard delete the user too.
            $owner = $profile->user;
            if ($owner && str_ends_with((string) $owner->email, '@system.local')) {
                $owner->forceDelete();
            }
        });

        return redirect()->back()->with('success', 'Showcase profile deleted.');
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
