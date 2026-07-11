@extends('layouts.bulk-register')

@php
    $sectionClass = 'rounded-xl border border-gray-200 bg-white/95 p-4 shadow-sm backdrop-blur-sm sm:p-6';
@endphp

@section('content')
<div class="mx-auto w-full max-w-2xl">
    @include('bulk-intake.partials.registration-progress', ['current' => 'password'])

    <div class="{{ $sectionClass }}">
        <div class="border-b border-gray-100 pb-4">
            <h1 class="text-xl font-bold tracking-tight text-gray-900 sm:text-2xl">पासवर्ड सेट करा</h1>
            @if (! empty($candidate_name))
                <p class="mt-0.5 text-base font-semibold text-violet-800">{{ $candidate_name }}</p>
            @endif
            <p class="mt-2 text-sm leading-relaxed text-gray-600">
                नंतर login साठी पासवर्ड वापरू शकता. पासवर्ड नसला तरी मोबाईल OTP ने login शक्य आहे.
            </p>
            @if (! empty($user?->email) && $user?->email_verified_at)
                <p class="mt-2 text-sm text-emerald-700">
                    पुष्टी केलेला ईमेल: <span class="font-semibold">{{ $user->email }}</span>
                </p>
            @endif
        </div>

        @if ($errors->any())
            <div class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <p class="font-medium">कृपया खालील त्रुटी दुरुस्त करा:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('bulk-intake.register.password.store', ['token' => $token]) }}" class="mt-6 space-y-4" id="bulk-registration-password-form">
            @csrf

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">नवीन पासवर्ड</label>
                <div class="relative mt-1">
                    <input
                        type="password"
                        name="password"
                        id="password"
                        required
                        autocomplete="new-password"
                        class="block w-full rounded-lg border-gray-300 pr-10 shadow-sm focus:border-violet-500 focus:ring-violet-500"
                    >
                    <button
                        type="button"
                        data-password-toggle="password"
                        class="absolute inset-y-0 right-0 flex items-center px-3 text-sm text-gray-500 hover:text-gray-700"
                        aria-label="पासवर्ड दाखवा"
                    >
                        दाखवा
                    </button>
                </div>
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700">पासवर्ड पुन्हा टाका</label>
                <div class="relative mt-1">
                    <input
                        type="password"
                        name="password_confirmation"
                        id="password_confirmation"
                        required
                        autocomplete="new-password"
                        class="block w-full rounded-lg border-gray-300 pr-10 shadow-sm focus:border-violet-500 focus:ring-violet-500"
                    >
                    <button
                        type="button"
                        data-password-toggle="password_confirmation"
                        class="absolute inset-y-0 right-0 flex items-center px-3 text-sm text-gray-500 hover:text-gray-700"
                        aria-label="पासवर्ड दाखवा"
                    >
                        दाखवा
                    </button>
                </div>
            </div>

            <div class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
                <button
                    type="submit"
                    formaction="{{ route('bulk-intake.register.password.skip', ['token' => $token]) }}"
                    formmethod="POST"
                    formnovalidate
                    class="text-sm font-medium text-gray-500 hover:text-gray-700"
                >
                    नंतर करेन
                </button>

                <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-violet-700 focus:outline-none focus:ring-2 focus:ring-violet-500 focus:ring-offset-2 sm:w-auto">
                    पासवर्ड जतन करा आणि पुढे जा
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var targetId = button.getAttribute('data-password-toggle');
                var input = document.getElementById(targetId);
                if (!input) return;
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                button.textContent = show ? 'लपवा' : 'दाखवा';
            });
        });
    })();
</script>
@endsection
