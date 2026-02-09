<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\DemoProfileDefaultsService;
use App\Services\FieldValueHistoryService;
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
        $defaults = DemoProfileDefaultsService::defaults(0, $genderOverride);
        $profile = MatrimonyProfile::create([
            'user_id' => $demoUser->id,
            'full_name' => $defaults['full_name'],
            'gender' => $defaults['gender'],
            'date_of_birth' => $defaults['date_of_birth'],
            'marital_status' => $defaults['marital_status'],
            'education' => $defaults['education'],
            'location' => $defaults['location'],
            'caste' => $defaults['caste'],
            'profile_photo' => $defaults['profile_photo'],
            'photo_approved' => $defaults['photo_approved'],
            'is_demo' => true,
            'is_suspended' => false,
        ]);

        foreach (['full_name', 'gender', 'date_of_birth', 'marital_status', 'education', 'location', 'caste', 'profile_photo', 'photo_approved', 'is_demo', 'is_suspended'] as $fieldKey) {
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
            $defaults = DemoProfileDefaultsService::defaults($i, $genderOverride);
            $profile = MatrimonyProfile::create([
                'user_id' => $user->id,
                'full_name' => $defaults['full_name'],
                'gender' => $defaults['gender'],
                'date_of_birth' => $defaults['date_of_birth'],
                'marital_status' => $defaults['marital_status'],
                'education' => $defaults['education'],
                'location' => $defaults['location'],
                'caste' => $defaults['caste'],
                'profile_photo' => $defaults['profile_photo'],
                'photo_approved' => $defaults['photo_approved'],
                'is_demo' => true,
                'is_suspended' => false,
            ]);
            foreach (['full_name', 'gender', 'date_of_birth', 'marital_status', 'education', 'location', 'caste', 'profile_photo', 'photo_approved', 'is_demo', 'is_suspended'] as $fieldKey) {
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
}
