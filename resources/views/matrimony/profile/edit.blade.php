@extends('layouts.app')

@section('content')

<div class="py-12">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">

                <h1 class="text-2xl font-bold mb-6">
                    Edit Matrimony Profile
                </h1>

                <form method="POST"
      action="{{ route('matrimony.profile.update') }}"
      enctype="multipart/form-data">

                    @csrf

                    <label>Full Name</label><br>
                    <input type="text" name="full_name" value="{{ $matrimonyProfile->full_name }}"><br><br>

                    <label>Date of Birth</label><br>
                    <input type="date" name="date_of_birth" value="{{ $matrimonyProfile->date_of_birth }}"><br><br>

                    <label>Marital Status</label><br>
                    <select name="marital_status" required>
                        <option value="">— Select —</option>
                        <option value="single" {{ old('marital_status', $matrimonyProfile->marital_status) === 'single' ? 'selected' : '' }}>Single</option>
                        <option value="divorced" {{ old('marital_status', $matrimonyProfile->marital_status) === 'divorced' ? 'selected' : '' }}>Divorced</option>
                        <option value="widowed" {{ old('marital_status', $matrimonyProfile->marital_status) === 'widowed' ? 'selected' : '' }}>Widowed</option>
                    </select><br><br>

                    <label>Education</label><br>
                    <input type="text" name="education" value="{{ $matrimonyProfile->education }}"><br><br>

                    <label>Caste</label><br>
                    <input type="text" name="caste" value="{{ $matrimonyProfile->caste }}"><br><br>

                    <label>Location</label><br>
                    <input type="text" name="location" value="{{ $matrimonyProfile->location }}"><br><br>
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

<button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-sm text-white tracking-wide hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition disabled:opacity-50 disabled:cursor-not-allowed mt-4">
                        Update Profile
                    </button>
                </form>

            </div>
        </div>
    </div>
</div>

@endsection
