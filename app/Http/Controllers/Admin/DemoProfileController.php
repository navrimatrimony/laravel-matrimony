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
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| DemoProfileController (SSOT)
|--------------------------------------------------------------------------
| Single + bulk demo create. All mandatory fields auto-filled. No NULLs.
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
                'name' => 'Demo Profiles',
                'password' => bcrypt(Str::random(32)),
                'gender' => 'other',
            ]
        );

        $genderOverride = $request->filled('gender') ? $request->gender : null;
        $defaults = DemoProfileDefaultsService::defaultsForDemo(0, $genderOverride);
        $profile = MatrimonyProfile::create([
            'user_id' => $demoUser->id,
            'full_name' => $defaults['full_name'],
            'gender' => $defaults['gender'],
            'date_of_birth' => $defaults['date_of_birth'],
            'marital_status' => $defaults['marital_status'],
            'highest_education' => $defaults['highest_education'],
            'caste' => $defaults['caste'],
            'height_cm' => $defaults['height_cm'] ?? null,
            'country_id' => $defaults['country_id'] ?? null,
            'state_id' => $defaults['state_id'] ?? null,
            'district_id' => $defaults['district_id'] ?? null,
            'taluka_id' => $defaults['taluka_id'] ?? null,
            'city_id' => $defaults['city_id'] ?? null,
            'profile_photo' => $defaults['profile_photo'],
            'photo_approved' => $defaults['photo_approved'],
            'is_demo' => true,
            'is_suspended' => false,
        ]);

        self::autofillExtendedAndHistory($profile);

        foreach (['full_name', 'gender', 'date_of_birth', 'marital_status', 'highest_education', 'caste', 'profile_photo', 'photo_approved', 'is_demo', 'is_suspended'] as $fieldKey) {
            $newVal = $profile->$fieldKey;
            if ($newVal instanceof \Carbon\Carbon) {
                $newVal = $newVal->format('Y-m-d');
            }
            $newVal = $newVal === '' || $newVal === null ? null : (string) $newVal;
            if ($fieldKey === 'photo_approved' || $fieldKey === 'is_demo' || $fieldKey === 'is_suspended') {
                $newVal = $newVal === null ? null : ($newVal ? '1' : '0');
            }
            FieldValueHistoryService::record($profile->id, $fieldKey, 'CORE', null, $newVal, FieldValueHistoryService::CHANGED_BY_SYSTEM);
        }

        return redirect()
            ->route('matrimony.profile.show', $profile->id)
            ->with('success', 'Demo profile created successfully.');
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

        for ($i = 0; $i < $count; $i++) {
            $email = 'demo-profile-' . Str::random(8) . '@system.local';
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => 'Demo ' . ($i + 1),
                    'password' => bcrypt(Str::random(32)),
                    'gender' => 'other',
                ]
            );
            if ($user->matrimonyProfile) {
                continue;
            }
            $defaults = DemoProfileDefaultsService::defaultsForDemo($i, $genderOverride);
            $profile = MatrimonyProfile::create([
                'user_id' => $user->id,
                'full_name' => $defaults['full_name'],
                'gender' => $defaults['gender'],
                'date_of_birth' => $defaults['date_of_birth'],
                'marital_status' => $defaults['marital_status'],
                'highest_education' => $defaults['highest_education'],
                'caste' => $defaults['caste'],
                'height_cm' => $defaults['height_cm'] ?? null,
                'country_id' => $defaults['country_id'] ?? null,
                'state_id' => $defaults['state_id'] ?? null,
                'district_id' => $defaults['district_id'] ?? null,
                'taluka_id' => $defaults['taluka_id'] ?? null,
                'city_id' => $defaults['city_id'] ?? null,
                'profile_photo' => $defaults['profile_photo'],
                'photo_approved' => $defaults['photo_approved'],
                'is_demo' => true,
                'is_suspended' => false,
            ]);
            self::autofillExtendedAndHistory($profile);
            foreach (['full_name', 'gender', 'date_of_birth', 'marital_status', 'highest_education', 'caste', 'profile_photo', 'photo_approved', 'is_demo', 'is_suspended'] as $fieldKey) {
                $newVal = $profile->$fieldKey;
                if ($newVal instanceof \Carbon\Carbon) {
                    $newVal = $newVal->format('Y-m-d');
                }
                $newVal = $newVal === '' || $newVal === null ? null : (string) $newVal;
                if ($fieldKey === 'photo_approved' || $fieldKey === 'is_demo' || $fieldKey === 'is_suspended') {
                    $newVal = $newVal === null ? null : ($newVal ? '1' : '0');
                }
                FieldValueHistoryService::record($profile->id, $fieldKey, 'CORE', null, $newVal, FieldValueHistoryService::CHANGED_BY_SYSTEM);
            }
        }

        return redirect()
            ->route('admin.demo-profile.bulk-create')
            ->with('success', "Created {$count} demo profile(s).");
    }

    /**
     * Phase-4: After demo profile create – fill extended fields from registry, then ensure completeness.
     * Uses ExtendedFieldService::saveValuesForProfile (actor null = system). No lock conflict on new profile.
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
