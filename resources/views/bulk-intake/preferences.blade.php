@extends('layouts.bulk-register')

@php
    $sectionClass = 'rounded-xl border border-gray-200 bg-white/95 p-4 shadow-sm backdrop-blur-sm sm:p-5';
@endphp

@section('content')
<div class="mx-auto w-full max-w-2xl">
    @include('bulk-intake.partials.registration-progress', ['current' => 'preferences'])

    <div class="{{ $sectionClass }}">
        <div class="border-b border-gray-100 pb-3">
            <h1 class="text-xl font-bold tracking-tight text-gray-900 sm:text-2xl">जोडीदार प्राधान्ये</h1>
            @if (! empty($candidate_name))
                <p class="mt-0.5 text-base font-semibold text-violet-800">{{ $candidate_name }}</p>
            @endif
            <p class="mt-2 text-sm text-gray-600">आम्ही तुमच्या नोंदणी माहितीवर आधारित प्राधान्ये भरले आहेत. शिक्षण, व्यवसाय, उत्पन्न आणि आहार — <strong>कोणतेही चालेल</strong> (सर्व निवडलेले). फक्त तपासा आणि पुष्टी करा.</p>
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

        <form method="POST" action="{{ route('bulk-intake.register.preferences.store', ['token' => $token]) }}" class="mt-4">
            @csrf
            @include('matrimony.profile.wizard.sections.about_preferences')

            <div class="mt-6 flex justify-end border-t border-gray-100 pt-4">
                <button type="submit" class="inline-flex items-center rounded-lg bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-violet-700 focus:outline-none focus:ring-2 focus:ring-violet-500 focus:ring-offset-2">
                    प्राधान्ये जतन करा आणि पुढे जा
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
