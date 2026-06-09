<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Modules\Suchak\Services\SuchakActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountRequestController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user()->suchakAccount()->exists()) {
            return redirect()
                ->route('suchak.dashboard')
                ->with('info', 'Suchak account request already exists.');
        }

        return view('suchak.apply');
    }

    public function store(Request $request, SuchakActivityLogger $activityLogger): RedirectResponse
    {
        $user = $request->user();

        if ($user->suchakAccount()->exists()) {
            return redirect()
                ->route('suchak.dashboard')
                ->with('info', 'Suchak account request already exists.');
        }

        $validated = $request->validate([
            'suchak_name' => ['required', 'string', 'max:255'],
            'office_name' => ['nullable', 'string', 'max:255'],
            'business_type' => [
                'required',
                'string',
                Rule::in([
                    SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
                    SuchakAccount::BUSINESS_TYPE_BUREAU,
                    SuchakAccount::BUSINESS_TYPE_ORGANIZATION,
                ]),
            ],
            'mobile_number' => ['nullable', 'string', 'max:20'],
            'whatsapp_number' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'address_line' => ['nullable', 'string', 'max:2000'],
        ]);

        $account = DB::transaction(function () use ($activityLogger, $request, $user, $validated): SuchakAccount {
            $account = SuchakAccount::query()->create([
                'user_id' => $user->id,
                'suchak_name' => $validated['suchak_name'],
                'office_name' => $validated['office_name'] ?? null,
                'business_type' => $validated['business_type'],
                'mobile_number' => $validated['mobile_number'] ?? null,
                'whatsapp_number' => $validated['whatsapp_number'] ?? null,
                'email' => $validated['email'] ?? null,
                'address_line' => $validated['address_line'] ?? null,
                'verification_status' => SuchakAccount::VERIFICATION_PENDING,
                'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            ]);

            $activityLogger->record([
                'suchak_account_id' => $account->id,
                'actor_user_id' => $user->id,
                'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
                'action_type' => SuchakActivityLog::ACTION_SUCHAK_ONBOARDING_REQUESTED,
                'target_type' => 'suchak_account',
                'target_id' => $account->id,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
                'metadata_json' => ['source' => 'authenticated_onboarding'],
            ]);

            return $account;
        });

        return redirect()
            ->route('suchak.dashboard')
            ->with('success', 'Suchak account request submitted for admin verification.');
    }
}
