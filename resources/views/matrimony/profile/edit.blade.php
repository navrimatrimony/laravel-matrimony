@extends('layouts.app')

@section('content')

<div class="py-12">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">

                <h1 class="text-2xl font-bold mb-6">
                    Edit Matrimony Profile
                </h1>

                <form method="POST" action="{{ route('matrimony.profile.update') }}">
                    @csrf

                    <label>Full Name</label><br>
                    <input type="text" name="full_name" value="{{ $profile->full_name }}"><br><br>

                    <label>Date of Birth</label><br>
                    <input type="date" name="date_of_birth" value="{{ $profile->date_of_birth }}"><br><br>

                    <label>Education</label><br>
                    <input type="text" name="education" value="{{ $profile->education }}"><br><br>

                    <label>Caste</label><br>
                    <input type="text" name="caste" value="{{ $profile->caste }}"><br><br>

                    <label>Location</label><br>
                    <input type="text" name="location" value="{{ $profile->location }}"><br><br>

                    <button type="submit">
                        Update Profile
                    </button>
                </form>

            </div>
        </div>
    </div>
</div>

@endsection
