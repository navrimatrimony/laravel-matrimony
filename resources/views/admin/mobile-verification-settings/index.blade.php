@extends('layouts.admin')

@section('content')
<style>
.admin-toggle { position: relative; display: inline-flex; align-items: center; cursor: pointer; }
.admin-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
.admin-toggle .toggle-track { width: 52px; height: 28px; background-color: #d1d5db; border-radius: 9999px; transition: background-color 0.2s ease; position: relative; }
.admin-toggle input:checked + .toggle-track { background-color: #10b981; }
.admin-toggle .toggle-thumb { position: absolute; top: 2px; left: 2px; width: 24px; height: 24px; background-color: white; border-radius: 9999px; transition: transform 0.2s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
.admin-toggle input:checked + .toggle-track .toggle-thumb { transform: translateX(24px); }
.admin-toggle .toggle-label { margin-left: 12px; font-weight: 600; font-size: 14px; }
.admin-toggle .toggle-label.on { color: #059669; }
.admin-toggle .toggle-label.off { color: #6b7280; }
</style>

<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Registration &amp; mobile verification</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">Control whether new users see the OTP step after registration and how OTP is delivered. Mobile verification is important; email verification is de-emphasised.</p>
    @if (session('success'))
        <p class="text-green-600 dark:text-green-400 text-sm mb-4">{{ session('success') }}</p>
    @endif
    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif
    <div class="mb-6 p-4 bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-800 rounded-lg text-sky-800 dark:text-sky-200 text-sm">
        <p class="font-semibold mb-1">Behaviour</p>
        <p><strong>Redirect after registration ON:</strong> User registers with mobile → lands on OTP page → can Verify (then wizard) or Skip / Verify later (wizard). User can always use the wizard without verifying.</p>
        <p class="mt-2"><strong>Redirect after registration OFF:</strong> User registers → goes straight to profile wizard (no OTP step).</p>
        <p class="mt-2"><strong>OTP mode:</strong> off = verification page redirects away; dev_show = OTP shown on screen for testing; live = real SMS (when implemented).</p>
    </div>
    <form method="POST" action="{{ route('admin.mobile-verification-settings.update') }}" class="space-y-6">
        @csrf

        <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600">
            <label class="admin-toggle">
                <input type="checkbox" name="redirect_to_mobile_verify_after_registration" value="1" {{ $redirectAfterRegister ? 'checked' : '' }}>
                <span class="toggle-track"><span class="toggle-thumb"></span></span>
                <span class="toggle-label {{ $redirectAfterRegister ? 'on' : 'off' }}">
                    {{ $redirectAfterRegister ? 'Redirect to OTP step after registration (user can skip)' : 'Do NOT redirect — go straight to wizard after registration' }}
                </span>
            </label>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">When ON, after sign-up user sees mobile OTP page first, then wizard. When OFF, user goes directly to profile wizard.</p>
        </div>

        <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Mobile verification mode</label>
            <select name="mobile_verification_mode" class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm">
                <option value="off" {{ $mobileVerificationMode === 'off' ? 'selected' : '' }}>Off — verification disabled (redirects away)</option>
                <option value="dev_show" {{ $mobileVerificationMode === 'dev_show' ? 'selected' : '' }}>Dev — OTP shown on screen (testing)</option>
                <option value="live" {{ $mobileVerificationMode === 'live' ? 'selected' : '' }}>Live — real SMS (when implemented)</option>
            </select>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Must not be "Off" for redirect-after-registration to show the OTP page.</p>
        </div>

        <div class="pt-2">
            <button type="submit" style="background-color: #4f46e5; color: white; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 16px; border: none; cursor: pointer;">
                Save settings
            </button>
        </div>
    </form>
</div>
@endsection
