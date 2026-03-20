@extends('layouts.app')

@section('content')
<div class="container max-w-2xl mx-auto py-10 px-4">
    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        <a href="{{ route('intake.index') }}" class="hover:underline">← My biodata uploads</a>
    </p>

    @php
        $parseStatus = $intake->parse_status;
        $approved = (bool) $intake->approved_by_user;
    @endphp

    @if(!empty($ocrPresetFeedback))
        <div class="mb-4 rounded-lg border border-sky-200 dark:border-sky-800 bg-sky-50 dark:bg-sky-900/25 px-3 py-2 text-sm text-sky-900 dark:text-sky-100" role="status">
            <span class="font-medium">{{ __('intake.ocr_enhancement_badge_prefix') }}</span>
            {{ $ocrPresetFeedback }}
        </div>
    @endif

    @if($approved)

        <div class="bg-white dark:bg-gray-800 rounded shadow p-6 border border-green-300">
            <h1 class="text-2xl font-bold text-green-600 mb-4">
                ✅ Intake Approved Successfully
            </h1>

            <p class="text-gray-700 dark:text-gray-300">
                तुमची माहिती यशस्वीरित्या सेव्ह झाली आहे.
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('matrimony.profile.wizard.section', ['section' => 'full']) }}"
                   class="px-5 py-3 bg-green-600 text-white rounded hover:bg-green-700">
                    Complete your profile
                </a>
                <a href="{{ route('intake.index') }}"
                   class="px-5 py-3 bg-gray-600 text-white rounded hover:bg-gray-700">
                    My biodata uploads
                </a>
                <a href="{{ route('dashboard') }}"
                   class="px-5 py-3 bg-indigo-600 text-white rounded hover:bg-indigo-700">
                    Go to Dashboard
                </a>
            </div>
        </div>

    @elseif($parseStatus === 'parsed' && !$approved)

        <script>
            window.location.href = "{{ route('intake.preview', $intake->id) }}";
        </script>

    @elseif(in_array($parseStatus, ['failed', 'error'], true))

        <div class="bg-white dark:bg-gray-800 rounded shadow p-6 border border-red-300">
            <h1 class="text-2xl font-bold text-red-600 mb-4">
                ❌ Parsing Failed
            </h1>

            <p class="text-gray-700 dark:text-gray-300">
                OCR parsing मध्ये त्रुटी आली आहे.
            </p>
            <a href="{{ route('intake.index') }}" class="mt-4 inline-block px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700">My biodata uploads</a>
        </div>

    @else
        @php
            $processingSteps = [
                __('intake.processing_step_1'),
                __('intake.processing_step_2'),
                __('intake.processing_step_3'),
            ];
        @endphp

        <div
            id="intake-processing-root"
            class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-8 border border-blue-200 dark:border-blue-900/40"
            data-poll-url="{{ route('api.intake-status', $intake) }}"
            data-preview-url="{{ route('intake.preview', $intake) }}"
            data-status-url="{{ route('intake.status', $intake) }}"
        >
            <div class="flex flex-col sm:flex-row sm:items-center gap-6">
                <div class="relative flex-shrink-0 w-20 h-20 mx-auto sm:mx-0" aria-hidden="true">
                    <div class="absolute inset-0 rounded-full border-4 border-blue-100 dark:border-blue-950"></div>
                    <div class="absolute inset-0 rounded-full border-4 border-transparent border-t-blue-600 dark:border-t-blue-400 animate-spin"></div>
                    <div class="absolute inset-2 rounded-full bg-blue-50 dark:bg-blue-950/50 animate-pulse"></div>
                </div>
                <div class="flex-1 text-center sm:text-left min-w-0">
                    <h1 class="text-xl sm:text-2xl font-bold text-blue-700 dark:text-blue-400 mb-2">
                        {{ __('intake.processing_title') }}
                    </h1>
                    <p class="text-gray-700 dark:text-gray-300 text-sm sm:text-base mb-3">
                        {{ __('intake.processing_subtitle') }}
                    </p>
                    <p
                        id="intake-processing-step"
                        class="text-sm text-blue-800/90 dark:text-blue-200/90 font-medium min-h-[1.25rem]"
                        aria-live="polite"
                    >{{ $processingSteps[0] }}</p>
                    <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('intake.processing_hint') }}
                    </p>
                    <a href="{{ route('intake.index') }}" class="mt-5 inline-block text-sm text-gray-600 dark:text-gray-400 hover:underline">
                        {{ __('intake.my_biodata_uploads') }}
                    </a>
                </div>
            </div>
        </div>

        <script>
            (function () {
                var root = document.getElementById('intake-processing-root');
                var stepEl = document.getElementById('intake-processing-step');
                if (!root || !stepEl) return;

                var steps = @json($processingSteps);
                var pollUrl = root.getAttribute('data-poll-url');
                var previewUrl = root.getAttribute('data-preview-url');
                var statusUrl = root.getAttribute('data-status-url');
                var stepIndex = 0;
                var stopped = false;

                setInterval(function () {
                    if (stopped || !steps.length) return;
                    stepIndex = (stepIndex + 1) % steps.length;
                    stepEl.textContent = steps[stepIndex];
                }, 3200);

                function poll() {
                    if (stopped || !pollUrl) return;
                    fetch(pollUrl, {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    })
                        .then(function (r) {
                            if (r.status === 403) throw new Error('forbidden');
                            if (!r.ok) throw new Error('bad status');
                            return r.json();
                        })
                        .then(function (data) {
                            if (data.parse_status === 'parsed') {
                                stopped = true;
                                window.location.href = data.approved_by_user ? statusUrl : previewUrl;
                                return;
                            }
                            if (data.parse_status === 'error' || data.parse_status === 'failed') {
                                stopped = true;
                                window.location.href = statusUrl;
                            }
                        })
                        .catch(function () { /* next interval */ });
                }

                poll();
                setInterval(poll, 2500);
            })();
        </script>

    @endif

</div>
@endsection