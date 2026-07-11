@extends('layouts.bulk-register')

@php
    $sectionClass = 'rounded-xl border border-gray-200 bg-white/95 p-4 shadow-sm backdrop-blur-sm sm:p-5 text-center';
    $redirectDelaySeconds = max(1, (int) ($redirect_delay_seconds ?? 4));
@endphp

@section('content')
<div class="mx-auto w-full max-w-2xl">
    <div class="{{ $sectionClass }}">
        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-green-100 text-3xl text-green-700">
            ✓
        </div>
        <h1 class="text-2xl font-bold text-gray-900">नोंदणी पूर्ण झाली</h1>
        @if (! empty($candidate_name))
            <p class="mt-2 text-lg font-semibold text-violet-800">{{ $candidate_name }}</p>
        @endif
        @if (session('success'))
            <p class="mt-4 text-sm text-green-800">{{ session('success') }}</p>
        @else
            <p class="mt-4 text-sm text-gray-600">
                तुमची माहिती, फोटो आणि जोडीदार प्राधान्ये यशस्वीरित्या जतन झाली. धन्यवाद!
            </p>
        @endif
        @if (! empty($photo_preview_url))
            <div class="mt-6 flex justify-center">
                <img src="{{ $photo_preview_url }}" alt="प्रोफाइल फोटो" class="h-40 w-32 rounded-xl border border-gray-200 object-cover shadow-sm">
            </div>
        @endif
        @if (! empty($dashboard_url))
            <p class="mt-6 text-xs text-gray-500">
                <span id="bulk-registration-redirect-countdown">{{ $redirectDelaySeconds }}</span> सेकंदात डॅशबोर्डवर पुनर्निर्देशित होत आहात…
            </p>
        @endif
    </div>
</div>

@if (! empty($dashboard_url))
    <script>
        (function () {
            var redirectUrl = @json($dashboard_url);
            var remainingSeconds = {{ $redirectDelaySeconds }};
            var countdownEl = document.getElementById('bulk-registration-redirect-countdown');
            var redirectTimerId = null;
            var countdownTimerId = null;

            function redirectToDashboard() {
                if (redirectTimerId) {
                    clearTimeout(redirectTimerId);
                }
                if (countdownTimerId) {
                    clearInterval(countdownTimerId);
                }
                window.location.href = redirectUrl;
            }

            redirectTimerId = window.setTimeout(redirectToDashboard, remainingSeconds * 1000);

            if (countdownEl) {
                countdownTimerId = window.setInterval(function () {
                    remainingSeconds -= 1;
                    if (remainingSeconds <= 0) {
                        countdownEl.textContent = '0';
                        if (countdownTimerId) {
                            clearInterval(countdownTimerId);
                        }
                        return;
                    }
                    countdownEl.textContent = String(remainingSeconds);
                }, 1000);
            }
        })();
    </script>
@endif
@endsection
