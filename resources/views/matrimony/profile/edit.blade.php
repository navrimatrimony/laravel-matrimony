@extends('layouts.app')

@section('content')

<div class="py-12">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">

                <h1 class="text-2xl font-bold mb-6">
                    Edit Matrimony Profile
                </h1>

                @if ($errors->any())
                    <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 text-sm">{{ $errors->first() }}</div>
                @endif

                <form method="POST"
      action="{{ route('matrimony.profile.update') }}"
      enctype="multipart/form-data">

                    @csrf

                    {{-- Day-18: Only show enabled and visible fields --}}
                    @php
                        $visibleFields = $visibleFields ?? [];
                        $enabledFields = $enabledFields ?? [];
                        $isVisible = fn($fieldKey) => in_array($fieldKey, $visibleFields, true);
                        $isEnabled = fn($fieldKey) => in_array($fieldKey, $enabledFields, true);
                    @endphp

                    <label>Full Name</label><br>
                    <input type="text" name="full_name" value="{{ $matrimonyProfile->full_name }}"><br><br>

                    @if ($isEnabled('date_of_birth') && $isVisible('date_of_birth'))
                    <label>Date of Birth</label><br>
                    <input type="date" name="date_of_birth" value="{{ $matrimonyProfile->date_of_birth }}"><br><br>
                    @endif

                    @if ($isEnabled('marital_status') && $isVisible('marital_status'))
                    <label>Marital Status</label><br>
                    <select name="marital_status" required>
                        <option value="">— Select —</option>
                        <option value="single" {{ old('marital_status', $matrimonyProfile->marital_status) === 'single' ? 'selected' : '' }}>Single</option>
                        <option value="divorced" {{ old('marital_status', $matrimonyProfile->marital_status) === 'divorced' ? 'selected' : '' }}>Divorced</option>
                        <option value="widowed" {{ old('marital_status', $matrimonyProfile->marital_status) === 'widowed' ? 'selected' : '' }}>Widowed</option>
                    </select><br><br>
                    @endif

                    @if ($isEnabled('education') && $isVisible('education'))
                    <label>Education</label><br>
                    <input type="text" name="education" value="{{ $matrimonyProfile->education }}"><br><br>
                    @endif

                    @if ($isEnabled('caste') && $isVisible('caste'))
                    <label>Caste</label><br>
                    <input type="text" name="caste" value="{{ $matrimonyProfile->caste }}"><br><br>
                    @endif

                    <label>Height (cm)</label><br>
                    <input type="number" name="height_cm" value="{{ old('height_cm', $matrimonyProfile->height_cm ?? '') }}" placeholder="170"><br>
                    @error('height_cm') <div class="text-danger">{{ $message }}</div> @enderror
                    <br>

                    @if ($isEnabled('location') && $isVisible('location'))
                    <label>Location</label><br>
                    <input type="text" name="location" value="{{ $matrimonyProfile->location }}"><br><br>
                    @endif

                    @if ($isEnabled('profile_photo') && $isVisible('profile_photo'))
                    <label>Profile Photo</label><br>
                    
                    {{-- Existing Profile Photo Preview --}}
                    @if ($matrimonyProfile->profile_photo)
                    <div style="margin-bottom:10px;">
                        <img
                            src="{{ asset('uploads/matrimony_photos/'.$matrimonyProfile->profile_photo) }}"
                            alt="Profile Photo"
                            style="width:120px; height:120px; object-fit:cover; border-radius:50%; border:1px solid #ccc;"
                        >
                    </div>
                    @endif

                    <input type="file" name="profile_photo"><br><br>
                    @endif

<button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-sm text-white tracking-wide hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition disabled:opacity-50 disabled:cursor-not-allowed mt-4">
                        Update Profile
                    </button>
                </form>

            </div>
        </div>
    </div>
</div>

@endsection
