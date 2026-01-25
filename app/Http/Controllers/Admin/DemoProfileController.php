<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\DemoProfileDefaultsService;
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
            'full_name' => 'Demo Profile',
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
            MatrimonyProfile::create([
                'user_id' => $user->id,
                'full_name' => DemoProfileDefaultsService::fullNameForIndex($i),
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
        }

        return redirect()
            ->route('admin.demo-profile.bulk-create')
            ->with('success', "Created {$count} demo profile(s).");
    }
}
