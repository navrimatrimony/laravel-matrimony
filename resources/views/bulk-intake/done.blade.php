@extends('layouts.bulk-register')

@php
    $sectionClass = 'rounded-xl border border-gray-200 bg-white/95 p-4 shadow-sm backdrop-blur-sm sm:p-5 text-center';
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
        <p class="mt-6 text-xs text-gray-500">आमचा प्रतिनिधी लवकरच तुमच्याशी संपर्क साधेल.</p>
    </div>
</div>
@endsection
