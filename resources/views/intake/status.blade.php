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

    @elseif($parseStatus === 'failed')

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

        <div class="bg-white dark:bg-gray-800 rounded shadow p-6 border border-blue-300">
            <h1 class="text-2xl font-bold text-blue-600 mb-4">
                Processing Biodata...
            </h1>

            <p class="text-gray-700 dark:text-gray-300">
                कृपया थांबा. तुमची माहिती process होत आहे.
            </p>
            <a href="{{ route('intake.index') }}" class="mt-4 inline-block text-sm text-gray-600 dark:text-gray-400 hover:underline">My biodata uploads</a>
        </div>

        <script>
            setTimeout(function() {
                location.reload();
            }, 2000);
        </script>

    @endif

</div>
@endsection