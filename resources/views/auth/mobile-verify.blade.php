<x-guest-layout>
    @if (!empty($fromRegistration))
        <div class="mb-4 p-4 bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-700 rounded-lg text-sky-800 dark:text-sky-200 text-sm">
            <p class="font-semibold">{{ __('otp.step_1_of_2_verify_mobile') }}</p>
            <p class="mt-1">{{ __('otp.verify_to_continue_or_skip') }}</p>
        </div>
    @endif
    <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        {{ __('otp.verify_we_will_send_otp') }}
    </div>

    @if (session('status'))
        <p class="mb-4 text-sm text-green-600 dark:text-green-400">{{ session('status') }}</p>
    @endif

    @if (!empty($otpDisplay))
        <div class="mb-4 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
            <p class="text-sm font-semibold text-amber-800 dark:text-amber-200">{{ __('otp.for_testing_otp_is') }}</p>
            <p class="text-2xl font-mono font-bold text-amber-900 dark:text-amber-100 mt-1">{{ $otpDisplay }}</p>
            <p class="text-xs text-amber-700 dark:text-amber-300 mt-1">{{ __('otp.enter_below_to_verify') }}</p>
        </div>
    @endif

    @if (!$user->mobile)
        <form method="POST" action="{{ route('mobile.verify.send') }}" class="space-y-4">
            @csrf
            @if (!$user->mobile)
                <div>
                    <x-profile.contact-field
                        name="mobile"
                        :value="old('mobile', $user->mobile)"
                        :label="__('otp.mobile_number')"
                        :placeholder="__('otp.ten_digit_number')"
                        :showCountryCode="true"
                        :showWhatsapp="false"
                        :required="true"
                    />
                    <x-input-error :messages="$errors->get('mobile')" class="mt-2" />
                </div>
                <x-primary-button type="submit">{{ __('otp.send_otp') }}</x-primary-button>
            @endif
        </form>
    @else
        <form method="POST" action="{{ route('mobile.verify.send') }}" class="mb-6">
            @csrf
            <input type="hidden" name="mobile" value="{{ $user->mobile }}">
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('otp.mobile_label') }}: {{ $user->mobile }}</p>
            <x-primary-button type="submit" class="mt-2">{{ __('otp.send_new_otp') }}</x-primary-button>
        </form>
    @endif

    @if ($user->mobile)
        <form method="POST" action="{{ route('mobile.verify.submit') }}" class="mt-6">
            @csrf
            <div>
                <x-input-label for="otp" :value="__('otp.enter_6_digit_otp')" />
                <x-text-input id="otp" class="block mt-1 w-full font-mono text-lg tracking-widest" type="text" name="otp" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="000000" required />
                <x-input-error :messages="$errors->get('otp')" class="mt-2" />
            </div>
            <div class="flex items-center justify-between mt-4 flex-wrap gap-2">
                @if (!empty($fromRegistration))
                    <a href="{{ route('mobile.verify.skip') }}" class="text-sm text-amber-600 dark:text-amber-400 hover:underline font-medium">{{ __('otp.skip_verify_later') }}</a>
                @else
                    <a href="{{ route('dashboard') }}" class="text-sm text-gray-600 dark:text-gray-400 hover:underline">{{ __('wizard.skip_for_now') }}</a>
                @endif
                <x-primary-button type="submit">{{ __('otp.verify') }}</x-primary-button>
            </div>
        </form>
    @endif

    <div class="mt-6 text-center">
        @if (!empty($fromRegistration))
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">{{ __('otp.can_verify_later_from_dashboard') }}</p>
            <a href="{{ route('mobile.verify.skip') }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline font-medium">{{ __('otp.skip_and_go_to_wizard_arrow') }}</a>
        @else
            <a href="{{ route('dashboard') }}" class="text-sm text-gray-600 dark:text-gray-400 hover:underline">{{ __('otp.back_to_dashboard') }}</a>
        @endif
    </div>
</x-guest-layout>
