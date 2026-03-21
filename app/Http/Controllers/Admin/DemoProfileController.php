<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\DemoProfileDefaultsService;
use App\Services\ExtendedFieldService;
use App\Services\FieldValueHistoryService;
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
        $attrs['user_id'] = $demoUser->id;
        $attrs['is_demo'] = true;
        $attrs['is_suspended'] = false;

        $profile = MatrimonyProfile::create($attrs);
        self::setLifecycleActive($profile);
        self::addPrimaryContact($profile);
        self::autofillExtendedAndHistory($profile);
        self::recordHistoryForDemo($profile);

        return redirect()
            ->route('matrimony.profile.show', $profile->id)
            ->with('success', 'Showcase profile created successfully.');
    }

    public function bulkCreate()
    {
        return view('admin.demo-profile.bulk-create');
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
            $attrs['user_id'] = $user->id;
            $attrs['is_demo'] = true;
            $attrs['is_suspended'] = false;
            $profile = MatrimonyProfile::create($attrs);
            self::setLifecycleActive($profile);
            self::addPrimaryContact($profile);
            self::autofillExtendedAndHistory($profile);
            self::recordHistoryForDemo($profile);
            $created++;
        }

        return redirect()
            ->route('admin.demo-profile.bulk-create')
            ->with('success', "Created {$created} showcase profile(s).");
    }

    /**
     * Set lifecycle_state to active so demo profile is visible (DB update to avoid model mutator).
     */
    private static function setLifecycleActive(MatrimonyProfile $profile): void
    {
        DB::table('matrimony_profiles')->where('id', $profile->id)->update(['lifecycle_state' => 'active']);
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
