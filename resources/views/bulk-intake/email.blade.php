@extends('layouts.bulk-register')

@php
    $sectionClass = 'rounded-xl border border-gray-200 bg-white/95 p-4 shadow-sm backdrop-blur-sm sm:p-6';
    $otpSession = is_array($otp_session ?? null) ? $otp_session : null;
    $otpEmail = is_string($otpSession['email'] ?? null) ? $otpSession['email'] : null;
@endphp

@section('content')
<div class="mx-auto w-full max-w-2xl">
    @include('bulk-intake.partials.registration-progress', ['current' => 'email'])

    <div class="{{ $sectionClass }}">
        <div class="border-b border-gray-100 pb-4">
            <h1 class="text-xl font-bold tracking-tight text-gray-900 sm:text-2xl">तुमचा ईमेल जोडा</h1>
            @if (! empty($candidate_name))
                <p class="mt-0.5 text-base font-semibold text-violet-800">{{ $candidate_name }}</p>
            @endif
            <p class="mt-2 text-sm leading-relaxed text-gray-600">
                Google खाते निवडून ईमेल एका क्लिकमध्ये पुष्टी करा. आम्ही तुमच्या मोबाईलवरून ईमेल वाचत नाही —
                तुम्ही स्वतः Google खाते निवडता.
            </p>
        </div>

        @if (session('success'))
            <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                {{ session('success') }}
            </div>
        @endif

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

        @if ($google_sign_in_configured ?? false)
            <div class="mt-6 rounded-xl border border-violet-100 bg-violet-50/60 p-4 sm:p-5">
                <div class="flex items-start gap-3">
                    <div class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white text-lg shadow-sm ring-1 ring-violet-100">
                        G
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-sm font-semibold text-gray-900">Google खाते निवडा</h2>
                        <p class="mt-1 text-sm text-gray-600">
                            तुमच्या ब्राउझरमध्ये login असलेली Google accounts दिसतील. एक निवडा — ईमेल आपोआप verify होईल.
                        </p>
                        <div class="mt-4 flex min-h-[44px] items-center">
                            <div id="bulk-registration-google-button" class="w-full max-w-xs"></div>
                        </div>
                        <p id="bulk-registration-google-status" class="mt-2 hidden text-sm text-violet-700"></p>
                    </div>
                </div>
            </div>

            <form
                id="bulk-registration-google-form"
                method="POST"
                action="{{ route('bulk-intake.register.email.google', ['token' => $token]) }}"
                class="hidden"
            >
                @csrf
                <input type="hidden" name="email" id="bulk-registration-google-email" value="">
                <input type="hidden" name="id_token" id="bulk-registration-google-id-token" value="">
            </form>
        @else
            <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Google खाते निवड सध्या उपलब्ध नाही. कृपया खाली ईमेल टाकून OTP वापरा.
            </div>
        @endif

        <div class="relative my-6">
            <div class="absolute inset-0 flex items-center" aria-hidden="true">
                <div class="w-full border-t border-gray-200"></div>
            </div>
            <div class="relative flex justify-center text-xs font-medium uppercase tracking-wide">
                <span class="bg-white px-3 text-gray-500">किंवा</span>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 sm:p-5">
            <h2 class="text-sm font-semibold text-gray-900">इतर ईमेल पत्ता</h2>
            <p class="mt-1 text-sm text-gray-600">Gmail नसलेला ईमेल असल्यास OTP ने verify करा.</p>

            @if ($otpEmail)
                <div class="mt-4 rounded-lg border border-violet-200 bg-white px-4 py-3 text-sm text-gray-700">
                    OTP पाठवला: <span class="font-semibold text-violet-800">{{ $otpEmail }}</span>
                </div>

                <form method="POST" action="{{ route('bulk-intake.register.email.otp.verify', ['token' => $token]) }}" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="otp" class="block text-sm font-medium text-gray-700">6 अंकी OTP</label>
                        <input
                            type="text"
                            inputmode="numeric"
                            pattern="\d{6}"
                            maxlength="6"
                            name="otp"
                            id="otp"
                            required
                            autocomplete="one-time-code"
                            class="mt-1 block w-full rounded-lg border-gray-300 text-center text-lg tracking-[0.35em] shadow-sm focus:border-violet-500 focus:ring-violet-500"
                            placeholder="••••••"
                        >
                    </div>
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-violet-700 focus:outline-none focus:ring-2 focus:ring-violet-500 focus:ring-offset-2">
                        OTP पुष्टी करा
                    </button>
                </form>
            @else
                <form method="POST" action="{{ route('bulk-intake.register.email.otp.send', ['token' => $token]) }}" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700">ईमेल पत्ता</label>
                        <input
                            type="email"
                            name="email"
                            id="email"
                            required
                            autocomplete="email"
                            value="{{ old('email') }}"
                            class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-violet-500 focus:ring-violet-500"
                            placeholder="you@example.com"
                        >
                    </div>
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-violet-700 shadow-sm ring-1 ring-violet-200 hover:bg-violet-50 focus:outline-none focus:ring-2 focus:ring-violet-500 focus:ring-offset-2">
                        OTP पाठवा
                    </button>
                </form>
            @endif
        </div>

        <div class="mt-6 flex items-center justify-between border-t border-gray-100 pt-4">
            <form method="POST" action="{{ route('bulk-intake.register.email.skip', ['token' => $token]) }}">
                @csrf
                <button type="submit" class="text-sm font-medium text-gray-500 hover:text-gray-700">
                    नंतर करेन
                </button>
            </form>
            <p class="text-xs text-gray-500">हे पाऊल ऐच्छिक आहे</p>
        </div>
    </div>
</div>
@endsection

@if ($google_sign_in_configured ?? false)
    @push('head')
        <script src="https://accounts.google.com/gsi/client" async defer></script>
    @endpush

    @push('scripts')
        <script>
            (function () {
                var clientId = @json($google_client_id);
                var form = document.getElementById('bulk-registration-google-form');
                var emailInput = document.getElementById('bulk-registration-google-email');
                var tokenInput = document.getElementById('bulk-registration-google-id-token');
                var statusEl = document.getElementById('bulk-registration-google-status');
                var buttonMount = document.getElementById('bulk-registration-google-button');
                var submitting = false;

                function decodeJwtPayload(token) {
                    try {
                        var base64 = token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
                        var json = decodeURIComponent(atob(base64).split('').map(function (char) {
                            return '%' + ('00' + char.charCodeAt(0).toString(16)).slice(-2);
                        }).join(''));
                        return JSON.parse(json);
                    } catch (error) {
                        return null;
                    }
                }

                function setStatus(message) {
                    if (!statusEl) return;
                    statusEl.textContent = message;
                    statusEl.classList.remove('hidden');
                }

                window.handleBulkRegistrationGoogleCredential = function (response) {
                    if (!response || !response.credential || submitting) {
                        return;
                    }

                    var payload = decodeJwtPayload(response.credential);
                    var email = payload && payload.email ? String(payload.email) : '';
                    if (!email) {
                        setStatus('Google ईमेल मिळाला नाही. कृपया OTP वापरा.');
                        return;
                    }

                    submitting = true;
                    setStatus('Google ईमेल verify होत आहे…');
                    emailInput.value = email;
                    tokenInput.value = response.credential;
                    form.submit();
                };

                function initGoogleButton() {
                    if (!window.google || !google.accounts || !google.accounts.id || !buttonMount) {
                        window.setTimeout(initGoogleButton, 120);
                        return;
                    }

                    google.accounts.id.initialize({
                        client_id: clientId,
                        callback: window.handleBulkRegistrationGoogleCredential,
                        auto_select: false,
                        cancel_on_tap_outside: true,
                    });

                    google.accounts.id.renderButton(buttonMount, {
                        type: 'standard',
                        theme: 'outline',
                        size: 'large',
                        text: 'continue_with',
                        shape: 'pill',
                        logo_alignment: 'left',
                        width: Math.min(320, buttonMount.offsetWidth || 320),
                        locale: 'mr',
                    });
                }

                initGoogleButton();
            })();
        </script>
    @endpush
@endif
