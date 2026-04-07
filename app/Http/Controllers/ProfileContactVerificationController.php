<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Services\MutationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * OTP stub + verified-only primary promotion; all profile_contacts writes go through MutationService.
 */
class ProfileContactVerificationController extends Controller
{
    private function otpCacheKey(int $userId, int $contactId): string
    {
        return 'profile_contact_otp:'.$userId.':'.$contactId;
    }

    private function profileOrAbort(): MatrimonyProfile
    {
        $profile = auth()->user()?->matrimonyProfile;
        if (! $profile) {
            abort(404);
        }

        return $profile;
    }

    public function sendOtp(int $contact)
    {
        $profile = $this->profileOrAbort();
        $this->authorizeSelfContact($profile, $contact);

        $otp = (string) random_int(100000, 999999);
        Cache::put(
            $this->otpCacheKey((int) auth()->id(), $contact),
            password_hash($otp, PASSWORD_DEFAULT),
            now()->addMinutes(10)
        );
        if (config('app.debug')) {
            Log::info('profile_contact_otp_stub', [
                'contact_id' => $contact,
                'profile_id' => $profile->id,
                'otp' => $otp,
            ]);
        }

        return redirect()
            ->route('matrimony.profile.wizard.section', ['section' => 'full', 'all' => 1])
            ->with('success', __('contact_verify.otp_sent'));
    }

    public function verifyOtp(Request $request, int $contact)
    {
        $request->validate([
            'otp' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);
        $profile = $this->profileOrAbort();
        $this->authorizeSelfContact($profile, $contact);

        $key = $this->otpCacheKey((int) auth()->id(), $contact);
        $hash = Cache::get($key);
        if (! is_string($hash) || ! password_verify((string) $request->input('otp'), $hash)) {
            throw ValidationException::withMessages([
                'otp' => [__('contact_verify.otp_invalid')],
            ]);
        }
        Cache::forget($key);

        try {
            app(MutationService::class)->markSelfContactVerified($profile, $contact, (int) auth()->id());
        } catch (ValidationException $e) {
            return redirect()
                ->route('matrimony.profile.wizard.section', ['section' => 'full', 'all' => 1])
                ->withErrors($e->errors());
        }

        return redirect()
            ->route('matrimony.profile.wizard.section', ['section' => 'full', 'all' => 1])
            ->with('success', __('contact_verify.verified_ok'));
    }

    public function promotePrimary(int $contact)
    {
        $profile = $this->profileOrAbort();
        $this->authorizeSelfContact($profile, $contact);

        try {
            app(MutationService::class)->promoteVerifiedSelfContactToPrimary($profile, $contact, (int) auth()->id());
        } catch (ValidationException $e) {
            return redirect()
                ->route('matrimony.profile.wizard.section', ['section' => 'full', 'all' => 1])
                ->withErrors($e->errors());
        }

        return redirect()
            ->route('matrimony.profile.wizard.section', ['section' => 'full', 'all' => 1])
            ->with('success', __('contact_verify.promoted_ok'));
    }

    private function authorizeSelfContact(MatrimonyProfile $profile, int $contactId): void
    {
        $selfId = DB::table('master_contact_relations')->where('key', 'self')->value('id');
        if ($selfId === null) {
            abort(503, 'Contact relations not configured.');
        }
        $row = DB::table('profile_contacts')
            ->where('id', $contactId)
            ->where('profile_id', $profile->id)
            ->first();
        if (! $row || (int) ($row->contact_relation_id ?? 0) !== (int) $selfId) {
            abort(403);
        }
    }
}
