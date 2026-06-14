<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakContactNumber;
use App\Support\MobileNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $account = $request->user()->suchakAccount()->with('contactNumbers')->firstOrFail();

        return view('suchak.account-settings', [
            'suchakAccount' => $account,
            'contactNumbers' => $account->contactNumbers()
                ->latest('id')
                ->get(),
        ]);
    }

    public function storeContactNumber(Request $request): RedirectResponse
    {
        $account = $request->user()->suchakAccount()->firstOrFail();

        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'max:32'],
            'label' => ['nullable', 'string', 'max:80'],
            'label_mr' => ['nullable', 'string', 'max:80'],
            'is_whatsapp' => ['nullable', 'boolean'],
        ]);

        $phone = MobileNumber::normalize((string) $validated['phone_number']);
        if ($phone === null) {
            return back()
                ->withInput()
                ->withErrors(['phone_number' => __('otp.enter_valid_10_digit_mobile')]);
        }

        Validator::make([
            'phone_number' => $phone,
        ], [
            'phone_number' => [
                Rule::unique('suchak_contact_numbers', 'phone_number')
                    ->where('suchak_account_id', $account->id),
            ],
        ], [
            'phone_number.unique' => 'This number is already added to your Suchak account.',
        ])->validate();

        SuchakContactNumber::query()->create([
            'suchak_account_id' => $account->id,
            'phone_number' => $phone,
            'label' => trim((string) ($validated['label'] ?? '')) ?: null,
            'label_mr' => trim((string) ($validated['label_mr'] ?? '')) ?: null,
            'is_whatsapp' => $request->boolean('is_whatsapp'),
            'is_active' => true,
        ]);

        return back()->with('success', 'Additional contact number added.');
    }

    public function destroyContactNumber(Request $request, SuchakContactNumber $contactNumber): RedirectResponse
    {
        $account = $request->user()->suchakAccount()->firstOrFail();
        abort_unless((int) $contactNumber->suchak_account_id === (int) $account->id, 404);

        $contactNumber->delete();

        return back()->with('success', 'Additional contact number removed.');
    }
}
