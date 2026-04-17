<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\MobileNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DuplicatePhoneController extends Controller
{
    /**
     * Accounts tied to a resolved duplicate mobile (suffix strategy) or holding secondary rows.
     */
    public function index(): View
    {
        $q = User::query()
            ->where(function ($query): void {
                $query->whereNotNull('mobile_duplicate_of_user_id')
                    ->orWhereHas('mobileDuplicateSecondaries');
            })
            ->with([
                'mobileDuplicatePrimary:id,name,mobile,email',
                'mobileDuplicateSecondaries:id,name,mobile,mobile_duplicate_of_user_id',
            ])
            ->orderByDesc('updated_at');

        $users = $q->paginate(40)->withQueryString();

        return view('admin.duplicate-phones.index', [
            'users' => $users,
        ]);
    }

    public function updateMobile(Request $request, User $user): RedirectResponse
    {
        $request->validate([
            'mobile' => ['required', 'string', 'max:64'],
        ]);

        $digits = MobileNumber::normalize($request->input('mobile'));
        if ($digits === null) {
            return redirect()->back()->withErrors(['mobile' => __('otp.enter_valid_10_digit_mobile')]);
        }

        Validator::make(
            ['mobile' => $digits],
            ['mobile' => ['required', Rule::unique('users', 'mobile')->ignore($user->id)]],
            ['mobile.unique' => __('auth.mobile_duplicate_register')]
        )->validate();

        $user->update([
            'mobile' => $digits,
            'mobile_duplicate_of_user_id' => null,
        ]);

        return redirect()
            ->route('admin.duplicate-phones.index')
            ->with('success', __('admin.duplicate_phones.mobile_updated'));
    }
}
