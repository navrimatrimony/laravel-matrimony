@extends('layouts.app')

@section('content') <div class="max-w-md mx-auto text-center">
{{-- Step indicator --}}
<div class="mb-4 text-sm text-gray-600">
    <span class="font-semibold text-indigo-600">Step 2 of 2:</span>
    Upload your profile photo
</div>

<h1 class="text-2xl font-bold mb-6">
    Upload Profile Photo
</h1>

@if (auth()->check() && auth()->user()->matrimonyProfile && auth()->user()->matrimonyProfile->photo_rejection_reason)
    <div style="margin-bottom:1.5rem; padding:1rem; background:#fee2e2; border:1px solid #fca5a5; border-radius:8px; color:#991b1b;">
        <p style="font-weight:600; margin-bottom:0.5rem;">Your profile photo was removed by admin.</p>
        <p style="margin:0;"><strong>Reason:</strong> {{ auth()->user()->matrimonyProfile->photo_rejection_reason }}</p>
    </div>
@endif

<form method="POST"
      action="{{ route('matrimony.profile.store-photo') }}"
      enctype="multipart/form-data">
    @csrf

    {{-- File input --}}
    <div class="mb-6">
        <label class="block font-medium text-gray-700 mb-2">
            Choose Profile Photo <span class="text-red-500">*</span>
        </label>

        <input
            type="file"
            name="profile_photo"
            required
            class="block w-full border border-gray-300 rounded-lg p-2"
        >

        <p class="mt-2 text-sm text-gray-500">
            Clear face photo preferred. JPG or PNG only.
        </p>
    </div>

    {{-- Primary action --}}
    <div class="text-center">
    <button
        type="submit"
        class="bg-green-600 text-white px-10 py-3 rounded-lg text-lg font-semibold hover:bg-green-700"
    >
        Upload Photo & Finish Profile ✔
    </button>
</div>

</form>

{{-- Secondary actions --}}
<div class="mt-6 text-center text-sm text-gray-600 space-x-4">
    <a href="{{ route('matrimony.profile.show', auth()->user()->matrimonyProfile->id) }}"
       class="underline hover:text-gray-900">
        Skip for now (View Profile)
    </a>

    <a href="{{ route('matrimony.profile.edit') }}"
       class="underline hover:text-gray-900">
        Edit Profile Details
    </a>
</div>

</div>
            </div>
        </div>
    </div>
</div>

@endsection
